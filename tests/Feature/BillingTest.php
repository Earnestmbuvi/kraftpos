<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\BillingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BillingTest extends TestCase
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

    private function shop(?string $ends, string $status = 'active', bool $billing = false): Business
    {
        $user = $this->makeActiveUser();
        $user->assignRole('owner');
        $b = $user->business;
        $b->subscription_ends_at = $ends;
        $b->status = $status;
        $b->billing_suspended = $billing;
        $b->save();

        return $b->fresh();
    }

    private function pay(Business $b, int $months = 1, float $amount = 1000, string $method = 'Cash')
    {
        return BillingService::recordPayment($b, $months, $amount, $method, null, null);
    }

    public function test_a_payment_extends_from_the_current_end_date(): void
    {
        $this->superAdmin();
        $b = $this->shop(today()->addDays(10)->toDateString());

        $this->pay($b, 2);

        $this->assertEquals(today()->addDays(10)->addMonthsNoOverflow(2)->toDateString(), $b->fresh()->subscription_ends_at->toDateString());
        $this->assertEquals(1, SubscriptionPayment::count());
    }

    public function test_a_late_payment_extends_from_today(): void
    {
        $this->superAdmin();
        $b = $this->shop(today()->subDays(20)->toDateString());

        $this->pay($b, 1);

        $this->assertEquals(today()->addMonthNoOverflow()->toDateString(), $b->fresh()->subscription_ends_at->toDateString());
    }

    public function test_paying_reactivates_a_billing_suspension_but_not_a_manual_one(): void
    {
        $this->superAdmin();

        $auto = $this->shop(today()->subDays(30)->toDateString(), 'suspended', true);
        $this->pay($auto);
        $this->assertEquals('active', $auto->fresh()->status);
        $this->assertFalse((bool) $auto->fresh()->billing_suspended);

        $manual = $this->shop(today()->subDays(30)->toDateString(), 'suspended', false);
        $this->pay($manual);
        $this->assertEquals('suspended', $manual->fresh()->status);
    }

    public function test_the_command_suspends_only_after_the_grace_period(): void
    {
        $inGrace = $this->shop(today()->subDays(3)->toDateString());
        $overdue = $this->shop(today()->subDays(6)->toDateString());
        $noBilling = $this->shop(null);
        $notActive = $this->shop(today()->subDays(30)->toDateString(), 'pending');

        $this->artisan('billing:suspend-overdue')->assertExitCode(0);

        $this->assertEquals('active', $inGrace->fresh()->status);
        $this->assertEquals('suspended', $overdue->fresh()->status);
        $this->assertTrue((bool) $overdue->fresh()->billing_suspended);
        $this->assertStringContainsString('Subscription expired', $overdue->fresh()->status_reason);
        $this->assertEquals('active', $noBilling->fresh()->status);
        $this->assertEquals('pending', $notActive->fresh()->status);
    }

    public function test_a_suspended_merchant_is_locked_out_then_back_in_after_paying(): void
    {
        $b = $this->shop(today()->subDays(10)->toDateString());
        $owner = User::where('business_id', $b->id)->first();

        $this->artisan('billing:suspend-overdue');
        $this->actingAs($owner->fresh())->get('/dashboard')->assertRedirect(route('login'));

        $this->superAdmin();
        $this->pay($b->fresh());

        $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    }

    public function test_only_the_super_admin_can_record_payments(): void
    {
        $b = $this->shop(today()->toDateString());
        $owner = User::where('business_id', $b->id)->first();
        $this->actingAs($owner);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->pay($b);
    }

    public function test_validation_and_references(): void
    {
        $this->superAdmin();
        $b = $this->shop(today()->toDateString());

        foreach ([[0, 100, 'Cash'], [25, 100, 'Cash'], [1, -5, 'Cash'], [1, 100, 'Bitcoin']] as [$m, $a, $method]) {
            try {
                $this->pay($b, $m, $a, $method);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // M-Pesa money needs a reference; a complimentary extension does not
        try {
            $this->pay($b, 1, 1000, 'M-Pesa');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }

        $this->pay($b, 1, 0, 'Complimentary');
        $this->assertEquals(1, SubscriptionPayment::count());
    }

    public function test_payment_records_cannot_be_changed_or_deleted(): void
    {
        $this->superAdmin();
        $p = $this->pay($this->shop(today()->toDateString()));

        $this->expectException(\LogicException::class);
        $p->delete();
    }

    public function test_the_billing_screen_works_for_the_super_admin_only(): void
    {
        $b = $this->shop(today()->toDateString());
        $owner = User::where('business_id', $b->id)->first();
        $this->actingAs($owner)->get('/admin/billing')->assertForbidden();

        $this->superAdmin();
        $this->get('/admin/billing')->assertOk();

        Volt::test('admin.billing')
            ->call('openPayment', $b->id)
            ->set('months', 3)->set('amount', '3000')->set('method', 'Cash')
            ->call('savePayment')->assertHasNoErrors();

        $this->assertEquals(3, SubscriptionPayment::first()->months);

        Volt::test('admin.billing')
            ->call('openTerms', $b->id)->set('monthly_fee', '1500')->set('ends_at', '')
            ->call('saveTerms')->assertHasNoErrors();

        $this->assertNull($b->fresh()->subscription_ends_at);
        $this->assertEquals(1500, (float) $b->fresh()->monthly_fee);
    }

    public function test_the_merchant_sees_a_banner_near_the_end_and_none_when_paid_up(): void
    {
        $b = $this->shop(today()->addDays(3)->toDateString());
        $owner = User::where('business_id', $b->id)->first();

        $this->actingAs($owner)->get('/dashboard')->assertSee('subscription ends on');

        $b->subscription_ends_at = today()->addMonths(2);
        $b->save();
        $this->actingAs($owner->fresh())->get('/dashboard')->assertDontSee('subscription ends on');
    }

    public function test_new_merchants_get_a_trial(): void
    {
        $this->superAdmin();

        $c = Volt::test('admin.businesses');
        foreach ([
            'name' => 'Trial Shop', 'category' => 'Grocery', 'phone' => '0711', 'email' => 't@b.test',
            'address' => 'Street', 'county' => 'Nairobi', 'owner_name' => 'T', 'owner_id_number' => '1',
            'owner_phone' => '0722', 'owner_dob' => '1990-01-01', 'login_email' => 't@shop.test', 'password' => 'temp-pass-1',
        ] as $k => $v) {
            $c->set($k, $v);
        }
        $c->call('save')->assertHasNoErrors();

        $this->assertEquals(
            today()->addDays(14)->toDateString(),
            Business::where('name', 'Trial Shop')->first()->subscription_ends_at->toDateString()
        );
    }
}
