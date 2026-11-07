<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProductPageAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role)
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = $this->makeActiveUser();
        $u->assignRole($role);
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        return $u;
    }

    private function item()
    {
        $p = Product::create(['name' => 'Sugar', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 160, 'cost_price' => 100, 'stock_quantity' => 0]);

        return [$p, $v];
    }

    public function test_the_product_page_shows_summary_update_and_audit(): void
    {
        $this->actAs('manager');
        [$p, $v] = $this->item();
        StockService::record($v, 10, 'adjustment', 'Purchase received');

        $this->get(route('products.show', $p))->assertOk()->assertSee('Sugar');

        Volt::test('products.show', ['productId' => $p->id])
            ->assertSee('Pricing and codes')
            ->call('setTab', 'audit')->assertSee('Purchase received')
            ->call('setTab', 'update')
            ->set('description', 'White sugar')->set('rows.0.cost_price', '110')
            ->call('save')->assertHasNoErrors();

        $this->assertEquals('White sugar', $p->fresh()->description);
        $this->assertEquals(110, (float) $v->fresh()->cost_price);
        $this->assertTrue(ActivityLog::where('subject_type', 'Product')->where('action', 'updated')->exists());
        $this->assertNotEmpty($v->fresh()->barcode);
    }

    public function test_a_cashier_sees_the_summary_only(): void
    {
        $this->actAs('cashier');
        [$p] = $this->item();

        Volt::test('products.show', ['productId' => $p->id])
            ->call('setTab', 'audit')->assertSet('tab', 'summary')
            ->call('setTab', 'update')->assertSet('tab', 'summary');
    }

    public function test_activity_is_recorded_private_and_limited_to_owners(): void
    {
        $this->actAs('owner');
        $this->item();
        $this->assertTrue(ActivityLog::where('description', 'like', 'Created product Sugar%')->exists());

        $this->get(route('activity'))->assertOk()->assertSee('Created product Sugar');

        $this->actAs('owner'); // another shop
        $this->get(route('activity'))->assertOk()->assertDontSee('Created product Sugar');

        $this->actAs('manager');
        $this->get(route('activity'))->assertForbidden();
    }

    public function test_activity_records_cannot_be_changed_or_deleted(): void
    {
        $this->actAs('owner');
        $this->item();

        $log = ActivityLog::first();
        $this->expectException(\LogicException::class);
        $log->delete();
    }
}
