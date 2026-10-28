<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\BusinessSetup;
use App\Services\ExpenseService;
use App\Services\ReportService;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ExpensesTest extends TestCase
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

    private function cat(): int
    {
        return ExpenseCategory::where('name', 'Rent')->first()->id;
    }

    private function spend(float $amount = 500, ?string $date = null, string $desc = 'October rent')
{
    return ExpenseService::record($this->cat(), $amount, $date ?? today()->toDateString(), null, null, $desc);
}

    public function test_everyone_can_record_an_expense(): void
    {
        foreach (['cashier', 'manager', 'owner'] as $role) {
            $user = $this->actAs($role);
            $this->get('/expenses')->assertOk();
            $e = $this->spend(250);

            $this->assertEquals($user->business_id, $e->business_id);
            $this->assertEquals('recorded', $e->status);
            $this->assertEquals('Rent', $e->category_name);
        }
    }

    public function test_validation_rules(): void
    {
        $this->actAs('manager');

        $bad = [
            [0, today()->toDateString(), 'x', 'amount'],
            [100, today()->addDay()->toDateString(), 'x', 'date'],
            [100, 'nonsense', 'x', 'date'],
            [100, today()->toDateString(), '   ', 'description'],
        ];
        foreach ($bad as [$amount, $date, $desc, $field]) {
            try {
                $this->spend($amount, $date, $desc);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertEquals(0, Expense::count());
    }

    public function test_a_cashier_cannot_backdate_but_a_manager_can(): void
    {
        $this->actAs('cashier');
        $this->spend(100, today()->subDay()->toDateString()); // yesterday is fine

        try {
            $this->spend(100, today()->subDays(5)->toDateString());
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        $this->actAs('manager');
        $this->spend(100, today()->subDays(5)->toDateString());
        $this->assertEquals(1, Expense::count());
    }

    public function test_disabled_and_foreign_categories_are_refused(): void
    {
        $this->actAs('manager');
        $theirs = $this->cat();

        $this->actAs('manager'); // second shop
        try {
            ExpenseService::record($theirs, 100, today()->toDateString(), null, null, 'x');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->errors());
        }

        ExpenseCategory::first()->disable();
        $this->expectException(ValidationException::class);
        ExpenseService::record(ExpenseCategory::withoutGlobalScopes()->where('is_active', false)->first()->id,
            100, today()->toDateString(), null, null, 'x');
    }

    public function test_a_cashier_sees_only_their_own_expenses_but_a_manager_sees_all(): void
    {
        $a = $this->actAs('cashier');
        $this->spend(100, null, 'Cashier A bought tape');

        $b = User::factory()->create(['is_active' => true]);
        $b->business_id = $a->business_id;
        $b->save();
        $b->assignRole('cashier');
        $this->actingAs($b->fresh());
        Volt::test('expenses.manage')->assertDontSee('Cashier A bought tape');

        $m = User::factory()->create(['is_active' => true]);
        $m->business_id = $a->business_id;
        $m->save();
        $m->assignRole('manager');
        $this->actingAs($m->fresh());
        Volt::test('expenses.manage')->assertSee('Cashier A bought tape');
    }

    public function test_voiding_needs_permission_and_a_reason_and_works_once(): void
    {
        $this->actAs('cashier');
        $e = $this->spend();

        try {
            ExpenseService::void($e, 'Trying');
            $this->fail('Cashiers must not void.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
            $this->assertTrue(true);
        }

        $manager = $this->makeActiveUser();
        $manager->assignRole('manager');
        $manager->business_id = $e->business_id;
        $manager->save();
        $this->actingAs($manager->fresh());

        try {
            ExpenseService::void($e, '  ');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason', $ex->errors());
        }

        ExpenseService::void($e, 'Entered twice');
        $this->assertEquals('voided', $e->fresh()->status);

        $this->expectException(ValidationException::class);
        ExpenseService::void($e, 'Again');
    }

    public function test_expenses_cannot_be_deleted(): void
    {
        $this->actAs('manager');
        $e = $this->spend();

        $this->expectException(\LogicException::class);
        $e->delete();
    }

    public function test_expenses_are_private_to_each_shop(): void
    {
        $this->actAs('manager');
        $this->spend(100, null, 'Secret rent');

        $this->actAs('manager');
        $this->assertCount(0, Expense::all());
        Volt::test('expenses.manage')->assertDontSee('Secret rent');
    }

    public function test_net_profit_subtracts_expenses_and_ignores_voided_ones(): void
    {
        $this->actAs('manager');

        $p = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 200, 'cost_price' => 150, 'stock_quantity' => 50]);
        $cash = PaymentMethod::where('name', 'Cash')->first();
        SaleService::complete([['variant_id' => $v->id, 'quantity' => 3]], null, [['method_id' => $cash->id, 'amount' => 600]]);
        // revenue 600, cost 450, gross profit 150

        $this->spend(50);
        $mistake = $this->spend(1000, null, 'Entered by mistake');
        ExpenseService::void($mistake, 'Wrong amount');

        [$f, $t] = ReportService::range(today()->toDateString(), today()->toDateString());
        $d = ReportService::profit($f, $t);

        $this->assertEquals(150, round($d['profit'], 2));
        $this->assertEquals(50, round($d['expenses'], 2));
        $this->assertEquals(100, round($d['netProfit'], 2));

        Volt::test('reports.view')->call('setTab', 'profit')->assertSee('100.00');
    }

    public function test_the_expenses_report_exports_to_csv(): void
    {
        $this->actAs('manager');
        $this->spend(500, null, 'October rent');

        $csv = $this->get(route('reports.export', ['report' => 'expenses']))->streamedContent();

        $this->assertStringContainsString('October rent', $csv);
        $this->assertStringContainsString('500', $csv);
    }

    public function test_expense_categories_are_managed_in_setup_by_those_who_manage_categories(): void
    {
        $this->actAs('manager');
        Volt::test('expense-categories.manage')->set('name', 'Insurance')->call('save')->assertHasNoErrors();
        $this->assertTrue(ExpenseCategory::where('name', 'Insurance')->exists());

        Volt::test('expense-categories.manage')->set('name', 'Insurance')->call('save')->assertHasErrors('name');

        $this->actAs('cashier');
        Volt::test('expense-categories.manage')->set('name', 'Sneaky')->call('save')->assertForbidden();
    }
}
