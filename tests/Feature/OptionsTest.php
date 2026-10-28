<?php

namespace Tests\Feature;

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_defaults_are_created_for_a_new_business(): void
    {
        $user = $this->makeActiveUser();
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        $this->assertEquals(['Colour', 'Size'], ProductOption::orderBy('name')->pluck('name')->all());
        $this->assertCount(8, ProductOptionValue::all());
    }

    public function test_options_are_private_to_each_business(): void
    {
        $a = $this->makeActiveUser();
        $a->assignRole('manager');
        $b = $this->makeActiveUser();
        $b->assignRole('manager');

        $this->actingAs($a);
        Volt::test('options.manage')->set('name', 'Material')->call('saveOption')->assertHasNoErrors();

        $this->actingAs($b);
        $this->assertCount(0, ProductOption::all());

        Volt::test('options.manage')->set('name', 'Material')->call('saveOption')->assertHasNoErrors();
        $this->assertCount(1, ProductOption::all());
    }

    public function test_manager_can_add_a_value_and_duplicates_are_rejected(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        $this->actingAs($user);

        $option = ProductOption::create(['name' => 'Size']);

        Volt::test('options.manage')
            ->set("newValues.{$option->id}", 'XXL')
            ->call('addValue', $option->id)
            ->assertHasNoErrors();

        Volt::test('options.manage')
            ->set("newValues.{$option->id}", 'XXL')
            ->call('addValue', $option->id)
            ->assertHasErrors("newValues.{$option->id}");

        $this->assertCount(1, ProductOptionValue::all());
    }

    public function test_cannot_add_a_value_to_another_businesss_option(): void
    {
        $a = $this->makeActiveUser();
        $a->assignRole('manager');
        $b = $this->makeActiveUser();
        $b->assignRole('manager');

        $this->actingAs($a);
        $option = ProductOption::create(['name' => 'Size']);

        $this->actingAs($b);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('options.manage')
            ->set("newValues.{$option->id}", 'XL')
            ->call('addValue', $option->id);
    }

    public function test_cashier_cannot_add_options(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('cashier');
        $this->actingAs($user);

        Volt::test('options.manage')->set('name', 'Material')->call('saveOption')->assertForbidden();
    }

    public function test_options_cannot_be_deleted(): void
    {
        $user = $this->makeActiveUser();
        $user->assignRole('manager');
        $this->actingAs($user);

        $option = ProductOption::create(['name' => 'Size']);

        $this->expectException(\LogicException::class);
        $option->delete();
    }
}
