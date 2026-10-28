<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_default_units_are_created_for_a_new_business(): void
    {
        $user = $this->makeActiveUser();
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        $this->assertCount(count(Unit::DEFAULTS), Unit::all());
    }

    public function test_custom_units_are_private_to_each_business(): void
    {
        $a = $this->makeActiveUser();
        $a->assignRole('manager');
        $b = $this->makeActiveUser();
        $b->assignRole('manager');

        $this->actingAs($a);
        Volt::test('units.manage')->set('name', 'crate')->call('save')->assertHasNoErrors();

        $this->actingAs($b);
        $this->assertCount(0, Unit::all());

        // Shop B can create a unit with the same name
        Volt::test('units.manage')->set('name', 'crate')->call('save')->assertHasNoErrors();
        $this->assertCount(1, Unit::all());
    }

    public function test_duplicate_unit_in_same_business_is_rejected(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        $this->actingAs($user);

        Volt::test('units.manage')->set('name', 'crate')->call('save');
        Volt::test('units.manage')->set('name', 'crate')->call('save')->assertHasErrors('name');
    }

    public function test_cashier_cannot_add_units(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');
        $this->actingAs($user);

        Volt::test('units.manage')->set('name', 'crate')->call('save')->assertForbidden();
    }

    public function test_units_cannot_be_deleted(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        $this->actingAs($user);

        $unit = Unit::create(['name' => 'crate']);

        $this->expectException(\LogicException::class);
        $unit->delete();
    }
}
