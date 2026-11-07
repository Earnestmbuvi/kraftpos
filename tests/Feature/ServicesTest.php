<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\ProductImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = $this->makeActiveUser();
        $u->assignRole('manager');
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        return $u;
    }

    public function test_products_and_services_have_their_own_lists_and_a_description(): void
    {
        $this->manager();

        Volt::test('products.manage', ['kind' => 'service'])->call('openCreate')
            ->set('name', 'Haircut')->set('cost_price', '0')->set('selling_price', '500')->set('description', 'Wash and cut')
            ->call('save')->assertHasNoErrors();

        Volt::test('products.manage')->call('openCreate')
            ->set('name', 'Sugar')->set('cost_price', '10')->set('selling_price', '160')->set('stock_quantity', '5')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue((bool) Product::where('name', 'Haircut')->first()->is_service);
        $this->assertEquals('Wash and cut', Product::where('name', 'Haircut')->first()->description);

        Volt::test('products.manage', ['kind' => 'service'])->assertSee('Haircut')->assertDontSee('Sugar');
        Volt::test('products.manage')->assertSee('Sugar')->assertDontSee('Haircut');
    }

    public function test_the_services_import_refuses_products_and_the_export_lists_services(): void
{
    $this->manager();

    $head = "name,category,unit,sku,barcode,cost_price,selling_price,stock,low_stock_threshold,type,tax,branches,description\n";
    $file = function (string $body) use ($head): string {
        $path = tempnam(sys_get_temp_dir(), 'kp');
        file_put_contents($path, $head . $body);

        return $path;
    };

    $bad = ProductImportService::run($file("Sugar,,pcs,,,0,160,5,,product,,,\n"), [], 'service');
    $this->assertNotEmpty($bad['errors']);
    $this->assertStringContainsString('services only', $bad['errors'][0]);

    $ok = ProductImportService::run($file("Haircut,,pcs,,,0,500,,,,,,Wash and cut\n"), [], 'service');
    $this->assertSame([], $ok['errors']);
    $this->assertEquals(1, $ok['created']);
    $this->assertTrue((bool) Product::where('name', 'Haircut')->first()->is_service);

    $this->get(route('services'))->assertOk()->assertSee('Haircut');
    $this->assertStringContainsString('Haircut', $this->get(route('catalog.export', 'service'))->streamedContent());
    $this->get(route('catalog.template', 'service'))->assertOk();
}
}
