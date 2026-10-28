<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SalesSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role)
    {
        $user = $this->makeActiveUser();
        $user->assignRole($role);
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    public function test_default_payment_methods_exist(): void
    {
        $this->actAs('owner');

        $this->assertEquals(['Cash', 'M-Pesa'], PaymentMethod::orderBy('name')->pluck('name')->all());
    }

    public function test_owner_can_add_a_payment_method_but_manager_cannot(): void
    {
        $this->actAs('owner');
        Volt::test('payments.manage')->set('name', 'Card')->set('type', 'card')->call('save')->assertHasNoErrors();
        $this->assertCount(3, PaymentMethod::all());

        $this->actAs('manager');
        Volt::test('payments.manage')->set('name', 'Card')->call('save')->assertForbidden();
    }

    public function test_owner_can_set_tax_but_manager_cannot(): void
    {
        $owner = $this->actAs('owner');
        Volt::test('settings.tax')->set('tax_rate', '16')->set('tax_inclusive', true)->call('save')->assertHasNoErrors();

        $this->assertEquals(16, $owner->business->fresh()->tax_rate);
        $this->assertTrue($owner->business->fresh()->tax_inclusive);

        $this->actAs('manager');
        Volt::test('settings.tax')->set('tax_rate', '5')->call('save')->assertForbidden();
    }
}
