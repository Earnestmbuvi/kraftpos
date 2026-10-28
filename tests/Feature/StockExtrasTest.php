<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use App\Services\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StockExtrasTest extends TestCase
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

    private function variant(string $name = 'Sugar 1kg', int $stock = 10, int $low = 0, array $product = [])
    {
        $p = Product::create(['name' => $name, 'type' => 'single'] + $product);

        return $p->variants()->create([
            'name' => 'Default', 'selling_price' => 100, 'cost_price' => 60,
            'stock_quantity' => $stock, 'low_stock_threshold' => $low,
        ]);
    }

    public function test_the_till_marks_low_stock_red_and_healthy_stock_green(): void
    {
        $this->actAs('cashier');
        $this->variant('Sugar 1kg', 2, 5);

        Volt::test('sell.till')->assertSee('text-red-600 font-semibold', false)->assertDontSee('text-green-700', false);

        $this->actAs('cashier');
        $this->variant('Rice 2kg', 50, 5);

        Volt::test('sell.till')->assertSee('text-green-700', false)->assertDontSee('text-red-600 font-semibold', false);
    }

    public function test_the_till_shows_the_next_expiry_and_flags_expired_stock(): void
    {
        $this->actAs('cashier');
        $v = $this->variant('Milk', 5, 0, ['tracks_batches' => true]);
        StockBatch::create([
            'product_variant_id' => $v->id, 'batch_number' => 'B1',
            'expiry_date' => today()->addDays(10)->toDateString(), 'quantity' => 5, 'received_quantity' => 5,
        ]);

        Volt::test('sell.till')->assertSee('Exp ' . today()->addDays(10)->format('d M Y'));

        $this->actAs('cashier');
        $old = $this->variant('Yoghurt', 4, 0, ['tracks_batches' => true]);
        StockBatch::create([
            'product_variant_id' => $old->id, 'batch_number' => 'OLD',
            'expiry_date' => today()->subDay()->toDateString(), 'quantity' => 4, 'received_quantity' => 4,
        ]);

        Volt::test('sell.till')->assertSee('Expired');
    }

    public function test_the_movements_page_filters_and_exports(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar 1kg', 10);
        StockService::record($v, -2, 'adjustment', 'Damaged');
        StockService::record($v, -1, 'sale', 'Sale 0001');

        $this->get('/stock/movements')->assertOk();

        Volt::test('stock.movements')->set('type', 'sale')->assertSee('Sale 0001')->assertDontSee('Damaged');

        $csv = $this->get(route('stock.movements.export'))->streamedContent();
        $this->assertStringContainsString('Sugar 1kg', $csv);
        $this->assertStringContainsString('Damaged', $csv);

        $sales = $this->get(route('stock.movements.export', ['type' => 'sale']))->streamedContent();
        $this->assertStringNotContainsString('Damaged', $sales);

        $this->get(route('stock.movements.export', ['type' => 'bogus']))->assertSessionHasErrors('type');
    }

    public function test_movements_are_private_and_cashiers_are_blocked(): void
    {
        $this->actAs('manager');
        StockService::record($this->variant('Secret Item', 10), -1, 'sale', 'Sale 0001');

        $this->actAs('manager');
        $csv = $this->get(route('stock.movements.export'))->streamedContent();
        $this->assertStringNotContainsString('Secret Item', $csv);

        $this->actAs('cashier');
        $this->get('/stock/movements')->assertForbidden();
        $this->get(route('stock.movements.export'))->assertForbidden();
    }

    public function test_a_stock_take_adjusts_stock_and_logs_movements(): void
    {
        $this->actAs('manager');
        $a = $this->variant('Sugar 1kg', 10);
        $b = $this->variant('Rice 2kg', 5);
        $c = $this->variant('Salt 500g', 8);

        Volt::test('stock.take')
            ->set('counts.' . $a->id, '7')
            ->set('counts.' . $b->id, '5')
            ->assertSet('expected.' . $a->id, 10)
            ->set('note', 'October count')
            ->call('apply')->assertHasNoErrors()->assertDispatched('notify');

        $this->assertEquals(7, $a->fresh()->stock_quantity);
        $this->assertEquals(5, $b->fresh()->stock_quantity);
        $this->assertEquals(8, $c->fresh()->stock_quantity); // not counted, not touched

        $m = StockMovement::where('type', 'stocktake')->get();
        $this->assertCount(1, $m); // the matching row creates no movement
        $this->assertEquals(-3, $m->first()->quantity_change);
        $this->assertEquals('October count', $m->first()->note);
    }

    public function test_sales_made_during_the_count_are_kept(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar 1kg', 10);

        $component = Volt::test('stock.take')->set('counts.' . $v->id, '7'); // counted 7, system said 10

        $v->update(['stock_quantity' => 9]); // one sold while counting

        $component->call('apply')->assertHasNoErrors();
        $this->assertEquals(6, $v->fresh()->stock_quantity); // 9 + (7 - 10)
    }

    public function test_bad_counts_are_refused_and_change_nothing(): void
    {
        $this->actAs('manager');
        $v = $this->variant('Sugar 1kg', 10);

        foreach (['-1', '2.5', 'abc'] as $bad) {
            Volt::test('stock.take')->set('counts.' . $v->id, $bad)->call('apply')->assertHasErrors('counts');
        }
        Volt::test('stock.take')->call('apply')->assertHasErrors('counts');

        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_services_and_batch_items_are_not_counted_and_cannot_be_forced(): void
    {
        $this->actAs('manager');
        $svc = $this->variant('Haircut', 0, 0, ['is_service' => true]);
        $tracked = $this->variant('Milk', 6, 0, ['tracks_batches' => true]);

        Volt::test('stock.take')->assertDontSee('Haircut')->assertDontSee('Milk')
            ->set('counts.' . $tracked->id, '1')->set('counts.' . $svc->id, '9')
            ->call('apply');

        $this->assertEquals(6, $tracked->fresh()->stock_quantity);
        $this->assertEquals(0, $svc->fresh()->stock_quantity);
    }

    public function test_another_shops_item_is_ignored(): void
    {
        $this->actAs('manager');
        $theirs = $this->variant('Theirs', 10);

        $this->actAs('manager');
        Volt::test('stock.take')->set('counts.' . $theirs->id, '1')->call('apply');

        $this->assertEquals(10, $theirs->fresh()->stock_quantity);
    }

    public function test_fill_all_copies_system_quantities_and_only_differences_filters(): void
    {
        $this->actAs('manager');
        $a = $this->variant('Sugar 1kg', 10);
        $b = $this->variant('Rice 2kg', 5);

        Volt::test('stock.take')
            ->call('fillAll')
            ->assertSet('counts.' . $a->id, '10')
            ->set('counts.' . $b->id, '4')
            ->set('onlyDifferences', true)
            ->assertSee('Rice 2kg')->assertDontSee('Sugar 1kg');
    }

    public function test_cashiers_cannot_open_the_stock_take(): void
    {
        $this->actAs('cashier');

        $this->get('/stock/take')->assertForbidden();
    }
}
