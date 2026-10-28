<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\ExpenseService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_output_input_and_net_tax(): void
    {
        $u = $this->makeActiveUser();
        $u->assignRole('manager');
        $u->business->forceFill(['tax_rate' => 16])->save();
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        $a = Product::create(['name' => 'Sugar', 'type' => 'single'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
        $b = Product::create(['name' => 'Bread', 'type' => 'single', 'tax_class' => 'zero'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
        $cash = PaymentMethod::where('name', 'Cash')->first()->id;

        $sale = SaleService::complete(
            [['variant_id' => $a->id, 'quantity' => 1], ['variant_id' => $b->id, 'quantity' => 1]], null,
            [['method_id' => $cash, 'amount' => 216]]
        ); // tax 16

        ExpenseService::record(ExpenseCategory::where('name', 'Supplies')->first()->id, 116, today()->toDateString(), null, null, 'Boxes', 16);

        [$f, $t] = ReportService::range(today()->toDateString(), today()->toDateString());
        $d = ReportService::tax($f, $t);

        $this->assertEquals(16, round($d['output'], 2));
        $this->assertEquals(16, round($d['input'], 2));
        $this->assertEquals(0, round($d['net'], 2));
        $this->assertEquals(100, collect($d['classes'])->firstWhere('label', 'Zero rated')['value']);

        ReturnService::refund($sale->id, [$sale->items->first()->id => 1], 'Faulty', $cash, null);
        $this->assertEquals(0, round(ReportService::tax($f, $t)['output'], 2)); // the taxed item came back

        $this->get(route('reports.tax'))->assertOk()->assertSee('Net tax payable');
        $this->assertStringContainsString('Net tax payable', $this->get(route('reports.export', ['report' => 'tax']))->streamedContent());
    }
}
