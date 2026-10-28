<?php

namespace Tests;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function makeActiveUser(): User
{
    $business = Business::create([
        'name' => 'Test Shop',
        'phone' => '0700000000',
        'owner_name' => 'Owner',
        'owner_id_number' => '12345678',
    ]);
    $business->status = 'active';
    $business->save();

    $user = User::factory()->create(['is_active' => true]);
    $user->business_id = $business->id;
    $user->save();

    return $user->fresh();
}
}
