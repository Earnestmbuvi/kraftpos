<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StockTest extends TestCase
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

    private function variant(int $stock = 10)
    {
        $product = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);

        return $product->variants()->create([
            'name' => 'Default', 'selling_price' => 200, 'stock_quantity' => $stock,
        ]);
    }

    public function test_adding_stock_updates_quantity_and_logs_a_movement(): void
    {
        $this->actAs('manager');
        $variant = $this->variant(10);

        Volt::test('stock.manage')
            ->call('select', $variant->id)
            ->set('quantity', '5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(15, $variant->fresh()->stock_quantity);

        $m = StockMovement::first();
        $this->assertEquals(5, $m->quantity_change);
        $this->assertEquals(15, $m->quantity_after);
    }

    public function test_removing_more_than_available_is_rejected(): void
    {
        $this->actAs('manager');
        $variant = $this->variant(3);

        Volt::test('stock.manage')
            ->call('select', $variant->id)
            ->set('mode', 'remove')
            ->set('quantity', '5')
            ->call('save')
            ->assertHasErrors('quantity');

        $this->assertEquals(3, $variant->fresh()->stock_quantity);
        $this->assertEquals(0, StockMovement::count());
    }

    public function test_set_to_counted_logs_the_difference(): void
    {
        $this->actAs('manager');
        $variant = $this->variant(10);

        Volt::test('stock.manage')
            ->call('select', $variant->id)
            ->set('mode', 'set')
            ->set('quantity', '7')
            ->set('reason', 'Stock-take correction')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(7, $variant->fresh()->stock_quantity);
        $this->assertEquals(-3, StockMovement::first()->quantity_change);
    }

    public function test_cashier_cannot_open_stock_page_or_adjust(): void
    {
        $user = $this->actAs('manager');
        $variant = $this->variant(10);

        $cashier = $this->makeActiveUser();
        $cashier->assignRole('cashier');
        $this->actingAs($cashier)->get('/stock')->assertForbidden();

        Volt::test('stock.manage')->call('select', $variant->id)->assertForbidden();
    }

    public function test_cannot_adjust_another_businesss_variant(): void
    {
        $this->actAs('manager');
        $variant = $this->variant(10);

        $this->actAs('manager'); // second business
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('stock.manage')->call('select', $variant->id);
    }

    public function test_movements_cannot_be_changed_or_deleted(): void
    {
        $this->actAs('manager');
        $variant = $this->variant(10);
        \App\Services\StockService::record($variant, 5, 'adjustment', 'Other');

        $m = StockMovement::first();

        $this->expectException(\LogicException::class);
        $m->delete();
    }
}
