<?php

namespace Tests\Feature;

use App\Models\BatchAllocation;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\BatchService;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role = 'manager')
    {
        $user = $this->makeActiveUser();
        $user->assignRole($role);
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    private function tracked(): ProductVariant
    {
        $p = Product::create(['name' => 'Milk 500ml', 'type' => 'single', 'tracks_batches' => true]);

        return $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 70, 'stock_quantity' => 0]);
    }

    private function receive(ProductVariant $v, int $qty, int $days, ?string $number = 'B-1' ): StockBatch
    {
        return BatchService::receive($v, $qty, $number, today()->addDays($days)->toDateString());
    }

    private function sell(ProductVariant $v, int $qty): Sale
    {
        $cash = PaymentMethod::where('name', 'Cash')->first();

        return SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => $qty]], null,
            [['method_id' => $cash->id, 'amount' => 100 * $qty]]
        );
    }

    public function test_receiving_creates_a_batch_raises_stock_and_logs_the_batch(): void
    {
        $this->actAs();
        $v = $this->tracked();

        $b = $this->receive($v, 10, 30, 'B-1');

        $this->assertEquals(10, $b->quantity);
        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals($b->id, StockMovement::first()->stock_batch_id);

        // The same batch number and expiry adds to the same batch
        $this->receive($v, 5, 30, 'B-1');
        $this->assertEquals(1, StockBatch::count());
        $this->assertEquals(15, StockBatch::first()->quantity);
        $this->assertEquals(15, $v->fresh()->stock_quantity);
    }

    public function test_receiving_needs_a_valid_future_expiry_and_quantity(): void
    {
        $this->actAs();
        $v = $this->tracked();

        foreach ([[5, null, 'expiry'], [5, today()->subDay()->toDateString(), 'expiry'],
                  [5, 'nonsense', 'expiry'], [0, today()->addDay()->toDateString(), 'quantity']] as [$qty, $exp, $field]) {
            try {
                BatchService::receive($v, $qty, 'B-1', $exp);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertEquals(0, StockBatch::count());
    }

    public function test_a_cashier_cannot_receive_stock(): void
    {
        $v = $this->actAs('manager') ? $this->tracked() : null;
        $this->actAs('cashier');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        BatchService::receive($v, 5, null, today()->addDays(5)->toDateString());
    }

    public function test_sales_take_the_soonest_expiring_batch_first(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $later = $this->receive($v, 5, 20, 'LATE');
        $soon = $this->receive($v, 3, 5, 'SOON');

        $this->sell($v, 4); // 3 from SOON, then 1 from LATE

        $this->assertEquals(0, $soon->fresh()->quantity);
        $this->assertEquals(4, $later->fresh()->quantity);
        $this->assertEquals(4, $v->fresh()->stock_quantity);
        $this->assertEquals(2, BatchAllocation::count());
    }

    public function test_batches_with_no_expiry_are_used_last(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $dated = $this->receive($v, 2, 10, 'DATED');
        $undated = StockBatch::create(['product_variant_id' => $v->id, 'batch_number' => 'OLD', 'quantity' => 5, 'received_quantity' => 5]);
        $v->update(['stock_quantity' => 7]);

        $this->sell($v, 3);

        $this->assertEquals(0, $dated->fresh()->quantity);
        $this->assertEquals(4, $undated->fresh()->quantity);
    }

    public function test_expired_stock_is_never_sold_and_a_short_sale_leaves_no_trace(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $this->receive($v, 3, 10, 'GOOD');
        $expired = StockBatch::create([
            'product_variant_id' => $v->id, 'batch_number' => 'BAD',
            'expiry_date' => today()->subDay()->toDateString(), 'quantity' => 5, 'received_quantity' => 5,
        ]);
        $v->update(['stock_quantity' => 8]);

        try {
            $this->sell($v, 4); // only 3 are in date
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $this->assertEquals(0, Sale::count());
        $this->assertEquals(8, $v->fresh()->stock_quantity);
        $this->assertEquals(5, $expired->fresh()->quantity);
        $this->assertEquals(0, BatchAllocation::count());

        $this->sell($v, 3); // the in-date quantity still sells
        $this->assertEquals(5, $v->fresh()->stock_quantity);
        $this->assertEquals(5, $expired->fresh()->quantity);
    }

    public function test_voiding_a_sale_returns_stock_to_the_same_batches(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $a = $this->receive($v, 3, 5, 'A');
        $b = $this->receive($v, 5, 20, 'B');
        $sale = $this->sell($v, 4);

        SaleService::void($sale, 'Mistake');

        $this->assertEquals(3, $a->fresh()->quantity);
        $this->assertEquals(5, $b->fresh()->quantity);
        $this->assertEquals(8, $v->fresh()->stock_quantity);
    }

    public function test_invoices_use_batches_and_voiding_puts_them_back(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $a = $this->receive($v, 2, 5, 'A');
        $b = $this->receive($v, 5, 20, 'B');
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0712345678', 'address' => 'Nairobi']);

        $inv = InvoiceService::issue($customer->id, null, null, [['variant_id' => $v->id, 'quantity' => 4]], null);

        $this->assertEquals(0, $a->fresh()->quantity);
        $this->assertEquals(3, $b->fresh()->quantity);

        InvoiceService::void($inv, 'Wrong customer');

        $this->assertEquals(2, $a->fresh()->quantity);
        $this->assertEquals(5, $b->fresh()->quantity);
        $this->assertEquals(7, $v->fresh()->stock_quantity);
    }

    public function test_batch_adjustments_remove_and_count_without_going_below_zero(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $b = $this->receive($v, 10, 10, 'A');

        BatchService::adjust($b, 'remove', 4, 'Expired', null);
        $this->assertEquals(6, $b->fresh()->quantity);
        $this->assertEquals(6, $v->fresh()->stock_quantity);

        BatchService::adjust($b, 'set', 5, 'Stock-take correction', null);
        $this->assertEquals(5, $v->fresh()->stock_quantity);

        try {
            BatchService::adjust($b, 'remove', 9, 'Damaged', null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertEquals(5, $b->fresh()->quantity);
    }

    public function test_tracking_converts_existing_stock_into_one_batch(): void
    {
        $this->actAs();
        $p = Product::create(['name' => 'Bread', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'stock_quantity' => 12]);

        BatchService::enableTracking($p);

        $this->assertTrue((bool) $p->fresh()->tracks_batches);
        $batch = StockBatch::first();
        $this->assertEquals('Existing stock', $batch->batch_number);
        $this->assertNull($batch->expiry_date);
        $this->assertEquals(12, $batch->quantity);

        $this->sell($v, 5);
        $this->assertEquals(7, $batch->fresh()->quantity);
    }

    public function test_tracking_is_refused_for_negative_stock_and_for_services(): void
    {
        $this->actAs();
        $p = Product::create(['name' => 'Bread', 'type' => 'single']);
        $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'stock_quantity' => -2]);

        try {
            BatchService::enableTracking($p);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tracksBatches', $e->errors());
        }
        $this->assertFalse((bool) $p->fresh()->tracks_batches);

        $s = Product::create(['name' => 'Haircut', 'type' => 'single', 'is_service' => true]);
        $this->expectException(ValidationException::class);
        BatchService::enableTracking($s);
    }

    public function test_the_product_form_tracks_new_products_from_zero_and_converts_existing_ones(): void
    {
        $this->actAs();

        Volt::test('products.manage')
            ->call('openCreate')
            ->set('name', 'Yoghurt')->set('selling_price', '80')->set('stock_quantity', '10')
            ->set('tracksBatches', true)
            ->call('save')->assertHasNoErrors();

        $yoghurt = Product::where('name', 'Yoghurt')->first();
        $this->assertTrue((bool) $yoghurt->tracks_batches);
        $this->assertEquals(0, $yoghurt->variants->first()->stock_quantity); // received later, with an expiry
        $this->assertEquals(0, StockBatch::count());

        Volt::test('products.manage')
            ->call('openCreate')
            ->set('name', 'Bread')->set('selling_price', '60')->set('stock_quantity', '10')
            ->call('save')->assertHasNoErrors();

        $bread = Product::where('name', 'Bread')->first();
        Volt::test('products.manage')
            ->call('edit', $bread->id)->set('tracksBatches', true)
            ->call('save')->assertHasNoErrors();

        $this->assertTrue((bool) $bread->fresh()->tracks_batches);
        $this->assertEquals(10, StockBatch::where('batch_number', 'Existing stock')->first()->quantity);
    }

    public function test_the_stock_screen_receives_into_batches(): void
    {
        $this->actAs();
        $v = $this->tracked();

        Volt::test('stock.manage')
            ->call('select', $v->id)
            ->set('quantity', '10')->set('batchNumber', 'B-7')
            ->call('save')->assertHasErrors('expiry');

        Volt::test('stock.manage')
            ->call('select', $v->id)
            ->set('quantity', '10')->set('batchNumber', 'B-7')->set('expiry', today()->addDays(14)->toDateString())
            ->call('save')->assertHasNoErrors();

        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals('B-7', StockBatch::first()->batch_number);

        Volt::test('stock.manage')
            ->call('select', $v->id)
            ->set('mode', 'remove')->set('quantity', '2')->set('reason', 'Expired')
            ->call('save')->assertHasErrors('batchId');
    }

    public function test_batches_are_private_to_each_shop_and_cannot_be_deleted(): void
    {
        $this->actAs();
        $b = $this->receive($this->tracked(), 5, 10, 'SECRET');

        try {
            $b->delete();
            $this->fail('Batches must not be deletable.');
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        }

        $this->actAs(); // second shop
        $this->assertCount(0, StockBatch::all());
    }

    public function test_the_expiry_report_lists_expired_and_expiring_batches_and_exports(): void
    {
        $this->actAs();
        $v = $this->tracked();
        $this->receive($v, 4, 5, 'SOON-1');
        $this->receive($v, 4, 200, 'FAR-1');
        StockBatch::create([
            'product_variant_id' => $v->id, 'batch_number' => 'OLD-1',
            'expiry_date' => today()->subDays(2)->toDateString(), 'quantity' => 3, 'received_quantity' => 3,
        ]);

        Volt::test('reports.view')->call('setTab', 'expiry')
            ->assertSee('SOON-1')->assertSee('OLD-1')->assertDontSee('FAR-1');

        $csv = $this->get(route('reports.export', ['report' => 'expiry']))->streamedContent();
        $this->assertStringContainsString('SOON-1', $csv);
        $this->assertStringNotContainsString('FAR-1', $csv);
    }
}
