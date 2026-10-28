<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\BatchService;
use App\Services\BranchService;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use App\Services\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BranchIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role, array $business = [])
    {
        $user = $this->makeActiveUser();
        $user->assignRole($role);
        if ($business) {
            $user->business->forceFill($business)->save();
        }
        BusinessSetup::run($user->business);
        $this->actingAs($user);

        return $user;
    }

    private function variant(string $name, int $stock, array $product = [])
    {
        $p = Product::create(['name' => $name, 'type' => 'single'] + $product);

        return $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => $stock]);
    }

    private function sell($v)
    {
        return SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 1]], null,
            [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => 100]]
        );
    }

    public function test_the_main_branch_can_be_renamed(): void
    {
        $this->actAs('owner');
        $main = BranchService::mainId();

        BranchService::rename($main, 'Karen');

        $this->assertEquals('Karen', Branch::find($main)->name);
    }

    public function test_the_adjustment_screen_shows_and_changes_the_branchs_own_stock(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $bread = $this->variant('Bread', 10);
        $west = BranchService::create('Westlands');
        session(['branch_id' => $west->id]);

        $this->assertEquals(0, $bread->fresh()->branch_stock);

        Volt::test('stock.manage')->call('select', $bread->id)
            ->set('quantity', '5')->call('save')->assertHasNoErrors();

        $bread = $bread->fresh();
        $this->assertEquals(5, $bread->branch_stock);
        $this->assertEquals(15, $bread->stock_quantity);
        $this->assertEquals(10, BranchService::quantity($bread, BranchService::mainId()));
    }

    public function test_batches_belong_to_a_branch_and_transfers_move_them(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $milk = $this->variant('Milk', 0, ['tracks_batches' => true]);
        $west = BranchService::create('Westlands');
        $exp = today()->addDays(20)->toDateString();

        BatchService::receive($milk, 10, 'M1', $exp);        // at main
        session(['branch_id' => $west->id]);
        $this->assertEquals(0, BatchService::sellable($milk->fresh()));

        BatchService::receive($milk, 4, 'W1', $exp);          // at Westlands
        $this->assertEquals(4, BatchService::sellable($milk->fresh()));

        session(['branch_id' => BranchService::mainId()]);
        TransferService::create($west->id, [['variant_id' => $milk->id, 'quantity' => 3]], null);

        $this->assertEquals(7, BatchService::sellable($milk->fresh(), BranchService::mainId()));
        $this->assertEquals(7, BatchService::sellable($milk->fresh(), $west->id));
        $this->assertEquals(14, $milk->fresh()->stock_quantity);
    }

    public function test_sales_are_separate_per_branch_and_all_branches_shows_them_together(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $v = $this->variant('Sugar', 50);
        $west = BranchService::create('Westlands');

        $this->sell($v);                                   // main
        session(['branch_id' => $west->id]);
        $this->sell($v);                                   // Westlands

        $this->assertEquals(1, Sale::count());
        session(['branch_id' => 'all']);
        $this->assertEquals(2, Sale::count());
        session(['branch_id' => BranchService::mainId()]);
        $this->assertEquals(1, Sale::count());
    }

    public function test_setup_pages_open_one_by_one_and_follow_permissions(): void
    {
        $this->actAs('manager');
        $this->get('/setup')->assertOk();
        $this->get('/setup/units')->assertOk();
        $this->get('/setup/branches')->assertNotFound();

        $this->actAs('owner');
        $this->get('/setup/branches')->assertOk();
        $this->get('/setup/tax')->assertOk();

        $this->actAs('cashier');
        $this->get('/setup')->assertForbidden();
    }
    public function test_a_manager_mapped_to_one_branch_cannot_open_another(): void
{
    $owner = $this->actAs('owner', ['expected_branches' => 3]);
    $west = BranchService::create('Westlands');
    $karen = BranchService::create('Karen');

    $manager = $this->makeActiveUser();
    $manager->business_id = $owner->business_id;
    $manager->save();
    $manager->assignRole('manager');
    \Illuminate\Support\Facades\DB::table('branch_user')->insert(['user_id' => $manager->id, 'branch_id' => $west->id]);
    $this->actingAs($manager->fresh());

    $this->assertEquals([$west->id], BranchService::allowed());
    $this->assertFalse(BranchService::unrestricted());

    session(['branch_id' => $karen->id]); // tampered
    $this->assertEquals($west->id, BranchService::currentId());

    Volt::test('layout.navigation')->call('switchBranch', (string) $karen->id)->assertForbidden();
    Volt::test('layout.navigation')->call('switchBranch', 'all')->assertForbidden();
    Volt::test('layout.navigation')->call('switchBranch', (string) $west->id)->assertRedirect();
}

