<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportsTest extends TestCase
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

    private function sell(int $qty = 3)
    {
        $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
        $v = $p->variants()->create([
            'name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 50,
        ]);
        $cash = PaymentMethod::where('name', 'Cash')->first();

        return SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => $qty]], null,
            [['method_id' => $cash->id, 'amount' => 200 * $qty]]
        );
    }

    public function test_cashier_cannot_open_reports(): void
    {
        $this->actAs('cashier');

        $this->get('/reports')->assertForbidden();
    }

    public function test_manager_can_open_reports(): void
    {
        $this->actAs('manager');

        $this->get('/reports')->assertOk();
    }

    public function test_sales_totals_and_voided_sales_are_excluded(): void
    {
        $this->actAs('manager');
        $this->sell(3);              // 600
        $voided = $this->sell(1);    // 200, then voided
        SaleService::void($voided, 'Mistake');

        Volt::test('reports.view')->assertSee('600.00')->assertDontSee('800.00');
    }

    public function test_profit_is_revenue_minus_saved_cost(): void
    {
        $this->actAs('manager');
        $this->sell(3); // revenue 600, cost 450, profit 150, margin 25%

        Volt::test('reports.view')
            ->call('setTab', 'profit')
            ->assertSee('150.00')
            ->assertSee('450.00')
            ->assertSee('25%');
    }

    public function test_reports_only_show_the_shops_own_data(): void
    {
        $this->actAs('manager');
        $this->sell(3);

        $this->actAs('manager'); // second shop
        Volt::test('reports.view')->assertDontSee('600.00');
    }
}
