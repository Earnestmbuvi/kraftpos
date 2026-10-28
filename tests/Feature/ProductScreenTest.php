<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProductScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cashier_can_view_products_page(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');

        $this->actingAs($user)->get('/products')->assertOk();
    }

    public function test_manager_can_add_a_single_product(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        Volt::test('products.manage')
            ->set('name', 'Rice 2kg')
            ->set('selling_price', '300')
            ->set('stock_quantity', '10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(1, Product::count());
        $this->assertEquals(10, ProductVariant::first()->stock_quantity);
    }

    public function test_cashier_cannot_save_a_product(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');
        $this->actingAs($user);

        Volt::test('products.manage')
            ->set('name', 'Rice 2kg')
            ->set('selling_price', '300')
            ->call('save')
            ->assertForbidden();

        $this->assertEquals(0, Product::count());
    }

    public function test_duplicate_sku_in_same_business_is_rejected(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        foreach ([1, 2] as $i) {
            $component = Volt::test('products.manage')
                ->set('name', "Item $i")
                ->set('sku', 'SAME-1')
                ->set('selling_price', '100')
                ->call('save');
        }

        $component->assertHasErrors('sku');
        $this->assertEquals(1, Product::count());
    }
}
