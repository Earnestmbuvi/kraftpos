<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\ItemCodes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ItemCodesAndLabelsTest extends TestCase
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

    public function test_missing_codes_are_generated_and_given_codes_are_kept(): void
    {
        $this->manager();

        $a = Product::create(['name' => 'Sugar', 'type' => 'single'])->variants()->create(['name' => 'Default', 'selling_price' => 10, 'cost_price' => 5]);
        $b = Product::create(['name' => 'Tea', 'type' => 'single'])->variants()->create(['name' => 'Default', 'selling_price' => 10, 'cost_price' => 5]);
        $c = Product::create(['name' => 'Salt', 'type' => 'single'])->variants()->create(['name' => 'Default', 'selling_price' => 10, 'cost_price' => 5, 'sku' => 'MY-SKU', 'barcode' => '6001234567893']);

        $this->assertStringStartsWith('SKU-', $a->sku);
        $this->assertTrue(ItemCodes::validEan13($a->barcode));
        $this->assertNotEquals($a->sku, $b->sku);
        $this->assertNotEquals($a->barcode, $b->barcode);
        $this->assertEquals('MY-SKU', $c->sku);
        $this->assertEquals('6001234567893', $c->barcode);
    }

    public function test_cost_price_is_required_on_the_form(): void
    {
        $this->manager();

        Volt::test('products.manage')->call('openCreate')
            ->set('name', 'Milk')->set('selling_price', '60')->set('stock_quantity', '5')
            ->call('save')->assertHasErrors(['cost_price'])
            ->set('cost_price', '0')->call('save')->assertHasNoErrors();

        $this->assertNotEmpty(Product::where('name', 'Milk')->first()->variants->first()->barcode);
    }

    public function test_the_labels_page_prints_a_scannable_barcode(): void
    {
        $this->manager();
        $v = Product::create(['name' => 'Sugar', 'type' => 'single'])->variants()->create(['name' => 'Default', 'selling_price' => 160, 'cost_price' => 100]);

        $this->get(route('labels'))->assertOk();

        Volt::test('labels.print')->call('addVariant', $v->id)
            ->assertSee('Sugar')->assertSee('<svg', false)->assertSee($v->fresh()->barcode);
    }
}
