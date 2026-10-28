<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Business;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function shopOwner(): User
    {
        $u = $this->makeActiveUser();
        $u->assignRole('owner');

        return $u;
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->is_super_admin = true;
        $u->save();
        $this->actingAs($u->fresh());

        return $u;
    }

    public function test_only_the_super_admin_can_open_a_merchant_page(): void
    {
        $owner = $this->shopOwner();
        $this->actingAs($owner)->get(route('admin.merchant', $owner->business_id))->assertForbidden();

        $this->superAdmin();
        $this->get(route('admin.merchant', $owner->business_id))->assertOk()->assertSee($owner->business->name);
    }

    public function test_resetting_a_password_works_signs_them_out_and_is_logged(): void
    {
        $owner = $this->shopOwner();
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time(),
        ]);
        $admin = $this->superAdmin();

        Volt::test('admin.merchant', ['businessId' => $owner->business_id])
            ->call('startReset', $owner->id)->set('password', 'brand-new-pass')
            ->call('confirmReset')->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass', $owner->fresh()->password));
        $this->assertEquals(0, DB::table('sessions')->where('user_id', $owner->id)->count());

        $log = AdminAuditLog::first();
        $this->assertEquals('password_reset', $log->action);
        $this->assertEquals($admin->id, $log->admin_id);
        $this->assertEquals($owner->id, $log->target_user_id);
        $this->assertStringNotContainsString('brand-new-pass', (string) $log->detail);
    }

    public function test_short_passwords_are_refused(): void
    {
        $owner = $this->shopOwner();
        $this->superAdmin();

        Volt::test('admin.merchant', ['businessId' => $owner->business_id])
            ->call('startReset', $owner->id)->set('password', 'short')
            ->call('confirmReset')->assertHasErrors('password');

        $this->assertEquals(0, AdminAuditLog::count());
    }

    public function test_you_cannot_reset_a_user_of_another_merchant_or_a_super_admin(): void
    {
        $a = $this->shopOwner();
        $b = $this->shopOwner();
        $admin = $this->superAdmin();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('admin.merchant', ['businessId' => $a->business_id])->call('startReset', $b->id);
    }

    public function test_a_super_admin_user_cannot_be_targeted(): void
    {
        $owner = $this->shopOwner();
        $admin = $this->superAdmin();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('admin.merchant', ['businessId' => $owner->business_id])->call('startReset', $admin->id);
    }

    public function test_disabling_a_user_signs_them_out_blocks_them_and_is_logged(): void
    {
        $owner = $this->shopOwner();
        DB::table('sessions')->insert([
            'id' => 'def', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time(),
        ]);
        $this->superAdmin();

        Volt::test('admin.merchant', ['businessId' => $owner->business_id])->call('toggleUser', $owner->id);

        $this->assertFalse((bool) $owner->fresh()->is_active);
        $this->assertEquals(0, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->assertEquals('user_disabled', AdminAuditLog::first()->action);

        $this->actingAs($owner->fresh())->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_an_owner_cannot_use_the_admin_actions(): void
    {
        $owner = $this->shopOwner();
        $this->actingAs($owner);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        \App\Services\AdminService::resetPassword($owner->business, $owner->id, 'whatever-pass');
    }

    public function test_audit_logs_cannot_be_changed_or_deleted(): void
    {
        $owner = $this->shopOwner();
        $this->superAdmin();
        \App\Services\AdminService::toggleUser($owner->business, $owner->id);

        $this->expectException(\LogicException::class);
        AdminAuditLog::first()->delete();
    }

    public function test_the_merchant_page_only_counts_that_merchants_data(): void
    {
        $a = $this->shopOwner();
        $this->actingAs($a);
        \App\Models\Customer::create(['name' => 'A customer', 'phone' => '0700', 'address' => 'x']);

        $b = $this->shopOwner();
        $this->superAdmin();

        $html = Volt::test('admin.merchant', ['businessId' => $b->business_id])->html();
        $this->assertStringContainsString('Customers', $html);
        $this->assertEquals(1, \App\Models\Customer::where('business_id', $a->business_id)->count());
        $this->assertEquals(0, \App\Models\Customer::where('business_id', $b->business_id)->count());
    }
    public function test_closing_an_account_disables_its_users_and_can_be_reversed(): void
{
    $owner = $this->makeActiveUser();
    $admin = $this->makeActiveUser();
    $admin->forceFill(['is_super_admin' => true, 'business_id' => null])->save();
    $this->actingAs($admin->fresh());

    Volt::test('admin.merchant', ['businessId' => $owner->business_id])
        ->set('closeReason', '')->call('closeAccount')->assertHasErrors(['closeReason'])
        ->set('closeReason', 'Contract ended')->call('closeAccount')->assertHasNoErrors();

    $this->assertFalse((bool) $owner->fresh()->is_active);

    Volt::test('admin.merchant', ['businessId' => $owner->business_id])->call('reopenAccount');
    $this->assertTrue((bool) $owner->fresh()->is_active);
}
}
