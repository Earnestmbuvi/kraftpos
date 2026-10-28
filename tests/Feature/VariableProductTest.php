<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VariableProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function manager()
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    private function pick()
    {
        $size = ProductOption::where('name', 'Size')->first();
        $colour = ProductOption::where('name', 'Colour')->first();

        return [
            [$size->id, $colour->id],
            [
                $size->id => $size->values->whereIn('value', ['S', 'L'])->pluck('id')->all(),
                $colour->id => $colour->values->whereIn('value', ['Red', 'Blue'])->pluck('id')->all(),
            ],
        ];
    }

    public function test_generate_builds_every_combination(): void
    {
        $this->manager();
        [$optionIds, $valueIds] = $this->pick();

        $component = Volt::test('products.manage')
            ->call('openCreate')
            ->call('setMode', 'variable')
            ->set('optionIds', $optionIds)
            ->set('valueIds', $valueIds)
            ->call('generate');

        $this->assertCount(4, $component->get('rows'));
    }

    public function test_manager_can_save_a_variable_product(): void
    {
        $this->manager();
        [$optionIds, $valueIds] = $this->pick();

        Volt::test('products.manage')
            ->call('openCreate')
            ->call('setMode', 'variable')
            ->set('name', 'T-Shirt')
            ->set('optionIds', $optionIds)
            ->set('valueIds', $valueIds)
            ->call('generate')
            ->set('bulkPrice', '800')
            ->set('bulkStock', '5')
            ->call('applyToNew')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertEquals('variable', Product::first()->type);
        $this->assertCount(4, ProductVariant::all());
        $this->assertEquals(5, ProductVariant::first()->stock_quantity);
    }

    public function test_duplicate_sku_in_the_table_is_rejected(): void
    {
        $this->manager();
        [$optionIds, $valueIds] = $this->pick();

        Volt::test('products.manage')
            ->call('openCreate')
            ->call('setMode', 'variable')
            ->set('name', 'T-Shirt')
            ->set('optionIds', $optionIds)
            ->set('valueIds', $valueIds)
            ->call('generate')
            ->set('bulkPrice', '800')
            ->call('applyToNew')
            ->set('rows.0.sku', 'DUP')
            ->set('rows.1.sku', 'DUP')
            ->call('save')
            ->assertHasErrors('rows.1.sku');

        $this->assertEquals(0, Product::count());
    }

    public function test_cashier_cannot_open_or_save_the_form(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');
        $this->actingAs($user);

        Volt::test('products.manage')->call('openCreate')->assertForbidden();
    }
}
