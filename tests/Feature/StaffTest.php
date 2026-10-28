<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StaffTest extends TestCase
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

    private function colleague(User $of, string $role = 'cashier', bool $active = true): User
    {
        $u = User::factory()->create(['is_active' => $active]);
        $u->business_id = $of->business_id;
        $u->save();
        $u->assignRole($role);

        return $u->fresh();
    }

    private function add(string $email = 'jane@shop.test', string $role = 'cashier', array $extras = [])
    {
        return Volt::test('users.manage')
            ->set('name', 'Jane')->set('email', $email)->set('role', $role)
            ->set('password', 'secret-pass-1')->set('extras', $extras)
            ->call('save');
    }

    public function test_only_owners_can_open_staff(): void
    {
        $this->actAs('manager');
        $this->get('/staff')->assertForbidden();

        $this->actAs('cashier');
        $this->get('/staff')->assertForbidden();

        $this->actAs('owner');
        $this->get('/staff')->assertOk();
    }

    public function test_owner_adds_staff_into_their_own_business_with_the_role(): void
    {
        $owner = $this->actAs('owner');

        $this->add()->assertHasNoErrors();

        $jane = User::where('email', 'jane@shop.test')->first();
        $this->assertEquals($owner->business_id, $jane->business_id);
        $this->assertTrue($jane->hasRole('cashier'));
        $this->assertTrue((bool) $jane->is_active);
        $this->assertNotNull($jane->email_verified_at);
        $this->assertFalse((bool) $jane->is_super_admin);
    }

    public function test_duplicate_email_and_unknown_roles_are_rejected(): void
    {
        $this->actAs('owner');
        $this->add();

        $this->add()->assertHasErrors('email');
        $this->add('x@shop.test', 'super-admin')->assertHasErrors('role');
        $this->assertEquals(0, User::where('email', 'x@shop.test')->count());
    }

    public function test_extra_permissions_are_granted_and_dangerous_ones_refused(): void
    {
        $this->actAs('owner');

        $this->add('a@shop.test', 'cashier', ['give-discounts'])->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'a@shop.test')->first()->can('give-discounts'));

        $this->add('b@shop.test', 'cashier', ['manage-users'])->assertHasErrors('extras.*');
        $this->assertEquals(0, User::where('email', 'b@shop.test')->count());
    }

    public function test_an_owner_cannot_reach_another_shops_staff(): void
    {
        $other = $this->actAs('owner');
        $theirs = $this->colleague($other);

        $this->actAs('owner'); // a different shop
        $this->expectException(ModelNotFoundException::class);
        Volt::test('users.manage')->call('edit', $theirs->id);
    }

    public function test_disabling_staff_blocks_them_and_nothing_is_deleted(): void
    {
        $owner = $this->actAs('owner');
        $staff = $this->colleague($owner);

        Volt::test('users.manage')->call('toggle', $staff->id)->assertHasNoErrors();
        $this->assertFalse((bool) $staff->fresh()->is_active);

        $this->actingAs($staff->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertEquals(1, User::where('id', $staff->id)->count());
    }

    public function test_owner_cannot_disable_or_demote_themselves(): void
    {
        $owner = $this->actAs('owner');

        Volt::test('users.manage')->call('toggle', $owner->id)->assertHasErrors('action');

        Volt::test('users.manage')->call('edit', $owner->id)
            ->set('role', 'manager')->call('save')->assertHasErrors('role');

        $this->assertTrue($owner->fresh()->hasRole('owner'));
    }

    public function test_the_last_owner_cannot_be_removed_but_a_second_owner_can_be_swapped(): void
    {
        $owner = $this->actAs('owner');
        $second = $this->colleague($owner, 'owner');

        // Two owners: demoting the second is fine
        Volt::test('users.manage')->call('edit', $second->id)
            ->set('role', 'manager')->call('save')->assertHasNoErrors();
        $this->assertTrue($second->fresh()->hasRole('manager'));

        // Promote again, then act as the second owner and try to disable the first
        $second->syncRoles(['owner']);
        $this->actingAs($second->fresh());
        Volt::test('users.manage')->call('toggle', $owner->id)->assertHasNoErrors();

        // Now the first owner is disabled, so the second is the last active one
        $third = $this->colleague($second->fresh(), 'manager');
        Volt::test('users.manage')->call('edit', $third->id)->set('role', 'owner')->call('save');
        $this->assertTrue($third->fresh()->hasRole('owner'));
    }

    public function test_role_changes_and_extras_can_be_edited(): void
    {
        $owner = $this->actAs('owner');
        $staff = $this->colleague($owner);

        Volt::test('users.manage')->call('edit', $staff->id)
            ->set('role', 'manager')->set('extras', [])->call('save')->assertHasNoErrors();

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasRole('manager'));
        $this->assertTrue($staff->can('manage-products'));
        $this->assertFalse($staff->can('manage-users'));
    }
}