public function test_a_manager_mapped_to_two_branches_can_use_both_but_not_all(): void
{
    $owner = $this->actAs('owner', ['expected_branches' => 3]);
    $west = BranchService::create('Westlands');
    $karen = BranchService::create('Karen');

    $manager = $this->makeActiveUser();
    $manager->business_id = $owner->business_id;
    $manager->save();
    $manager->assignRole('manager');
    foreach ([$west->id, $karen->id] as $b) {
        \Illuminate\Support\Facades\DB::table('branch_user')->insert(['user_id' => $manager->id, 'branch_id' => $b]);
    }
    $this->actingAs($manager->fresh());

    session(['branch_id' => $karen->id]);
    $this->assertEquals($karen->id, BranchService::currentId());
    $this->assertNotContains(BranchService::mainId(), BranchService::allowed());
}

public function test_staff_can_be_mapped_from_the_staff_screen(): void
{
    $this->actAs('owner', ['expected_branches' => 2]);
    $west = BranchService::create('Westlands');

    Volt::test('users.manage')->call('openCreate')
        ->set('name', 'Wanjiru')->set('email', 'w@shop.test')->set('password', 'secret-pass-1')
        ->set('role', 'manager')->set('branchIds', [(string) $west->id])
        ->call('save')->assertHasNoErrors();

    $u = \App\Models\User::where('email', 'w@shop.test')->first();
    $this->assertEquals([$west->id], \Illuminate\Support\Facades\DB::table('branch_user')->where('user_id', $u->id)->pluck('branch_id')->all());
}

public function test_a_cashier_only_sees_items_stocked_at_their_branch(): void
{
    $owner = $this->actAs('owner', ['expected_branches' => 2]);
    $bread = $this->variant('Bread', 10);
    $west = BranchService::create('Westlands');

    $cashier = $this->makeActiveUser();
    $cashier->business_id = $owner->business_id;
    $cashier->branch_id = $west->id;
    $cashier->save();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier->fresh());

    Volt::test('sell.till')->assertSee('Bread');

    $this->actingAs($owner->fresh());
    session(['branch_id' => BranchService::mainId()]);
    TransferService::create($west->id, [['variant_id' => $bread->id, 'quantity' => 3]], null);

    $this->actingAs($cashier->fresh());
    BranchService::forget();
    Volt::test('sell.till')->assertSee('Bread');
}

public function test_expenses_quotations_and_movements_follow_the_selected_branch(): void
{
    $this->actAs('owner', ['expected_branches' => 2]);
    $west = BranchService::create('Westlands');
    $cat = \App\Models\ExpenseCategory::where('name', 'Rent')->first()->id;

    \App\Services\ExpenseService::record($cat, 100, today()->toDateString(), null, null, 'Main rent');
    session(['branch_id' => $west->id]);
    \App\Services\ExpenseService::record($cat, 50, today()->toDateString(), null, null, 'West rent');

    $this->assertEquals(1, \App\Models\Expense::count());
    $this->assertEquals('West rent', \App\Models\Expense::first()->description);

    session(['branch_id' => 'all']);
    $this->assertEquals(2, \App\Models\Expense::count());
}
}
