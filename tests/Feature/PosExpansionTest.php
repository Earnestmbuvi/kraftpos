<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BranchService;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PosExpansionTest extends TestCase
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

    private function variant(string $name = 'Sugar 1kg', int $stock = 20, float $price = 200, float $cost = 150, array $product = [])
    {
        $p = Product::create(['name' => $name, 'type' => 'single'] + $product);

        return $p->variants()->create(['name' => 'Default', 'selling_price' => $price, 'cost_price' => $cost, 'stock_quantity' => $stock]);
    }

    private function method(string $name = 'Cash'): int
    {
        return PaymentMethod::where('name', $name)->first()->id;
    }

    private function cash(float $amount): array
    {
        return [['method_id' => $this->method(), 'amount' => $amount]];
    }

    private function sell($v, int $qty = 1, ?float $paid = null, ?int $customer = null): Sale
    {
        return SaleService::complete([['variant_id' => $v->id, 'quantity' => $qty]], null, $this->cash($paid ?? $qty * (float) $v->selling_price), $customer);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);
    }

    // ---- tax per product ----

    public function test_tax_is_charged_only_on_standard_rated_products(): void
    {
        $this->actAs('manager', ['tax_rate' => 16]);
        $a = $this->variant('Sugar', 20, 100);
        $b = $this->variant('Bread', 20, 100, 60, ['tax_class' => 'zero']);
        $lines = [['variant_id' => $a->id, 'quantity' => 1], ['variant_id' => $b->id, 'quantity' => 1]];

        $sale = SaleService::complete($lines, null, $this->cash(216));
        $this->assertEquals(16, (float) $sale->tax_amount);
        $this->assertEquals(216, (float) $sale->total);

        $disc = SaleService::complete($lines, ['type' => 'percent', 'value' => 10], $this->cash(200));
        $this->assertEquals(194.4, (float) $disc->total); // 180 after discount, tax 16% of 90
    }

    public function test_inclusive_tax_skips_zero_rated_lines(): void
    {
        $this->actAs('manager', ['tax_rate' => 16, 'tax_inclusive' => true]);
        $a = $this->variant('Sugar', 20, 116);
        $b = $this->variant('Bread', 20, 100, 60, ['tax_class' => 'exempt']);

        $sale = SaleService::complete(
            [['variant_id' => $a->id, 'quantity' => 1], ['variant_id' => $b->id, 'quantity' => 1]], null, $this->cash(216)
        );

        $this->assertEquals(216, (float) $sale->total);
        $this->assertEquals(16, (float) $sale->tax_amount);
    }

    public function test_the_product_form_and_import_carry_the_tax_class(): void
    {
        $this->actAs('manager');

        Volt::test('products.manage')->call('openCreate')
            ->set('name', 'Bread')->set('selling_price', '60')->set('stock_quantity', '5')->set('tax_class', 'zero')
            ->call('save')->assertHasNoErrors();
        $this->assertEquals('zero', Product::where('name', 'Bread')->first()->tax_class);

        $csv = fn ($body) => UploadedFile::fake()->createWithContent('p.csv', "name,category,unit,sku,barcode,cost_price,selling_price,stock,low_stock_threshold,type,tax\n" . $body);

        Volt::test('products.import')->set('file', $csv("Milk,,pcs,,,0,50,,,,zero-rated\nTea,,pcs,,,0,80,,,,\n"))->call('import')->assertSet('problems', []);
        $this->assertEquals('zero', Product::where('name', 'Milk')->first()->tax_class);
        $this->assertEquals('standard', Product::where('name', 'Tea')->first()->tax_class);

        $c = Volt::test('products.import')->set('file', $csv("Oil,,pcs,,,0,50,,,,maybe\n"))->call('import');
        $this->assertStringContainsString('Tax must be', $c->get('problems')[0]);
    }

    // ---- selling without stock ----

    public function test_the_owner_can_forbid_selling_without_stock(): void
    {
        $this->actAs('owner', ['allow_zero_stock_sales' => false]);
        $v = $this->variant('Sugar', 2);

        try {
            $this->sell($v, 3);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }
        $this->assertEquals(0, Sale::count());
        $this->assertEquals(2, $v->fresh()->stock_quantity);

        $this->sell($v, 2);
        $this->assertEquals(0, $v->fresh()->stock_quantity);
    }

    public function test_the_setting_is_saved_from_the_settings_screen(): void
    {
        $owner = $this->actAs('owner');

        Volt::test('settings.tax')->set('allow_zero_stock_sales', false)->call('save')->assertHasNoErrors();
        $this->assertFalse((bool) $owner->business->fresh()->allow_zero_stock_sales);
    }

    // ---- branches and transfers ----

    public function test_branches_are_capped_by_expected_branches(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);

        BranchService::create('Westlands');

        try {
            BranchService::create('Karen');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertEquals(2, Branch::count());
    }

    public function test_transfers_move_stock_between_branches_without_changing_the_total(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $v = $this->variant('Sugar', 10);
        $west = BranchService::create('Westlands');

        TransferService::create($west->id, [['variant_id' => $v->id, 'quantity' => 4]], 'restock');

        $v = $v->fresh();
        $this->assertEquals(10, $v->stock_quantity);
        $this->assertEquals(6, BranchService::quantity($v, BranchService::mainId()));
        $this->assertEquals(4, BranchService::quantity($v, $west->id));
        $this->assertEquals(1, StockMovement::where('type', 'transfer_out')->count());
        $this->assertEquals(1, StockMovement::withoutGlobalScope(\App\Models\Scopes\BranchViewScope::class)->where('type', 'transfer_in')->count());

        try {
            TransferService::create($west->id, [['variant_id' => $v->id, 'quantity' => 7]], null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }
        $this->assertEquals(6, BranchService::quantity($v->fresh(), BranchService::mainId()));
    }

    public function test_sales_and_voids_use_the_branch_being_worked_in(): void
    {
        $owner = $this->actAs('owner', ['expected_branches' => 2]);
        $v = $this->variant('Sugar', 10);
        $west = BranchService::create('Westlands');
        TransferService::create($west->id, [['variant_id' => $v->id, 'quantity' => 4]], null);

        session(['branch_id' => $west->id]);
        $sale = $this->sell($v, 3);

        $v = $v->fresh();
        $this->assertEquals($west->id, $sale->branch_id);
        $this->assertEquals(7, $v->stock_quantity);
        $this->assertEquals(1, BranchService::quantity($v, $west->id));
        $this->assertEquals(6, BranchService::quantity($v, BranchService::mainId()));

        SaleService::void($sale, 'Mistake');
        $this->assertEquals(4, BranchService::quantity($v->fresh(), $west->id));
    }

    public function test_a_cashier_sells_from_the_branch_they_are_assigned_to(): void
    {
        $owner = $this->actAs('owner', ['expected_branches' => 2]);
        $v = $this->variant('Sugar', 10);
        $west = BranchService::create('Westlands');
        TransferService::create($west->id, [['variant_id' => $v->id, 'quantity' => 5]], null);

        $cashier = $this->makeActiveUser();
        $cashier->business_id = $owner->business_id;
        $cashier->branch_id = $west->id;
        $cashier->save();
        $cashier->assignRole('cashier');
        $this->actingAs($cashier->fresh());

        $this->sell($v, 2);

        $this->assertEquals(3, BranchService::quantity($v->fresh(), $west->id));
        $this->assertEquals(5, BranchService::quantity($v->fresh(), BranchService::mainId()));
    }

    public function test_transfers_need_the_permission(): void
    {
        $this->actAs('cashier');
        $this->get('/stock/transfers')->assertForbidden();
    }

    // ---- returns and exchanges ----

    public function test_a_return_refunds_restocks_and_cannot_exceed_what_was_sold(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar', 20, 200, 150);
        $sale = $this->sell($v, 3); // 600
        $item = $sale->items->first();

        $ret = ReturnService::refund($sale->id, [$item->id => 1], 'Faulty', $this->method(), null);

        $this->assertEquals('RT-0001', $ret->number_label);
        $this->assertEquals(200, (float) $ret->value);
        $this->assertEquals(200, (float) $ret->refund_total);
        $this->assertEquals(18, $v->fresh()->stock_quantity);

        try {
            ReturnService::refund($sale->id, [$item->id => 3], 'Too many', $this->method(), null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }

        ReturnService::refund($sale->id, [$item->id => 2], 'Rest', $this->method(), null);
        $this->assertEquals(20, $v->fresh()->stock_quantity);

        try {
            SaleService::void($sale, 'Too late');
            $this->fail('A sale with returns must not be voided.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_returns_need_a_reason_a_mobile_code_and_the_permission(): void
    {
        $this->actAs('manager');
        $v = $this->variant();
        $sale = $this->sell($v, 1);
        $item = $sale->items->first();

        foreach ([['', $this->method()], ['Faulty', $this->method('M-Pesa')]] as [$reason, $method]) {
            try {
                ReturnService::refund($sale->id, [$item->id => 1], $reason, $method, '');
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertEquals(0, \App\Models\SaleReturn::count());

        $this->actAs('cashier');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        ReturnService::refund($sale->id, [$item->id => 1], 'x', 1, null);
    }

    public function test_a_voided_sale_cannot_be_returned(): void
    {
        $this->actAs('manager');
        $sale = $this->sell($this->variant());
        $item = $sale->items->first();
        SaleService::void($sale, 'Mistake');

        $this->expectException(ValidationException::class);
        ReturnService::refund($sale->id, [$item->id => 1], 'x', $this->method(), null);
    }

    public function test_returns_reduce_revenue_cost_and_money_received(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar', 20, 200, 150);
        $sale = $this->sell($v, 3); // 600 revenue, 450 cost
        ReturnService::refund($sale->id, [$sale->items->first()->id => 1], 'Faulty', $this->method(), null);

        [$f, $t] = ReportService::range(today()->toDateString(), today()->toDateString());
        $p = ReportService::profit($f, $t);

        $this->assertEquals(400, round($p['net'], 2));
        $this->assertEquals(300, round($p['cost'], 2));

        $s = ReportService::sales($f, $t);
        $this->assertEquals(200, $s['summary']->returns);
        $this->assertEquals(400, $s['methods']->firstWhere('method_name', 'Cash')->net);
    }

    public function test_an_exchange_for_something_dearer_collects_the_difference(): void
    {
        $this->actAs('manager');
        $sugar = $this->variant('Sugar', 20, 200, 150);
        $rice = $this->variant('Rice', 10, 300, 200);
        $sale = $this->sell($sugar, 2);

        [$ret, $new] = ReturnService::exchange(
            $sale->id, [$sale->items->first()->id => 1], [['variant_id' => $rice->id, 'quantity' => 1]],
            $this->cash(100), 'Wrong item', null, null
        );

        $this->assertEquals('exchange', $ret->type);
        $this->assertEquals(200, (float) $ret->value);
        $this->assertEquals(0, (float) $ret->refund_total);
        $this->assertEquals($new->id, $ret->exchange_sale_id);
        $this->assertEquals(300, (float) $new->total);
        $this->assertEquals(200, (float) $new->credit_applied);
        $this->assertEquals(300, (float) $new->amount_paid);
        $this->assertEquals(19, $sugar->fresh()->stock_quantity);
        $this->assertEquals(9, $rice->fresh()->stock_quantity);
    }

    public function test_an_exchange_for_something_cheaper_pays_the_difference_back(): void
    {
        $this->actAs('manager');
        $sugar = $this->variant('Sugar', 20, 200, 150);
        $rice = $this->variant('Rice', 10, 300, 200);
        $sale = $this->sell($sugar, 2); // 400

        [$ret, $new] = ReturnService::exchange(
            $sale->id, [$sale->items->first()->id => 2], [['variant_id' => $rice->id, 'quantity' => 1]],
            [], 'Prefers rice', $this->method(), null
        );

        $this->assertEquals(400, (float) $ret->value);
        $this->assertEquals(100, (float) $ret->refund_total);
        $this->assertEquals(300, (float) $new->credit_applied);
    }

    public function test_the_return_pages_open_for_managers_only(): void
    {
        $this->actAs('manager');
        foreach (['/returns', '/returns/new', '/exchanges/new'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->actAs('cashier');
        $this->get('/returns')->assertForbidden();
    }

    // ---- history and reports ----

    public function test_sales_history_shows_sales_and_invoices_and_cashiers_see_only_their_own(): void
    {
        $cashier = $this->actAs('cashier');
        $v = $this->variant('Sugar', 50, 200);
        $c = $this->customer();

        $this->sell($v, 1);
        InvoiceService::issue($c->id, null, null, [['variant_id' => $v->id, 'quantity' => 3]], null); // 600

        $other = $this->makeActiveUser();
        $other->business_id = $cashier->business_id;
        $other->save();
        $other->assignRole('cashier');
        $this->actingAs($other->fresh());
        $this->sell($v, 5); // 1,000

        $this->actingAs($cashier->fresh());
        Volt::test('sales.history')->assertSee('INV-0001')->assertSee('600.00')->assertDontSee('1,000.00');

        $manager = $this->makeActiveUser();
        $manager->business_id = $cashier->business_id;
        $manager->save();
        $manager->assignRole('manager');
        $this->actingAs($manager->fresh());
        Volt::test('sales.history')->assertSee('INV-0001')->assertSee('1,000.00')
            ->set('kind', 'invoice')->assertSee('INV-0001')->assertDontSee('1,000.00');
    }

    public function test_sale_items_lists_every_product_line_and_exports(): void
    {
        $this->actAs('manager');
        $a = $this->variant('Sugar 1kg', 20, 200);
        $b = $this->variant('Rice 2kg', 20, 300);
        SaleService::complete(
            [['variant_id' => $a->id, 'quantity' => 2], ['variant_id' => $b->id, 'quantity' => 1]], null, $this->cash(700)
        );

        Volt::test('reports.sale-items')->assertSee('Sugar 1kg')->assertSee('Rice 2kg');

        $csv = $this->get(route('reports.detail-export', ['kind' => 'items']))->streamedContent();
        $this->assertStringContainsString('Sugar 1kg', $csv);
        $this->assertStringContainsString('Rice 2kg', $csv);
    }

    public function test_the_customer_report_totals_what_a_customer_bought(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar 1kg', 50, 200);
        $c = $this->customer();

        $this->sell($v, 3, null, $c->id);
        InvoiceService::issue($c->id, null, null, [['variant_id' => $v->id, 'quantity' => 2]], null);

        Volt::test('reports.customers')->assertSee('Jane')->assertSee('1,000.00')
            ->call('pick', $c->id)->assertSee('Sugar 1kg');

        $csv = $this->get(route('reports.detail-export', ['kind' => 'customer', 'customer' => $c->id]))->streamedContent();
        $this->assertStringContainsString('Sugar 1kg', $csv);

        $this->actAs('cashier');
        $this->get('/reports/customers')->assertForbidden();
    }

    public function test_branches_are_private_to_each_shop(): void
{
    $first = $this->actAs('owner');
    $firstMain = BranchService::mainId();

    $second = $this->actAs('owner');
    $this->assertEquals(1, Branch::count());
    $this->assertNotEquals($firstMain, Branch::first()->id);
    $this->assertEquals($second->business_id, Branch::first()->business_id);
}
public function test_an_out_of_stock_item_is_refused_when_added_to_the_cart(): void
{
    $this->actAs('owner', ['allow_zero_stock_sales' => false]);
    $v = $this->variant('Sugar', 1);

    Volt::test('sell.till')
        ->call('addVariant', $v->id)->assertSet('cart.' . $v->id . '.qty', 1)
        ->call('addVariant', $v->id)->assertDispatched('notify')
        ->assertSet('cart.' . $v->id . '.qty', 1);

    $empty = $this->variant('Rice', 0);
    Volt::test('sell.till')->call('addVariant', $empty->id)->assertDispatched('notify')->assertSet('cart', []);
}

public function test_items_can_still_be_added_when_selling_without_stock_is_allowed(): void
{
    $this->actAs('owner');
    $empty = $this->variant('Rice', 0);

    Volt::test('sell.till')->call('addVariant', $empty->id)->assertSet('cart.' . $empty->id . '.qty', 1);
}
public function test_typing_too_large_a_quantity_in_the_till_is_pulled_back(): void
{
    $this->actAs('owner', ['allow_zero_stock_sales' => false]);
    $v = $this->variant('Sugar', 3);

    Volt::test('sell.till')
        ->call('addVariant', $v->id)
        ->set('cart.' . $v->id . '.qty', '9')
        ->assertSet('cart.' . $v->id . '.qty', 3)
        ->assertDispatched('notify');

    Volt::test('sell.till')
        ->call('addVariant', $v->id)
        ->set('cart.' . $v->id . '.qty', '2')
        ->assertSet('cart.' . $v->id . '.qty', 2);
}

public function test_typing_any_quantity_is_fine_when_selling_without_stock_is_allowed(): void
{
    $this->actAs('owner');
    $v = $this->variant('Sugar', 3);

    Volt::test('sell.till')
        ->call('addVariant', $v->id)
        ->set('cart.' . $v->id . '.qty', '9')
        ->assertSet('cart.' . $v->id . '.qty', 9);
}

public function test_typing_too_large_a_quantity_on_an_invoice_is_pulled_back(): void
{
    $this->actAs('owner', ['allow_zero_stock_sales' => false]);
    $v = $this->variant('Sugar', 3);

    Volt::test('invoices.form')
        ->call('addLine', $v->id)
        ->set('lines.' . $v->id . '.qty', '9')
        ->assertSet('lines.' . $v->id . '.qty', 3)
        ->assertDispatched('notify');
}
public function test_the_till_preview_taxes_only_standard_rated_items(): void
{
    $this->actAs('owner', ['tax_rate' => 16]);
    $a = $this->variant('Sugar', 20, 100);
    $b = $this->variant('Bread', 20, 100, 60, ['tax_class' => 'zero']);

    Volt::test('sell.till')
        ->call('addVariant', $b->id)
        ->assertDontSee('standard-rated items only')
        ->call('addVariant', $a->id)
        ->assertSee('16.00')->assertSee('216.00');
}
}
