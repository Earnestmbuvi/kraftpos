<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusinessWithUser(string $name): array
    {
        $business = Business::create([
            'name' => $name,
            'phone' => '0700000000',
            'owner_name' => 'Owner ' . $name,
            'owner_id_number' => '12345678',
        ]);
        $business->status = 'active';
        $business->save();

        $user = User::factory()->create();
        $user->business_id = $business->id;
        $user->save();

        return [$business, $user];
    }

    private function makeProduct(string $name): Product
    {
        $product = Product::create(['name' => $name, 'type' => 'single']);
        $product->variants()->create([
            'name' => 'Default',
            'selling_price' => 100,
            'stock_quantity' => 10,
        ]);

        return $product;
    }

    public function test_business_cannot_see_another_businesss_products_or_variants(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        [, $userB] = $this->makeBusinessWithUser('Shop B');

        $this->actingAs($userA);
        $this->makeProduct('Sugar');
        Category::create(['name' => 'Groceries']);

        $this->actingAs($userB);
        $this->assertCount(0, Product::all());
        $this->assertCount(0, ProductVariant::all());
        $this->assertCount(0, Category::all());
    }

    public function test_new_records_get_the_logged_in_users_business_id(): void
    {
        [$businessA, $userA] = $this->makeBusinessWithUser('Shop A');

        $this->actingAs($userA);
        $product = $this->makeProduct('Bread');

        $this->assertEquals($businessA->id, $product->business_id);
        $this->assertEquals($businessA->id, $product->variants()->first()->business_id);
    }

    public function test_user_without_a_business_sees_nothing(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        $this->actingAs($userA);
        $this->makeProduct('Sugar');

        $orphan = User::factory()->create();
        $this->actingAs($orphan);

        $this->assertCount(0, Product::all());
    }

    public function test_super_admin_sees_all_businesses_data(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        [, $userB] = $this->makeBusinessWithUser('Shop B');

        $this->actingAs($userA);
        $this->makeProduct('Sugar');
        $this->actingAs($userB);
        $this->makeProduct('Bread');

        $admin = User::factory()->create();
        $admin->is_super_admin = true;
        $admin->save();
        $this->actingAs($admin);

        $this->assertCount(2, Product::all());
    }

    public function test_same_sku_is_allowed_in_different_businesses(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        [, $userB] = $this->makeBusinessWithUser('Shop B');

        $this->actingAs($userA);
        $this->makeProduct('Sugar')->variants()->first()->update(['sku' => 'SUG-1']);

        $this->actingAs($userB);
        $this->makeProduct('Sugar')->variants()->first()->update(['sku' => 'SUG-1']);

        $this->assertCount(1, ProductVariant::all());
    }

    public function test_records_cannot_be_deleted(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        $this->actingAs($userA);
        $product = $this->makeProduct('Sugar');

        $this->expectException(LogicException::class);
        $product->delete();
    }

    public function test_disabled_product_is_excluded_by_the_active_scope(): void
    {
        [, $userA] = $this->makeBusinessWithUser('Shop A');
        $this->actingAs($userA);
        $product = $this->makeProduct('Sugar');

        $product->disable();

        $this->assertCount(0, Product::active()->get());
        $this->assertCount(1, Product::all());
    }
}
