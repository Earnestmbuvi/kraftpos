<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_has_all_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $this->assertTrue($user->can('manage-users'));
        $this->assertTrue($user->can('manage-products'));
        $this->assertTrue($user->can('make-sales'));
    }

    public function test_manager_cannot_manage_users_or_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->assertTrue($user->can('manage-products'));
        $this->assertTrue($user->can('view-reports'));
        $this->assertFalse($user->can('manage-users'));
        $this->assertFalse($user->can('manage-settings'));
    }

    public function test_cashier_can_only_sell_and_view_products(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $this->assertTrue($user->can('make-sales'));
        $this->assertTrue($user->can('view-products'));
        $this->assertFalse($user->can('manage-products'));
        $this->assertFalse($user->can('view-cost-prices'));
        $this->assertFalse($user->can('adjust-stock'));
    }
}
