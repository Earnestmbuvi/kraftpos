<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_manager_can_open_setup(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');

        $this->actingAs($user)->get('/setup')->assertOk();
    }

    public function test_cashier_is_blocked_from_setup(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');

        $this->actingAs($user)->get('/setup')->assertForbidden();
    }
}
