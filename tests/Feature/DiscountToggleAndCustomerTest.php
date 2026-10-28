<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DiscountToggleAndCustomerTest extends TestCase
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

    private function variant()
    {
        $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);

        return $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 20]);
    }

    private function cash(float $amount): array
    {
        return [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => $amount]];
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);
    }

    public function test_discounts_work_by_default_and_are_refused_when_switched_off(): void
    {
        $this->actAs('manager');
        $v = $this->variant();
        $line = [['variant_id' => $v->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]];

        $this->assertEquals(180, (float) SaleService::complete($line, null, $this->cash(200))->total);

        $this->actAs('manager', ['discounts_enabled' => false]);
        $v2 = $this->variant();
        $line2 = [['variant_id' => $v2->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]];

        try {
            SaleService::complete($line2, null, $this->cash(200));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }
        $this->assertEquals(0, Sale::count());

        // Plain sales still work
        SaleService::complete([['variant_id' => $v2->id, 'quantity' => 1]], null, $this->cash(200));
        $this->assertEquals(1, Sale::count());
    }

    public function test_the_switch_also_covers_cart_discounts_quotations_and_invoices(): void
    {
        $this->actAs('owner', ['discounts_enabled' => false]);
        $v = $this->variant();
        $c = $this->customer();
        $lines = [['variant_id' => $v->id, 'quantity' => 1]];

        foreach ([
            fn () => SaleService::complete($lines, ['type' => 'fixed', 'value' => 10], $this->cash(200)),
            fn () => QuotationService::create($c->id, null, null, $lines, ['type' => 'fixed', 'value' => 10]),
            fn () => InvoiceService::issue($c->id, null, null, $lines, ['type' => 'fixed', 'value' => 10]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cart', $e->errors());
            }
        }
    }

    public function test_the_discount_boxes_disappear_from_the_till_when_off(): void
    {
        $this->actAs('manager');
        Volt::test('sell.till')->assertSee('Cart discount');

        $this->actAs('manager', ['discounts_enabled' => false]);
        Volt::test('sell.till')->assertDontSee('Cart discount');
    }

    public function test_only_the_owner_can_change_the_discount_setting(): void
    {
        $owner = $this->actAs('owner');
        Volt::test('settings.tax')->set('discounts_enabled', false)->call('save')->assertHasNoErrors();
        $this->assertFalse((bool) $owner->business->fresh()->discounts_enabled);

        $this->actAs('manager');
        Volt::test('settings.tax')->set('discounts_enabled', false)->call('save')->assertForbidden();
    }

    public function test_a_sale_defaults_to_walk_in_and_can_carry_a_customer(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();

        $walkIn = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(200));
        $this->assertNull($walkIn->customer_id);

        $c = $this->customer();
        $named = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(200), $c->id);
        $this->assertEquals($c->id, $named->fresh()->customer_id);
    }

    public function test_another_shops_or_a_disabled_customer_is_refused(): void
    {
        $this->actAs('cashier');
        $theirs = $this->customer();

        $this->actAs('cashier');
        $v = $this->variant();

        try {
            SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(200), $theirs->id);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }

        $mine = $this->customer();
        $mine->disable();
        $this->expectException(ValidationException::class);
        SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(200), $mine->id);
    }

    public function test_the_till_picks_a_customer_completes_the_sale_and_the_receipt_shows_them(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $c = $this->customer();

        Volt::test('sell.till')
            ->set('customerSearch', 'Jan')->call('pickCustomer', $c->id)
            ->assertSet('customerId', $c->id)
            ->call('addVariant', $v->id)
            ->set('payments.0.amount', '200')
            ->call('complete')->assertHasNoErrors();

        $sale = Sale::first();
        $this->assertEquals($c->id, $sale->customer_id);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Jane');
    }

    public function test_a_parked_cart_remembers_its_customer(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $c = $this->customer();

        Volt::test('sell.till')->call('pickCustomer', $c->id)->call('addVariant', $v->id)->call('hold');
        $held = \App\Models\HeldCart::first();
        $this->assertEquals($c->id, $held->customer_id);

        Volt::test('sell.till')->call('resume', $held->id)->assertSet('customerId', $c->id);
    }

    public function test_resuming_strips_discounts_when_they_are_switched_off(): void
    {
        $manager = $this->actAs('manager');
        $v = $this->variant();

        Volt::test('sell.till')->call('addVariant', $v->id)
            ->set('cart.' . $v->id . '.dtype', 'percent')->set('cart.' . $v->id . '.dvalue', '10')
            ->call('hold');

        $manager->business->forceFill(['discounts_enabled' => false])->save();
        $this->actingAs($manager->fresh());

        Volt::test('sell.till')->call('resume', \App\Models\HeldCart::first()->id)
            ->assertSet('cart.' . $v->id . '.dtype', '');
    }
}
