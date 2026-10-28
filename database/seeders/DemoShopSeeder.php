<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::firstOrCreate(
            ['name' => 'Demo Shop'],
            [
                'phone' => '0711000000',
                'owner_name' => 'Demo Owner',
                'owner_id_number' => '00000000',
            ]
        );
        $business->status = 'active';
        $business->save();

        $users = [];
        foreach (['owner', 'manager', 'cashier'] as $role) {
            $user = User::firstOrNew(['email' => "{$role}@demo.test"]);
            $user->name = ucfirst($role) . ' Demo';
            $user->password = Hash::make('password');
            $user->business_id = $business->id;
            $user->is_active = true;
            $user->save();
            $user->syncRoles([$role]);
            $users[$role] = $user;
        }

        // Act as the owner so the tenant trait fills in business_id
        Auth::login($users['owner']);

        $drinks = Category::firstOrCreate(['name' => 'Drinks', 'parent_id' => null]);
        $soda = Category::firstOrCreate(['name' => 'Soda', 'parent_id' => $drinks->id]);
        $groceries = Category::firstOrCreate(['name' => 'Groceries', 'parent_id' => null]);
        $clothing = Category::firstOrCreate(['name' => 'Clothing', 'parent_id' => null]);

        $singles = [
            ['Bread 400g', $groceries, 'BRD-400', 60, 45, 50, 'pcs'],
            ['Sugar 1kg', $groceries, 'SUG-1KG', 200, 170, 30, 'kg'],
            ['Cooking Oil 1L', $groceries, 'OIL-1L', 350, 300, 20, 'litres'],
            ['Soda 500ml', $soda, 'SOD-500', 70, 50, 100, 'bottle'],
        ];

        foreach ($singles as [$name, $category, $sku, $price, $cost, $stock, $unit]) {
            $product = Product::firstOrCreate(
                ['name' => $name],
                ['category_id' => $category->id, 'type' => 'single', 'unit' => $unit]
            );
            $product->variants()->firstOrCreate(
                ['name' => 'Default'],
                [
                    'sku' => $sku,
                    'selling_price' => $price,
                    'cost_price' => $cost,
                    'stock_quantity' => $stock,
                    'low_stock_threshold' => 5,
                ]
            );
        }

        $shirt = Product::firstOrCreate(
            ['name' => 'T-Shirt'],
            ['category_id' => $clothing->id, 'type' => 'variable', 'unit' => 'pcs']
        );

        foreach (['Small', 'Large'] as $size) {
            foreach (['Red', 'Blue'] as $colour) {
                $shirt->variants()->firstOrCreate(
                    ['name' => "$size / $colour"],
                    [
                        'options' => ['Size' => $size, 'Colour' => $colour],
                        'sku' => 'TSH-' . strtoupper(substr($size, 0, 1) . substr($colour, 0, 1)),
                        'selling_price' => 800,
                        'cost_price' => 500,
                        'stock_quantity' => 15,
                        'low_stock_threshold' => 3,
                    ]
                );
            }
        }

        Auth::logout();
    }
}
