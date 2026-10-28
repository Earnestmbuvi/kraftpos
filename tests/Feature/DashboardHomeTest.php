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

class DashboardHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role)
    {
        $u = $this->makeActiveUser();
        $u->assignRole($role);
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        return $u;
    }

    private function sell(int $qty = 3)
    {
        $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 50]);

        return SaleService::complete([['variant_id' => $v->id, 'quantity' => $qty]], null,
            [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => 200 * $qty]]);
    }

    public function test_home_shows_sales_shortcuts_and_the_period_switch(): void
    {
        $this->actAs('manager');
        $this->sell(3);

        $this->get('/dashboard')->assertOk();

        Volt::test('dashboard.home')
            ->assertSee('600.00')->assertSee('Sell')->assertSee('Stock take')->assertSee('Sugar 1kg')
            ->call('setPeriod', 'month')->assertSet('period', 'month')->assertSee('600.00')
            ->call('setPeriod', 'bogus')->assertSet('period', 'month');
    }

    public function test_a_cashier_sees_only_their_own_sales_and_no_profit(): void
    {
        $this->actAs('cashier');
        $this->sell(2); // 400

        Volt::test('dashboard.home')->assertSee('400.00')->assertDontSee('Net profit')->assertDontSee('Stock take');
    }
    public function test_discount_totals_include_line_and_cart_discounts(): void
{
    $this->actAs('manager');
    $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
    $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 50]);

    // line 10% = 20, cart 10 fixed, total 170
    SaleService::complete(
        [['variant_id' => $v->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 10]],
        ['type' => 'fixed', 'value' => 10],
        [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => 170]]
    );

    [$f, $t] = \App\Services\ReportService::range(today()->toDateString(), today()->toDateString());
    $this->assertEquals(30, \App\Services\ReportService::sales($f, $t)['summary']->discount);

    Volt::test('dashboard.home')->assertSee('30.00');
}
}
