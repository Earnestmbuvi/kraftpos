<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\ReportService;
use App\Services\WriteOffService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WriteOffTest extends TestCase
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

    private function invoice(int $qty = 2): Invoice
    {
        $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 20]);
        $c = Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);

        return InvoiceService::issue($c->id, null, null, [['variant_id' => $v->id, 'quantity' => $qty]], null); // 400
    }

    public function test_writing_off_closes_the_balance_and_records_a_bad_debt(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();
        InvoiceService::recordPayment($inv, PaymentMethod::where('name', 'Cash')->first()->id, 100, null);

        WriteOffService::run($inv, 300, 'Customer cannot be traced');

        $inv = $inv->fresh();
        $this->assertEquals('written_off', $inv->payment_status);
        $this->assertEquals(0, $inv->balance);
        $this->assertEquals(100, $inv->cash_paid);
        $this->assertEquals(300, (float) $inv->written_off);

        $e = Expense::first();
        $this->assertEquals('Bad debts', $e->category_name);
        $this->assertEquals(300, (float) $e->amount);
        $this->assertStringContainsString('INV-0001', $e->description);
    }

    public function test_written_off_money_is_not_money_received_and_lowers_net_profit(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();
        InvoiceService::recordPayment($inv, PaymentMethod::where('name', 'Cash')->first()->id, 100, null);
        WriteOffService::run($inv, 300, 'Lost');

        [$f, $t] = ReportService::range(today()->toDateString(), today()->toDateString());

        $s = ReportService::sales($f, $t);
        $this->assertEquals(100, $s['methods']->firstWhere('method_name', 'Cash')->net);
        $this->assertNull($s['methods']->firstWhere('method_name', 'Written off'));
        $this->assertEquals(0, $s['summary']->credit_open);

        $i = ReportService::income($f, $t);
        $this->assertEquals(400, round($i['revenue'], 2));   // still earned
        $this->assertEquals(100, round($i['gross'], 2));
        $this->assertEquals(300, round($i['expenses'], 2));  // the bad debt
        $this->assertEquals(-200, round($i['net'], 2));
    }

    public function test_the_amount_reason_and_state_are_checked(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();

        foreach ([[0, 'x'], [500, 'x'], [100, '  ']] as [$amount, $reason]) {
            try {
                WriteOffService::run($inv, $amount, $reason);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertEquals(0, Expense::count());

        WriteOffService::run($inv, 400, 'Lost');

        try {
            WriteOffService::run($inv, 1, 'Again');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('writeOffAmount', $e->errors());
        }
    }

    public function test_cashiers_cannot_write_off(): void
    {
        $this->actAs('cashier');
        $inv = $this->invoice();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        WriteOffService::run($inv, 100, 'Nope');
    }

    public function test_a_written_off_invoice_cannot_be_voided(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();
        WriteOffService::run($inv, 100, 'Part lost');

        try {
            InvoiceService::void($inv, 'Mistake');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_the_invoice_page_writes_off_and_the_list_filters_it(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();

        Volt::test('invoices.detail', ['invoiceId' => $inv->id])
            ->call('startWriteOff')->assertSet('writeOffAmount', '400')
            ->set('writeOffReason', 'Customer untraceable')
            ->call('confirmWriteOff')->assertHasNoErrors()->assertDispatched('notify');

        Volt::test('invoices.detail', ['invoiceId' => $inv->id])->assertSee('Written off');

        Volt::test('invoices.list')->set('status', 'written_off')->assertSee('INV-0001');
        Volt::test('invoices.list')->set('status', 'paid')->assertDontSee('INV-0001');
    }

    public function test_the_income_statement_shows_the_receivables_memo(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();
        InvoiceService::recordPayment($inv, PaymentMethod::where('name', 'Cash')->first()->id, 100, null);

        $this->get(route('reports.income'))->assertOk()->assertSee('Credit sales in this period');

        Volt::test('reports.receivables-memo', ['from' => today()->toDateString(), 'to' => today()->toDateString()])
            ->assertSee('400.00')->assertSee('100.00')->assertSee('300.00');
    }

    public function test_write_offs_are_private_to_each_shop(): void
    {
        $this->actAs('manager');
        $inv = $this->invoice();

        $this->actAs('manager');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        WriteOffService::run($inv, 100, 'Not mine');
    }
}
