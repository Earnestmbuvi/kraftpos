<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HeldCart;
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

class QuotationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role, array $business = [])
    {
        $user = $this->makeActiveUser();
        $user->assignRole($role);
        if ($business) {
            $user->business->forceFill($business)->save();
        }
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    private function variant(float $price = 200)
    {
        $product = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);

        return $product->variants()->create([
            'name' => 'Default', 'selling_price' => $price, 'cost_price' => 150, 'stock_quantity' => 10,
        ]);
    }

    private function customer()
    {
        return Customer::create(['name' => 'Jane', 'phone' => '0712345678', 'address' => 'Nairobi']);
    }

    public function test_a_quotation_is_priced_from_the_database_and_leaves_stock_alone(): void
    {
        $this->actAs('cashier');
        $v = $this->variant(200);

        $q = QuotationService::create(
            $this->customer()->id, null, 'Thanks', [['variant_id' => $v->id, 'quantity' => 3]], null
        );

        $this->assertEquals(600, $q->total);
        $this->assertEquals('Q-0001', $q->number_label);
        $this->assertEquals('open', $q->status);
        $this->assertEquals('Sugar 1kg', $q->items->first()->product_name);
        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_discounts_then_tax_are_applied(): void
    {
        $this->actAs('manager', ['tax_rate' => 16, 'tax_inclusive' => false]);
        $v = $this->variant(200);

        // 2 x 200 = 400, 10% off = 360, tax 16% = 57.60, total 417.60
        $q = QuotationService::create(
            $this->customer()->id, null, null,
            [['variant_id' => $v->id, 'quantity' => 2]], ['type' => 'percent', 'value' => 10]
        );

        $this->assertEquals(360, (float) $q->subtotal - (float) $q->discount_amount);
        $this->assertEquals(57.6, (float) $q->tax_amount);
        $this->assertEquals(417.6, (float) $q->total);
    }

    public function test_a_cashier_cannot_give_a_discount(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();

        $this->expectException(ValidationException::class);
        QuotationService::create(
            $this->customer()->id, null, null,
            [['variant_id' => $v->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]], null
        );
    }

    public function test_another_shops_customer_is_rejected(): void
    {
        $this->actAs('manager');
        $other = $this->customer();

        $this->actAs('manager'); // second shop
        $v = $this->variant();

        try {
            QuotationService::create($other->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }
    }

    public function test_quote_numbers_are_per_business(): void
    {
        $this->actAs('manager');
        $v = $this->variant();
        $q1 = QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);

        $this->actAs('manager');
        $v2 = $this->variant();
        $q2 = QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v2->id, 'quantity' => 1]], null);

        $this->assertEquals(1, $q1->quote_number);
        $this->assertEquals(1, $q2->quote_number);
        $this->assertEquals(1, Quotation::count());
    }

    public function test_converting_parks_the_items_and_marks_the_quotation(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $q = QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 4]], null);

        Volt::test('quotations.list')->call('convert', $q->id)->assertRedirect();

        $this->assertEquals('converted', $q->fresh()->status);
        $this->assertEquals(4, HeldCart::first()->cart[$v->id]['qty']);
        $this->assertEquals(10, $v->fresh()->stock_quantity);

        // It cannot be converted twice
        Volt::test('quotations.list')->call('convert', $q->id)->assertHasErrors('action');
        $this->assertEquals(1, HeldCart::count());
    }

    public function test_accept_and_decline_only_work_on_open_quotations(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $q = QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);

        Volt::test('quotations.list')->call('setStatus', $q->id, 'declined')->assertHasNoErrors();
        $this->assertEquals('declined', $q->fresh()->status);

        Volt::test('quotations.list')->call('setStatus', $q->id, 'accepted')->assertHasErrors('action');
        $this->assertEquals('declined', $q->fresh()->status);
    }

    public function test_cashier_sees_only_their_own_quotations(): void
    {
        $a = $this->actAs('cashier');
        $v = $this->variant();
        QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);

        $b = \App\Models\User::factory()->create(['is_active' => true]);
        $b->business_id = $a->business_id;
        $b->save();
        $b->assignRole('cashier');

        Volt::test('quotations.list')->assertSee('Q-0001');

        $this->actingAs($b->fresh());
        Volt::test('quotations.list')->assertDontSee('Q-0001');
    }

    public function test_quotations_cannot_be_deleted(): void
    {
        $this->actAs('manager');
        $v = $this->variant();
        $q = QuotationService::create($this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);

        $this->expectException(\LogicException::class);
        $q->delete();
    }
}
