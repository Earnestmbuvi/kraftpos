<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProductCsvTest extends TestCase
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

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $body);
    }

    private const HEAD = "name,category,unit,sku,barcode,cost_price,selling_price,stock,low_stock_threshold,type\n";

    public function test_export_lists_products_and_hides_cost_from_people_without_access(): void
    {
        $this->actAs('manager');
        $p = Product::create(['name' => '=EVIL()', 'type' => 'single']);
        $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => 3]);

        $csv = $this->get(route('products.export'))->streamedContent();
        $this->assertStringContainsString("'=EVIL()", $csv);
        $this->assertStringContainsString('Cost price', $csv);

        $this->actAs('cashier');
        $this->assertStringNotContainsString('Cost price', $this->get(route('products.export'))->streamedContent());
    }

    public function test_the_template_downloads(): void
    {
        $this->actAs('manager');

        $csv = $this->get(route('products.import-template'))->streamedContent();
        $this->assertStringContainsString('selling_price', $csv);
    }

    public function test_a_valid_file_creates_products_with_opening_stock(): void
    {
        $this->actAs('manager');
        Category::create(['name' => 'Groceries']);

        Volt::test('products.import')
            ->set('file', $this->csv(self::HEAD
                . "Sugar 1kg,Groceries,kg,SUG-1,,170,200,30,5,product\n"
                . "Haircut,,pcs,,,0,500,12,3,service\n"
                . "Bread,groceries,,,,,60,,,\n"))
            ->call('import')->assertHasNoErrors()->assertSet('problems', []);

        $this->assertEquals(3, Product::count());

        $sugar = Product::where('name', 'Sugar 1kg')->first();
        $this->assertNotNull($sugar->category_id);
        $this->assertEquals(30, $sugar->variants->first()->stock_quantity);
        $this->assertEquals(1, StockMovement::where('type', 'opening')->count());

        $haircut = Product::where('name', 'Haircut')->first();
        $this->assertTrue((bool) $haircut->is_service);
        $this->assertEquals(0, $haircut->variants->first()->stock_quantity);

        $this->assertEquals('pcs', Product::where('name', 'Bread')->first()->unit);
    }

    public function test_one_bad_row_imports_nothing_and_names_the_row(): void
    {
        $this->actAs('manager');

        $c = Volt::test('products.import')
            ->set('file', $this->csv(self::HEAD
                . "Good,,pcs,,,0,100,5,0,product\n"
                . "Bad,,pcs,,,0,abc,5,0,product\n"))
            ->call('import');

        $this->assertEquals(0, Product::count());
        $this->assertStringContainsString('Row 3', $c->get('problems')[0]);
    }

    public function test_duplicates_unknown_categories_and_units_are_refused(): void
    {
        $this->actAs('manager');
        $p = Product::create(['name' => 'Existing', 'type' => 'single']);
        $p->variants()->create(['name' => 'Default', 'selling_price' => 1, 'sku' => 'TAKEN']);

        $c = Volt::test('products.import')
            ->set('file', $this->csv(self::HEAD
                . "A,,pcs,TAKEN,,0,10,,,\n"
                . "B,,pcs,NEW1,,0,10,,,\n"
                . "C,,pcs,NEW1,,0,10,,,\n"
                . "D,Nowhere,pcs,,,0,10,,,\n"
                . "E,,furlong,,,0,10,,,\n"))
            ->call('import');

        $text = implode(' | ', $c->get('problems'));
        $this->assertStringContainsString('already used', $text);
        $this->assertStringContainsString('twice', $text);
        $this->assertStringContainsString('Category "Nowhere"', $text);
        $this->assertStringContainsString('Unit "furlong"', $text);
        $this->assertEquals(1, Product::count());
    }

    public function test_semicolon_files_and_missing_columns(): void
    {
        $this->actAs('manager');

        Volt::test('products.import')
            ->set('file', $this->csv("name;selling_price;unit\nTea;120;pcs\n"))
            ->call('import')->assertHasNoErrors();
        $this->assertEquals(1, Product::where('name', 'Tea')->count());

        $c = Volt::test('products.import')
            ->set('file', $this->csv("title,price\nX,1\n"))
            ->call('import');
        $this->assertStringContainsString('missing', $c->get('problems')[0]);
    }

    public function test_imported_skus_do_not_clash_across_shops_and_cashiers_are_blocked(): void
    {
        $this->actAs('manager');
        Volt::test('products.import')->set('file', $this->csv(self::HEAD . "A,,pcs,SAME,,0,10,,,\n"))->call('import');

        $this->actAs('manager'); // second shop
        Volt::test('products.import')->set('file', $this->csv(self::HEAD . "A,,pcs,SAME,,0,10,,,\n"))
            ->call('import')->assertSet('problems', []);
        $this->assertEquals(1, Product::count());

        $this->actAs('cashier');
        $this->get('/products/import')->assertForbidden();
        $this->get(route('products.import-template'))->assertForbidden();
    }

    public function test_non_csv_uploads_are_refused(): void
    {
        $this->actAs('manager');

        Volt::test('products.import')
            ->set('file', UploadedFile::fake()->createWithContent('photo.png', "\x89PNG\r\n\x1a\n"))
            ->call('import')->assertHasErrors('file');
    }
}
