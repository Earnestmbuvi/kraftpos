<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use App\Services\QuotationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuotationEditTest extends TestCase
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

    private function variant(string $name, float $price)
    {
        $p = Product::create(['name' => $name, 'type' => 'single']);

        return $p->variants()->create(['name' => 'Default', 'selling_price' => $price, 'stock_quantity' => 10]);
    }

    private function quote($v, int $qty = 1): Quotation
    {
        $c = Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);

        return QuotationService::create($c->id, null, null, [['variant_id' => $v->id, 'quantity' => $qty]], null);
    }

    public function test_more_products_can_be_added_and_the_number_stays(): void
    {
        $this->actAs('cashier');
        $a = $this->variant('Sugar', 200);
        $b = $this->variant('Rice', 300);
        $q = $this->quote($a, 2); // 400

        Volt::test('quotations.form', ['quotationId' => $q->id])
            ->assertSet('lines.' . $a->id . '.qty', 2)
            ->call('addLine', $b->id)->call('save')->assertHasNoErrors();

        $q = $q->fresh()->load('items');
        $this->assertEquals('Q-0001', $q->number_label);
        $this->assertCount(2, $q->items);
        $this->assertEquals(700, (float) $q->total);
        $this->assertEquals(1, Quotation::count());
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_the_edit_page_opens_and_items_can_be_removed_and_quantities_changed(): void
    {
        $this->actAs('cashier');
        $a = $this->variant('Sugar', 200);
        $b = $this->variant('Rice', 300);
        $q = $this->quote($a, 1);

        $this->get(route('quotations.edit', $q))->assertOk()->assertSee('Edit quotation Q-0001');

        Volt::test('quotations.form', ['quotationId' => $q->id])
            ->call('addLine', $b->id)->call('removeLine', $a->id)
            ->set('lines.' . $b->id . '.qty', 3)->call('save')->assertHasNoErrors();

        $this->assertEquals(900, (float) $q->fresh()->total);
        $this->assertEquals(['Rice'], $q->fresh()->items->pluck('product_name')->all());
    }

    public function test_an_accepted_quotation_goes_back_to_open_when_edited(): void
    {
        $this->actAs('cashier');
        $a = $this->variant('Sugar', 200);
        $q = $this->quote($a);
        $q->status = 'accepted';
        $q->save();

        Volt::test('quotations.form', ['quotationId' => $q->id])->call('addLine', $a->id)->call('save');

        $this->assertEquals('open', $q->fresh()->status);
    }

    public function test_declined_and_converted_quotations_are_locked(): void
    {
        $this->actAs('cashier');
        $a = $this->variant('Sugar', 200);

        foreach (['declined', 'converted'] as $status) {
            $q = $this->quote($a);
            $q->status = $status;
            $q->save();

            $this->get(route('quotations.edit', $q))->assertForbidden();

            try {
                QuotationService::update($q, $q->customer_id, null, null, [['variant_id' => $a->id, 'quantity' => 1]], null);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quotation', $e->errors());
            }
        }
    }

    public function test_cashiers_edit_only_their_own_but_managers_can_edit_any(): void
    {
        $cashier = $this->actAs('cashier');
        $a = $this->variant('Sugar', 200);
        $q = $this->quote($a);

        $other = $this->makeActiveUser();
        $other->business_id = $cashier->business_id;
        $other->save();
        $other->assignRole('cashier');
        $this->actingAs($other->fresh());
        $this->get(route('quotations.edit', $q))->assertForbidden();

        $manager = $this->makeActiveUser();
        $manager->business_id = $cashier->business_id;
        $manager->save();
        $manager->assignRole('manager');
        $this->actingAs($manager->fresh());
        $this->get(route('quotations.edit', $q))->assertOk();
    }

    public function test_another_shop_cannot_edit_it(): void
    {
        $this->actAs('manager');
        $q = $this->quote($this->variant('Sugar', 200));

        $this->actAs('manager');
        $this->get(route('quotations.edit', $q))->assertNotFound();
    }

    public function test_discounts_are_dropped_with_a_warning_when_they_are_switched_off(): void
    {
        $user = $this->actAs('manager');
        $a = $this->variant('Sugar', 200);
        $c = Customer::create(['name' => 'Jane', 'phone' => '0711000111', 'address' => 'Nairobi']);
        $q = QuotationService::create($c->id, null, null,
            [['variant_id' => $a->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]], null);
        $this->assertEquals(180, (float) $q->total);

        $user->business->forceFill(['discounts_enabled' => false])->save();
        $this->actingAs($user->fresh());

        Volt::test('quotations.form', ['quotationId' => $q->id])
            ->assertSet('discountsDropped', true)->assertSet('lines.' . $a->id . '.dtype', '')
            ->call('save')->assertHasNoErrors();

        $this->assertEquals(200, (float) $q->fresh()->total);
    }
}
