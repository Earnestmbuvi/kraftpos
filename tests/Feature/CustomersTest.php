<?php

namespace Tests\Feature;

use App\Models\Customer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomersTest extends TestCase
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
        $this->actingAs($user);

        return $user;
    }

    public function test_manager_can_add_and_edit_a_customer(): void
{
    $this->actAs('manager');

    Volt::test('customers.manage')
        ->set('name', 'Jane Wanjiku')
        ->set('phone', '0712345678')
        ->set('address', 'Nairobi')
        ->call('save')->assertHasNoErrors();

    $c = Customer::first();

    Volt::test('customers.manage')
        ->call('edit', $c->id)
        ->set('name', 'Jane W.')
        ->call('save')->assertHasNoErrors();

    $this->assertEquals('Jane W.', $c->fresh()->name);
}

   public function test_duplicate_phone_is_rejected_in_one_shop_but_allowed_in_another(): void
{
    $this->actAs('manager');

    Volt::test('customers.manage')
        ->set('name', 'A')->set('phone', '0700')->set('address', 'Nairobi')
        ->call('save')->assertHasNoErrors();

    Volt::test('customers.manage')
        ->set('name', 'B')->set('phone', '0700')->set('address', 'Nairobi')
        ->call('save')->assertHasErrors('phone');

    $this->actAs('manager'); // second shop
    Volt::test('customers.manage')
        ->set('name', 'C')->set('phone', '0700')->set('address', 'Nairobi')
        ->call('save')->assertHasNoErrors();

    $this->assertCount(1, Customer::all());
}

    public function test_customers_are_private_to_each_shop(): void
    {
        $this->actAs('manager');
        Customer::create(['name' => 'Secret']);

        $this->actAs('manager');
        $this->assertCount(0, Customer::all());
    }

   public function test_cashier_can_open_customers_and_add_one(): void
{
    $this->actAs('cashier');

    $this->get('/customers')->assertOk();

    Volt::test('customers.manage')
        ->set('name', 'Walk-in Wanjiru')
        ->set('phone', '0722000111')
        ->set('address', 'Nairobi')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertCount(1, Customer::all());
}

    public function test_customers_cannot_be_deleted(): void
    {
        $this->actAs('manager');
        $c = Customer::create(['name' => 'Keep me']);

        $this->expectException(\LogicException::class);
        $c->delete();
    }
    public function test_phone_and_address_are_required(): void
{
    $this->actAs('manager');

    Volt::test('customers.manage')
        ->set('name', 'No Contact')
        ->call('save')
        ->assertHasErrors(['phone', 'address']);

    $this->assertCount(0, Customer::all());
}
}
