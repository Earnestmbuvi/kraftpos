<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\BusinessSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UniformFormsTest extends TestCase
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

    public function test_the_layout_carries_the_alert_component(): void
    {
        $this->actAs('manager');

        $this->get('/dashboard')->assertSee('notify.window', false);
    }

    public function test_customers_open_in_a_panel_and_alert_on_add_and_update(): void
    {
        $this->actAs('manager');

        Volt::test('customers.manage')
            ->assertSet('showForm', false)
            ->call('openCreate')->assertSet('showForm', true)
            ->set('name', 'Jane')->set('phone', '0711111111')->set('address', 'Nairobi')
            ->call('save')
            ->assertDispatched('notify', message: 'Customer added.')
            ->assertSet('showForm', false);

        $c = Customer::first();

        Volt::test('customers.manage')
            ->call('edit', $c->id)->assertSet('showForm', true)
            ->set('name', 'Jane W.')->call('save')
            ->assertDispatched('notify', message: 'Customer updated.');
    }

    public function test_a_failed_save_keeps_the_panel_open_and_sends_no_alert(): void
    {
        $this->actAs('manager');

        Volt::test('customers.manage')
            ->call('openCreate')->set('name', 'No contact')->call('save')
            ->assertHasErrors(['phone', 'address'])
            ->assertSet('showForm', true)
            ->assertNotDispatched('notify');
    }

    public function test_expenses_open_in_a_panel_and_alert(): void
    {
        $this->actAs('cashier');

        Volt::test('expenses.manage')
            ->call('openCreate')->assertSet('showForm', true)
            ->set('categoryId', ExpenseCategory::first()->id)
            ->set('amount', '120')->set('description', 'Packing tape')
            ->call('save')
            ->assertDispatched('notify', message: 'Expense recorded.')
            ->assertSet('showForm', false);
    }

    public function test_staff_open_in_a_panel_and_alert(): void
    {
        $this->actAs('owner');

        Volt::test('users.manage')
            ->call('openCreate')->assertSet('showForm', true)
            ->set('name', 'Jane')->set('email', 'jane@shop.test')->set('password', 'secret-pass-1')
            ->call('save')
            ->assertDispatched('notify', message: 'Staff member added.')
            ->assertSet('showForm', false);

        $this->assertTrue(User::where('email', 'jane@shop.test')->exists());
    }
}
