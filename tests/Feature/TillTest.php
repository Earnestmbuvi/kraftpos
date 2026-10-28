<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role)
    {
        $user = $this->makeActiveUser();
        $user->assignRole($role);
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    private function variant(int $stock = 10, float $price = 200)
    {
        $product = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);

        return $product->variants()->create([
            'name' => 'Default', 'selling_price' => $price, 'cost_price' => 150, 'stock_quantity' => $stock,
        ]);
    }

    public function test_cashier_can_open_the_till(): void
    {
        $user = $this->actAs('cashier');

        $this->get('/sell')->assertOk();
    }

    public function test_cashier_completes_a_sale(): void
    {
        $this->actAs('cashier');
        $v = $this->variant(10, 200);

        Volt::test('sell.till')
            ->call('addVariant', $v->id)
            ->call('addVariant', $v->id)
            ->set('payments.0.amount', '500')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertEquals(1, Sale::count());
        $this->assertEquals(8, $v->fresh()->stock_quantity);
        $this->assertEquals(400, Sale::first()->total);
        $this->assertEquals(100, Sale::first()->change_given);
    }

    public function test_scanning_a_barcode_adds_the_item(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $v->update(['barcode' => '6001234567890']);

        Volt::test('sell.till')
            ->call('addFromSearch', '6001234567890')
            ->assertSet('cart.' . $v->id . '.qty', 1);
    }

    public function test_cashier_cannot_give_a_discount(): void
    {
        $this->actAs('cashier');
        $v = $this->variant(10, 200);

        Volt::test('sell.till')
            ->call('addVariant', $v->id)
            ->set('cart.' . $v->id . '.dtype', 'percent')
            ->set('cart.' . $v->id . '.dvalue', '10')
            ->set('payments.0.amount', '500')
            ->call('complete')
            ->assertHasErrors('cart');

        $this->assertEquals(0, Sale::count());
    }

    public function test_manager_can_give_a_discount(): void
    {
        $this->actAs('manager');
        $v = $this->variant(10, 200);

        Volt::test('sell.till')
            ->call('addVariant', $v->id)
            ->call('addVariant', $v->id)
            ->set('cart.' . $v->id . '.dtype', 'percent')
            ->set('cart.' . $v->id . '.dvalue', '10')
            ->set('payments.0.amount', '400')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertEquals(360, Sale::first()->total);
    }

    public function test_cashier_sees_only_their_own_receipts(): void
    {
        $a = $this->actAs('cashier');
        $v = $this->variant();
        $cash = PaymentMethod::where('name', 'Cash')->first();
        $sale = SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 1]], null,
            [['method_id' => $cash->id, 'amount' => 200]]
        );

        $this->get(route('sales.receipt', $sale))->assertOk();

        $b = User::factory()->create(['is_active' => true]);
        $b->business_id = $a->business_id;
        $b->save();
        $b->assignRole('cashier');

        $this->actingAs($b->fresh())->get(route('sales.receipt', $sale))->assertForbidden();
    }
    public function test_category_tab_filters_the_till(): void
{
    $this->actAs('cashier');
    $drinks = \App\Models\Category::create(['name' => 'Drinks']);
    $food = \App\Models\Category::create(['name' => 'Food']);

    $a = Product::create(['name' => 'Soda', 'type' => 'single', 'category_id' => $drinks->id]);
    $a->variants()->create(['name' => 'Default', 'selling_price' => 70, 'stock_quantity' => 5]);
    $b = Product::create(['name' => 'Bread', 'type' => 'single', 'category_id' => $food->id]);
    $b->variants()->create(['name' => 'Default', 'selling_price' => 60, 'stock_quantity' => 5]);

    Volt::test('sell.till')
        ->call('setCategory', $drinks->id)
        ->assertSee('Soda')
        ->assertDontSee('Bread');
}
public function test_dropdown_can_pick_a_subcategory_directly(): void
{
    $this->actAs('cashier');
    $drinks = \App\Models\Category::create(['name' => 'Drinks']);
    $soda = \App\Models\Category::create(['name' => 'Soda', 'parent_id' => $drinks->id]);

    $a = Product::create(['name' => 'Cola', 'type' => 'single', 'category_id' => $soda->id]);
    $a->variants()->create(['name' => 'Default', 'selling_price' => 70, 'stock_quantity' => 5]);
    $b = Product::create(['name' => 'Juice', 'type' => 'single', 'category_id' => $drinks->id]);
    $b->variants()->create(['name' => 'Default', 'selling_price' => 90, 'stock_quantity' => 5]);

    Volt::test('sell.till')
        ->call('pickFromList', $soda->id)
        ->assertSet('categoryId', $drinks->id)
        ->assertSet('subId', $soda->id)
        ->assertSee('Cola')
        ->assertDontSee('Juice');
}

    public function test_a_cart_can_be_parked_and_resumed_without_touching_stock(): void
{
    $this->actAs('cashier');
    $v = $this->variant(10, 200);

    Volt::test('sell.till')
        ->call('addVariant', $v->id)
        ->call('addVariant', $v->id)
        ->set('holdLabel', 'Blue shirt lady')
        ->call('hold')
        ->assertSet('cart', []);

    $this->assertEquals(10, $v->fresh()->stock_quantity);
    $this->assertEquals(1, \App\Models\HeldCart::count());

    $held = \App\Models\HeldCart::first();

    Volt::test('sell.till')
        ->call('resume', $held->id)
        ->assertSet('cart.' . $v->id . '.qty', 2);

    $this->assertEquals(0, \App\Models\HeldCart::count());
}

public function test_parked_carts_are_private_to_the_shop(): void
{
    $this->actAs('cashier');
    $v = $this->variant();
    Volt::test('sell.till')->call('addVariant', $v->id)->call('hold');
    $held = \App\Models\HeldCart::first();

    $this->actAs('cashier'); // another business
    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    Volt::test('sell.till')->call('resume', $held->id);
}

public function test_resume_drops_items_that_were_disabled_meanwhile(): void
{
    $this->actAs('cashier');
    $v = $this->variant();
    Volt::test('sell.till')->call('addVariant', $v->id)->call('hold');
    $v->update(['is_active' => false]);

    Volt::test('sell.till')
        ->call('resume', \App\Models\HeldCart::first()->id)
        ->assertSet('cart', [])
        ->assertHasErrors('cart');
}

}
