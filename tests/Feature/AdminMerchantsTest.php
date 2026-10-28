<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminMerchantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->is_super_admin = true;
        $u->save();
        $this->actingAs($u->fresh());

        return $u;
    }

    private function create(array $over = [])
{
    $values = $over + [
        'name' => 'Mama Shop', 'category' => 'Grocery', 'phone' => '0711000111',
        'email' => 'mama@business.test', 'address' => 'Tom Mboya St', 'county' => 'Nairobi',
        'owner_name' => 'Mama Wanjiru', 'owner_id_number' => '12345678',
        'owner_phone' => '0722000222', 'owner_dob' => '1985-04-12',
        'login_email' => 'mama@shop.test', 'password' => 'temp-pass-1',
    ];

    $c = Volt::test('admin.businesses');
    foreach ($values as $k => $v) {
        $c->set($k, $v);
    }

    return $c->call('save');
}

    public function test_only_the_super_admin_can_open_the_panel(): void
    {
        $owner = $this->makeActiveUser();
        $owner->assignRole('owner');
        $this->actingAs($owner)->get('/admin/merchants')->assertForbidden();

        $this->superAdmin();
        $this->get('/admin/merchants')->assertOk();
    }

    public function test_the_super_admin_is_kept_out_of_shop_screens_and_sent_to_the_panel(): void
    {
        $this->superAdmin();

        $this->get('/products')->assertForbidden();
        $this->get('/sell')->assertForbidden();
        $this->get('/dashboard')->assertRedirect(route('admin.businesses'));
    }

    public function test_creating_a_merchant_sets_up_the_shop_and_its_owner(): void
    {
        $this->superAdmin();

        $this->create()->assertHasNoErrors();

        $b = Business::where('name', 'Mama Shop')->first();
        $this->assertEquals('active', $b->status);
        $this->assertNotNull($b->reviewed_at);
        $this->assertGreaterThan(0, Unit::withoutGlobalScopes()->where('business_id', $b->id)->count());
        $this->assertGreaterThan(0, PaymentMethod::withoutGlobalScopes()->where('business_id', $b->id)->count());

        $owner = User::where('email', 'mama@shop.test')->first();
        $this->assertEquals($b->id, $owner->business_id);
        $this->assertTrue($owner->hasRole('owner'));
        $this->assertFalse((bool) $owner->is_super_admin);

        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_required_kyc_fields_and_unique_login(): void
    {
        $this->superAdmin();

        Volt::test('admin.businesses')->set('name', 'Only A Name')->call('save')
    ->assertHasErrors([
        'category', 'phone', 'email', 'address', 'county', 'owner_name',
        'owner_id_number', 'owner_phone', 'owner_dob', 'login_email', 'password',
    ]);

        $this->create()->assertHasNoErrors();
        $this->create(['name' => 'Second'])->assertHasErrors('login_email');
        $this->assertEquals(1, Business::count());
    }

    public function test_a_pending_merchant_cannot_log_in_until_activated(): void
    {
        $this->superAdmin();
        $this->create(['activate_now' => false]);

        $b = Business::first();
        $owner = User::where('email', 'mama@shop.test')->first();
        $this->assertEquals('pending', $b->status);

        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('login'));

        $this->superAdmin();
        Volt::test('admin.businesses')->call('activate', $b->id)->assertHasNoErrors();
        $this->assertEquals('active', $b->fresh()->status);

        $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    }

    public function test_suspending_needs_a_reason_and_locks_the_shop_out(): void
    {
        $this->superAdmin();
        $this->create();
        $b = Business::first();
        $owner = User::where('email', 'mama@shop.test')->first();

        Volt::test('admin.businesses')
            ->call('startReview', $b->id, 'suspend')->set('reason', '')->call('confirmReview')
            ->assertHasErrors('reason');
        $this->assertEquals('active', $b->fresh()->status);

        Volt::test('admin.businesses')
            ->call('startReview', $b->id, 'suspend')->set('reason', 'Unpaid for October')->call('confirmReview')
            ->assertHasNoErrors();

        $this->assertEquals('suspended', $b->fresh()->status);
        $this->assertEquals('Unpaid for October', $b->fresh()->status_reason);

        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('login'));

        $this->superAdmin();
        Volt::test('admin.businesses')->call('activate', $b->id);
        $this->assertEquals('active', $b->fresh()->status);
        $this->assertNull($b->fresh()->status_reason);
    }

    public function test_invalid_moves_are_refused(): void
    {
        $this->superAdmin();
        $this->create();
        $b = Business::first(); // already active

        Volt::test('admin.businesses')->call('activate', $b->id)->assertHasErrors('action');
        Volt::test('admin.businesses')->call('startReview', $b->id, 'reject')->assertHasErrors('action');
        $this->assertEquals('active', $b->fresh()->status);
    }

    public function test_kyc_details_can_be_edited(): void
    {
        $this->superAdmin();
        $this->create();
        $b = Business::first();

        Volt::test('admin.businesses')->call('edit', $b->id)
    ->set('county', 'Kiambu')->call('save')->assertHasNoErrors();

$this->assertEquals('Kiambu', $b->fresh()->county);
        $this->assertEquals(1, User::where('business_id', $b->id)->count()); // no second owner
    }

    public function test_an_older_merchant_with_blanks_must_be_completed_when_edited(): void
{
    $this->superAdmin();
    $old = Business::create(['name' => 'Old Shop', 'phone' => '0700', 'owner_name' => 'Old', 'owner_id_number' => '1']);

    Volt::test('admin.businesses')->call('edit', $old->id)->call('save')
        ->assertHasErrors(['category', 'email', 'address', 'county', 'owner_phone', 'owner_dob']);
}
}
