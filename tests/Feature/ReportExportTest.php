<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportTest extends TestCase
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

    private function sell(string $name = 'Sugar 1kg', int $qty = 3)
    {
        $p = Product::create(['name' => $name, 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 50]);
        $cash = PaymentMethod::where('name', 'Cash')->first();

        return SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => $qty]], null,
            [['method_id' => $cash->id, 'amount' => 200 * $qty]]
        );
    }

    public function test_every_report_exports_as_csv(): void
    {
        $this->actAs('manager');
        $this->sell();

        foreach (['sales', 'products', 'profit', 'stock', 'owed'] as $report) {
            $r = $this->get(route('reports.export', ['report' => $report]));
            $r->assertOk();
            $this->assertStringContainsString('text/csv', $r->headers->get('Content-Type'));
        }
    }

    public function test_the_sales_csv_carries_the_totals(): void
    {
        $this->actAs('manager');
        $this->sell('Sugar 1kg', 3); // 600

        $csv = $this->get(route('reports.export', ['report' => 'sales']))->streamedContent();

        $this->assertStringContainsString('600', $csv);
        $this->assertStringContainsString('Cash', $csv);
    }

    public function test_spreadsheet_formulas_are_neutralised(): void
    {
        $this->actAs('manager');
        $this->sell('=HYPERLINK("http://evil")');

        $csv = $this->get(route('reports.export', ['report' => 'products']))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_a_cashier_cannot_export(): void
    {
        $this->actAs('cashier');

        $this->get(route('reports.export', ['report' => 'sales']))->assertForbidden();
    }

    public function test_profit_export_needs_cost_price_access(): void
    {
        $user = $this->actAs('cashier');
        $user->givePermissionTo('view-reports'); // can see reports, but not costs

        $this->get(route('reports.export', ['report' => 'sales']))->assertOk();
        $this->get(route('reports.export', ['report' => 'profit']))->assertForbidden();
    }

    public function test_unknown_reports_and_bad_dates_are_refused(): void
    {
        $this->actAs('manager');

        $this->get(route('reports.export', ['report' => 'secrets']))->assertNotFound();
        $this->get(route('reports.export', ['report' => 'sales', 'from' => 'nonsense']))->assertSessionHasErrors('from');
    }

    public function test_an_export_only_contains_the_shops_own_data(): void
    {
        $this->actAs('manager');
        $this->sell('Secret Item');

        $this->actAs('manager'); // second shop
        $csv = $this->get(route('reports.export', ['report' => 'products']))->streamedContent();

        $this->assertStringNotContainsString('Secret Item', $csv);
    }
}
