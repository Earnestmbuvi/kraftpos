<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BatchService;
use App\Services\BusinessSetup;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CreditAndReportsTest extends TestCase
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

    private function variant(string $name = 'Sugar 1kg', int $stock = 20, float $price = 200, float $cost = 150, int $low = 0)
    {
        $p = Product::create(['name' => $name, 'type' => 'single']);

        return $p->variants()->create([
            'name' => 'Default', 'selling_price' => $price, 'cost_price' => $cost,
            'stock_quantity' => $stock, 'low_stock_threshold' => $low,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);
    }

    private function method(string $name): int
    {
        return PaymentMethod::where('name', $name)->first()->id;
    }

    private function invoice($variant, int $qty = 2, ?Customer $c = null): Invoice
    {
        return InvoiceService::issue(($c ?? $this->customer())->id, null, null,
            [['variant_id' => $variant->id, 'quantity' => $qty]], null);
    }

    private function today(): array
    {
        return ReportService::range(today()->toDateString(), today()->toDateString());
    }

    // ---- required codes ----

    public function test_mobile_money_needs_a_code_of_4_characters_or_the_full_code(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $line = [['variant_id' => $v->id, 'quantity' => 1]];
        $mpesa = $this->method('M-Pesa');

        foreach (['', 'ABC', 'AB-12'] as $bad) {
            try {
                SaleService::complete($line, null, [['method_id' => $mpesa, 'amount' => 200, 'reference' => $bad]]);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('payments', $e->errors());
            }
        }

        $short = SaleService::complete($line, null, [['method_id' => $mpesa, 'amount' => 200, 'reference' => 'a1b2']]);
        $this->assertEquals('A1B2', $short->payments->first()->reference);

        $full = SaleService::complete($line, null, [['method_id' => $mpesa, 'amount' => 200, 'reference' => 'QGH7ABC123']]);
        $this->assertEquals('QGH7ABC123', $full->payments->first()->reference);

        // Cash needs no code
        SaleService::complete($line, null, [['method_id' => $this->method('Cash'), 'amount' => 200]]);
        $this->assertEquals(17, $v->fresh()->stock_quantity);
    }

    public function test_batch_numbers_are_required_when_receiving(): void
    {
        $this->actAs('manager');
        $p = Product::create(['name' => 'Milk', 'type' => 'single', 'tracks_batches' => true]);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'stock_quantity' => 0]);

        try {
            BatchService::receive($v, 5, '', today()->addDays(10)->toDateString());
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batchNumber', $e->errors());
        }

        $this->assertEquals(0, $v->fresh()->stock_quantity);
    }

    // ---- credit sales and receipts ----

    public function test_invoice_payments_need_a_code_for_mobile_money_and_get_numbered_receipts(): void
    {
        $this->actAs('cashier');
        $inv = $this->invoice($this->variant(), 2); // 400

        try {
            InvoiceService::recordPayment($inv, $this->method('M-Pesa'), 100, '');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }

        $first = InvoiceService::recordPayment($inv, $this->method('M-Pesa'), 100, 'qw12');
        $second = InvoiceService::recordPayment($inv, $this->method('Cash'), 50, null);

        $this->assertEquals('QW12', $first->reference);
        $this->assertEquals('RP-0001', $first->receipt_label);
        $this->assertEquals('RP-0002', $second->receipt_label);

        $this->get(route('invoices.payment-receipt', [$inv, $first->id]))
            ->assertOk()->assertSee('RP-0001')->assertSee('PAYMENT RECEIPT');
    }

    public function test_a_payment_receipt_is_private_to_its_shop(): void
    {
        $this->actAs('cashier');
        $inv = $this->invoice($this->variant());
        $p = InvoiceService::recordPayment($inv, $this->method('Cash'), 10, null);

        $this->actAs('cashier');
        $this->get(route('invoices.payment-receipt', [$inv->id, $p->id]))->assertNotFound();
    }

    public function test_a_deposit_can_be_taken_when_the_invoice_is_issued_and_a_bad_one_issues_nothing(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $c = $this->customer();

        Volt::test('invoices.form')
            ->set('customerId', $c->id)->call('addLine', $v->id)
            ->set('payNow', '100')->set('payMethodId', $this->method('Cash'))
            ->call('save')->assertHasNoErrors();

        $inv = Invoice::first();
        $this->assertEquals(100, (float) $inv->amount_paid);
        $this->assertEquals('partly_paid', $inv->payment_status);
        $this->assertCount(1, $inv->payments);

        Volt::test('invoices.form')
            ->set('customerId', $c->id)->call('addLine', $v->id)
            ->set('payNow', '99999')->set('payMethodId', $this->method('Cash'))
            ->call('save')->assertHasErrors('amount');

        $this->assertEquals(1, Invoice::count()); // the failed one was rolled back
        $this->assertEquals(19, $v->fresh()->stock_quantity);
    }

    public function test_a_credit_sale_with_no_payment_stays_unpaid(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();

        Volt::test('invoices.form')
            ->set('customerId', $this->customer()->id)->call('addLine', $v->id)
            ->call('save')->assertHasNoErrors();

        $this->assertEquals('unpaid', Invoice::first()->payment_status);
    }

    public function test_the_till_hands_its_cart_to_a_credit_invoice(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();
        $c = $this->customer();

        Volt::test('sell.till')
            ->call('pickCustomer', $c->id)->call('addVariant', $v->id)
            ->call('creditSale')->assertRedirect();

        $this->assertTrue(session()->has('credit_cart'));

        $this->withSession(['credit_cart' => ['customer' => $c->id, 'lines' => [$v->id => ['qty' => 3]]]])
            ->get(route('invoices.create', ['from' => 'till']))
            ->assertOk()->assertSee('Cart from the till')->assertSee('Sugar 1kg');
    }

    public function test_customers_show_what_they_owe(): void
    {
        $this->actAs('manager');
        $this->invoice($this->variant(), 2); // 400 unpaid

        Volt::test('customers.manage')->assertSee('400.00');
    }

    // ---- invoices count as sales ----

    public function test_invoices_are_included_in_sales_and_money_received(): void
    {
        $this->actAs('manager');
        $v = $this->variant();

        SaleService::complete([['variant_id' => $v->id, 'quantity' => 3]], null,
            [['method_id' => $this->method('Cash'), 'amount' => 600]]);

        $inv = $this->invoice($v, 2); // 400 on credit
        InvoiceService::recordPayment($inv, $this->method('Cash'), 100, null);

        [$f, $t] = $this->today();
        $d = ReportService::sales($f, $t);

        $this->assertEquals(2, $d['summary']->n);
        $this->assertEquals(1000, $d['summary']->total);
        $this->assertEquals(400, $d['summary']->invoice_total);
        $this->assertEquals(300, $d['summary']->credit_open);
        $this->assertEquals(700, $d['methods']->firstWhere('method_name', 'Cash')->net); // 600 till + 100 received

        Volt::test('reports.view')->assertSee('1,000.00');
    }

    public function test_voided_invoices_are_left_out(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice($this->variant(), 2);
        InvoiceService::void($inv, 'Wrong customer');

        [$f, $t] = $this->today();
        $this->assertEquals(0, ReportService::sales($f, $t)['summary']->total);
    }

    public function test_top_products_and_profit_merge_till_and_invoice_lines(): void
    {
        $this->actAs('manager');
        $v = $this->variant(); // price 200, cost 150

        SaleService::complete([['variant_id' => $v->id, 'quantity' => 3]], null,
            [['method_id' => $this->method('Cash'), 'amount' => 600]]);
        $this->invoice($v, 2);

        [$f, $t] = $this->today();

        $top = ReportService::products($f, $t)['top'];
        $this->assertCount(1, $top);
        $this->assertEquals(5, $top->first()->qty);
        $this->assertEquals(1000, $top->first()->revenue);

        $p = ReportService::profit($f, $t);
        $this->assertEquals(1000, round($p['net'], 2));
        $this->assertEquals(750, round($p['cost'], 2));
        $this->assertEquals(250, round($p['profit'], 2));
    }

    // ---- new reports ----

    public function test_the_income_statement_adds_up(): void
    {
        $this->actAs('manager');
        $v = $this->variant();

        SaleService::complete([['variant_id' => $v->id, 'quantity' => 3]], null,
            [['method_id' => $this->method('Cash'), 'amount' => 600]]);
        $this->invoice($v, 2);
        ExpenseService::record(ExpenseCategory::where('name', 'Rent')->first()->id, 50, today()->toDateString(), null, null, 'Rent');

        [$f, $t] = $this->today();
        $s = ReportService::income($f, $t);

        $this->assertEquals(600, round($s['tillRevenue'], 2));
        $this->assertEquals(400, round($s['invoiceRevenue'], 2));
        $this->assertEquals(1000, round($s['revenue'], 2));
        $this->assertEquals(750, round($s['cost'], 2));
        $this->assertEquals(250, round($s['gross'], 2));
        $this->assertEquals(50, round($s['expenses'], 2));
        $this->assertEquals(200, round($s['net'], 2));

        $this->get(route('reports.income'))->assertOk()->assertSee('Net profit');

        $csv = $this->get(route('reports.export', ['report' => 'income']))->streamedContent();
        $this->assertStringContainsString('Net profit', $csv);
    }

    public function test_new_report_pages_open_for_managers_and_are_closed_to_cashiers(): void
    {
        $this->actAs('manager');
        foreach (['stock-list', 'additions', 'price-list', 'expenses', 'income'] as $kind) {
            $this->get(route("reports.{$kind}"))->assertOk();
        }

        $this->actAs('cashier');
        foreach (['stock-list', 'additions', 'price-list', 'expenses', 'income'] as $kind) {
            $this->get(route("reports.{$kind}"))->assertForbidden();
        }
    }

    public function test_the_income_statement_needs_cost_price_access(): void
    {
        $user = $this->actAs('cashier');
        $user->givePermissionTo('view-reports');

        $this->get(route('reports.price-list'))->assertOk();
        $this->get(route('reports.income'))->assertForbidden();
        $this->get(route('reports.export', ['report' => 'income']))->assertForbidden();
    }

    public function test_the_stock_list_filters_totals_and_exports(): void
    {
        $this->actAs('manager');
        $this->variant('Sugar 1kg', 10, 100, 60, 0);
        $this->variant('Rice 2kg', 2, 100, 60, 5);

        $t = ReportService::stockTotals([]);
        $this->assertEquals(12, $t->units);
        $this->assertEquals(720, $t->cost_value);
        $this->assertEquals(1200, $t->retail_value);

        Volt::test('reports.extra', ['kind' => 'stock-list'])
            ->assertSee('Sugar 1kg')->assertSee('Rice 2kg')
            ->set('status', 'low')->assertSee('Rice 2kg')->assertDontSee('Sugar 1kg');

        $csv = $this->get(route('reports.export', ['report' => 'stocklist', 'status' => 'low']))->streamedContent();
        $this->assertStringContainsString('Rice 2kg', $csv);
        $this->assertStringNotContainsString('Sugar 1kg', $csv);
        $this->assertStringContainsString('Cost price', $csv);
    }

    public function test_the_stock_list_hides_cost_from_people_without_access(): void
    {
        $user = $this->actAs('cashier');
        $user->givePermissionTo('view-reports');
        $this->variant('Sugar 1kg', 10, 100, 60);

        $csv = $this->get(route('reports.export', ['report' => 'stocklist']))->streamedContent();
        $this->assertStringNotContainsString('Cost price', $csv);
        $this->assertStringContainsString('Sugar 1kg', $csv);
    }

    public function test_stock_additions_list_only_stock_that_came_in(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar 1kg', 10, 100, 60);

        StockService::record($v, 5, 'adjustment', 'Purchase received');
        StockService::record($v, -2, 'adjustment', 'Damaged');
        StockService::record($v, -1, 'sale', 'Sale 0001');

        [$f, $t] = $this->today();
        $from = $f->toDateString();
        $to = $t->toDateString();

        $this->assertEquals(1, ReportService::additions($from, $to)->count());

        $totals = ReportService::additionTotals($from, $to, '', true);
        $this->assertEquals(5, $totals->units);
        $this->assertEquals(300, $totals->value);

        $csv = $this->get(route('reports.export', ['report' => 'additions']))->streamedContent();
        $this->assertStringContainsString('Purchase received', $csv);
        $this->assertStringNotContainsString('Damaged', $csv);
    }

    public function test_the_price_list_never_shows_cost(): void
    {
        $this->actAs('manager');
        $this->variant('Sugar 1kg', 10, 200, 150);

        $this->get(route('reports.price-list'))->assertOk()
            ->assertSee('Sugar 1kg')->assertSee('200.00')->assertDontSee('150.00');

        $csv = $this->get(route('reports.export', ['report' => 'pricelist']))->streamedContent();
        $this->assertStringContainsString('Sugar 1kg', $csv);
        $this->assertStringNotContainsString('150', $csv);
    }

    public function test_the_expense_report_filters_by_category_and_exports(): void
    {
        $this->actAs('manager');
        $rent = ExpenseCategory::where('name', 'Rent')->first()->id;
        $transport = ExpenseCategory::where('name', 'Transport')->first()->id;

        ExpenseService::record($rent, 1000, today()->toDateString(), null, null, 'October rent');
        ExpenseService::record($transport, 200, today()->toDateString(), null, null, 'Bus fare');

        Volt::test('reports.extra', ['kind' => 'expenses'])
            ->assertSee('1,200.00')->assertSee('Bus fare')
            ->set('expenseCategoryId', $rent)
            ->assertSee('1,000.00')->assertDontSee('Bus fare');

        $csv = $this->get(route('reports.export', ['report' => 'expensereport', 'expcat' => $rent]))->streamedContent();
        $this->assertStringContainsString('October rent', $csv);
        $this->assertStringNotContainsString('Bus fare', $csv);
    }

    public function test_new_reports_only_show_the_shops_own_data(): void
    {
        $this->actAs('manager');
        $this->variant('Secret Item', 10);

        $this->actAs('manager');
        $csv = $this->get(route('reports.export', ['report' => 'stocklist']))->streamedContent();
        $this->assertStringNotContainsString('Secret Item', $csv);
    }
}
