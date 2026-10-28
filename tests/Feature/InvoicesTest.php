<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role = 'cashier')
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

    private function customer()
    {
        return Customer::create([
            'name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi',
        ]);
    }

    private function issue($variant, int $qty = 2, ?array $discount = null, ?int $quotationId = null)
    {
        return InvoiceService::issue(
            $this->customer()->id, null, null,
            [['variant_id' => $variant->id, 'quantity' => $qty]], $discount, $quotationId
        );
    }

    private function cashId(): int
    {
        return PaymentMethod::where('name', 'Cash')->first()->id;
    }

    public function test_a_cashier_can_issue_an_invoice_and_stock_leaves(): void
    {
        $this->actAs('cashier');
        $v = $this->variant(10, 200);

        $inv = $this->issue($v, 2);

        $this->assertEquals('INV-0001', $inv->number_label);
        $this->assertEquals(400, (float) $inv->total);
        $this->assertEquals('issued', $inv->status);
        $this->assertEquals('unpaid', $inv->payment_status);
        $this->assertEquals(8, $v->fresh()->stock_quantity);
        $this->assertEquals(1, StockMovement::where('type', 'sale')->count());
    }

    public function test_services_do_not_touch_stock(): void
    {
        $this->actAs();
        $p = Product::create(['name' => 'Haircut', 'type' => 'single', 'is_service' => true]);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 500, 'stock_quantity' => 0]);

        $this->issue($v, 2);

        $this->assertEquals(0, $v->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_a_cashier_cannot_give_a_discount(): void
    {
        $this->actAs('cashier');
        $v = $this->variant();

        $this->expectException(ValidationException::class);
        $this->issue($v, 1, ['type' => 'percent', 'value' => 10]);
    }

    public function test_another_shops_customer_is_rejected(): void
    {
        $this->actAs();
        $other = $this->customer();

        $this->actAs();
        $v = $this->variant();

        try {
            InvoiceService::issue($other->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }
    }

    public function test_part_payments_move_the_status_and_overpaying_is_rejected(): void
    {
        $this->actAs();
        $v = $this->variant(10, 200);
        $inv = $this->issue($v, 2); // 400

        InvoiceService::recordPayment($inv, $this->cashId(), 100, null);
        $inv = $inv->fresh();
        $this->assertEquals('partly_paid', $inv->payment_status);
        $this->assertEquals(300, $inv->balance);

        try {
            InvoiceService::recordPayment($inv, $this->cashId(), 500, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        InvoiceService::recordPayment($inv, $this->cashId(), 300, null);
        $this->assertEquals('paid', $inv->fresh()->payment_status);
        $this->assertCount(2, $inv->fresh()->payments);
    }

    public function test_payments_can_be_recorded_from_the_invoice_page(): void
    {
        $this->actAs();
        $inv = $this->issue($this->variant(10, 200), 1);

        Volt::test('invoices.detail', ['invoiceId' => $inv->id])
            ->set('amount', '50')->call('recordPayment')->assertHasNoErrors();

        Volt::test('invoices.detail', ['invoiceId' => $inv->id])
            ->set('amount', '9999')->call('recordPayment')->assertHasErrors('amount');

        $this->assertEquals(50, (float) $inv->fresh()->amount_paid);
    }

    public function test_a_manager_can_void_an_unpaid_invoice_and_stock_returns(): void
    {
        $this->actAs('manager');
        $v = $this->variant(10, 200);
        $inv = $this->issue($v, 3);
        $this->assertEquals(7, $v->fresh()->stock_quantity);

        InvoiceService::void($inv, 'Wrong customer');

        $this->assertEquals('voided', $inv->fresh()->payment_status);
        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals(1, Invoice::count()); // nothing deleted
    }

    public function test_a_paid_into_invoice_cannot_be_voided(): void
    {
        $this->actAs('manager');
        $v = $this->variant(10, 200);
        $inv = $this->issue($v, 1);
        InvoiceService::recordPayment($inv, $this->cashId(), 50, null);

        try {
            InvoiceService::void($inv, 'Mistake');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertEquals(9, $v->fresh()->stock_quantity);
    }

    public function test_a_cashier_cannot_void(): void
    {
        $this->actAs('cashier');
        $inv = $this->issue($this->variant());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        InvoiceService::void($inv, 'Trying');
    }

    public function test_invoice_numbers_are_per_business(): void
    {
        $this->actAs();
        $a = $this->issue($this->variant());

        $this->actAs();
        $b = $this->issue($this->variant());

        $this->assertEquals(1, $a->invoice_number);
        $this->assertEquals(1, $b->invoice_number);
        $this->assertEquals(1, Invoice::count());
    }

    public function test_converting_a_quotation_marks_it_and_works_once(): void
    {
        $this->actAs();
        $v = $this->variant(10, 200);
        $quote = QuotationService::create(
            $this->customer()->id, null, null, [['variant_id' => $v->id, 'quantity' => 2]], null
        );

        $this->issue($v, 2, null, $quote->id);
        $this->assertEquals('converted', $quote->fresh()->status);
        $this->assertEquals(8, $v->fresh()->stock_quantity);

        try {
            $this->issue($v, 2, null, $quote->id);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quotation', $e->errors());
        }

        $this->assertEquals(8, $v->fresh()->stock_quantity); // rolled back
        $this->assertEquals(1, Invoice::count());
    }

    public function test_another_shop_cannot_open_the_invoice(): void
    {
        $this->actAs();
        $inv = $this->issue($this->variant());

        $this->actAs();
        $this->get(route('invoices.show', $inv->id))->assertNotFound();
    }

    public function test_a_cashier_can_open_the_invoice_list(): void
    {
        $this->actAs('cashier');

        $this->get('/invoices')->assertOk();
    }

    public function test_invoices_and_payments_cannot_be_deleted(): void
    {
        $this->actAs();
        $inv = $this->issue($this->variant());
        $payment = InvoiceService::recordPayment($inv, $this->cashId(), 10, null);

        try {
            $payment->delete();
            $this->fail('Payment delete should be blocked.');
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        }

        $this->expectException(\LogicException::class);
        $inv->delete();
    }
    public function test_manager_and_cashier_can_open_invoices_and_customers(): void
{
    $this->actAs('manager');
    $this->get('/invoices')->assertOk();

    $this->actAs('cashier');
    $this->get('/invoices')->assertOk();
    $this->get('/customers')->assertOk();
}
}
