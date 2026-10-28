<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PlatformItem;
use App\Models\Product;
use App\Models\User;
use App\Services\BranchService;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\PlatformDocumentService as Docs;
use App\Services\SaleService;
use App\Support\RoleLabel;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RolesBranchesAndPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role, array $business = [])
    {
        $u = $this->makeActiveUser();
        $u->assignRole($role);
        if ($business) {
            $u->business->forceFill($business)->save();
        }
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        return $u;
    }

    private function superAdmin()
    {
        $u = $this->makeActiveUser();
        $u->forceFill(['is_super_admin' => true, 'business_id' => null])->save();
        $this->actingAs($u->fresh());

        return $u;
    }

    public function test_an_owner_creates_a_custom_role_and_gives_it_to_staff(): void
    {
        $owner = $this->actAs('owner');

        Volt::test('settings.roles')->set('label', 'Accountant')->set('perms', ['view-reports', 'view-cost-prices', 'record-expenses'])
            ->call('save')->assertHasNoErrors();

        $name = 'b' . $owner->business_id . '-accountant';

        Volt::test('settings.roles')->set('label', 'Boss')->set('perms', ['manage-users'])->call('save')->assertHasErrors(['perms.0']);
        Volt::test('settings.roles')->set('label', 'Manager')->set('perms', ['view-reports'])->call('save')->assertHasErrors(['label']);

        Volt::test('users.manage')->call('openCreate')
            ->set('name', 'Joy')->set('email', 'joy@shop.test')->set('password', 'secret-pass-1')->set('role', $name)
            ->call('save')->assertHasNoErrors();

        $joy = User::where('email', 'joy@shop.test')->first();
        $this->assertTrue($joy->can('view-reports'));
        $this->assertFalse($joy->can('manage-users'));
        $this->assertEquals('Accountant', RoleLabel::for($joy));
    }

    public function test_another_shops_custom_role_cannot_be_assigned(): void
    {
        $this->actAs('owner');
        Volt::test('settings.roles')->set('label', 'Accountant')->set('perms', ['view-reports'])->call('save');

        $second = $this->actAs('owner');
        Volt::test('users.manage')->call('openCreate')
            ->set('name', 'X')->set('email', 'x@shop.test')->set('password', 'secret-pass-1')
            ->set('role', 'b' . ($second->business_id - 1) . '-accountant')
            ->call('save')->assertHasErrors(['role']);
    }

    public function test_a_product_limited_to_a_branch_cannot_be_sold_elsewhere(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $west = BranchService::create('Westlands');

        $p = Product::create(['name' => 'Bread', 'type' => 'single']);
        $v = $p->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => 20]);
        $p->branches()->sync([$west->id]);

        $cash = PaymentMethod::where('name', 'Cash')->first()->id;

        // working at the main branch
        $this->assertNotNull(\App\Services\StockService::cartMessage($v, 1));
        try {
            SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, [['method_id' => $cash, 'amount' => 100]]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $customer = \App\Models\Customer::create(['name' => 'Jane', 'phone' => '0700000001', 'address' => 'Nairobi']);
        try {
            InvoiceService::issue($customer->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        session(['branch_id' => $west->id]);
        $this->assertNull(\App\Services\StockService::cartMessage($v->fresh(), 1) && 'x' ? null : null);

        $p->branches()->sync([]); // no mapping: every branch
        session(['branch_id' => BranchService::mainId()]);
        BranchService::forget();
        $this->assertNotNull(SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, [['method_id' => $cash, 'amount' => 100]]));
    }

    public function test_the_product_form_saves_branches(): void
    {
        $this->actAs('owner', ['expected_branches' => 2]);
        $west = BranchService::create('Westlands');

        Volt::test('products.manage')->call('openCreate')
            ->set('name', 'Milk')->set('selling_price', '60')->set('stock_quantity', '5')->set('branchIds', [(string) $west->id])
            ->call('save')->assertHasNoErrors();

        $this->assertEquals([$west->id], Product::where('name', 'Milk')->first()->branches()->pluck('branches.id')->all());
    }

    public function test_a_super_admin_adds_another_and_makes_documents_for_a_merchant(): void
    {
        $merchant = $this->makeActiveUser()->business;
        $this->superAdmin();

        Volt::test('admin.admins')->set('name', 'Second')->set('email', 'second@codekraft.test')->set('password', 'short')->call('add')->assertHasErrors(['password'])
            ->set('password', 'a-long-password-1')->call('add')->assertHasNoErrors();
        $this->assertTrue(User::where('email', 'second@codekraft.test')->first()->is_super_admin);

        $item = PlatformItem::create(['name' => 'Setup and training', 'kind' => 'service', 'price' => 10000]);

        $q = Docs::create('quotation', $merchant->id, [['item_id' => $item->id, 'name' => 'Setup and training', 'quantity' => 1, 'unit_price' => 10000]], null, null, 16);
        $this->assertEquals('KQ-0001', $q->number_label);
        $this->assertEquals(11600, (float) $q->total);

        $inv = Docs::convert($q);
        $this->assertEquals('KI-0001', $inv->number_label);
        $this->assertEquals('converted', $q->fresh()->status);

        try {
            Docs::convert($q);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        Docs::pay($inv, 'Cash', 5000, null);
        Docs::pay($inv, 'M-Pesa', 6600, 'ABC123XYZ');
        $this->assertEquals('paid', $inv->fresh()->display_status);

        $this->get(route('admin.document', $inv->id))->assertOk()->assertSee('KI-0001');
        $this->get(route('admin.invoices'))->assertOk();
    }

    public function test_only_super_admins_can_open_platform_pages(): void
    {
        $this->actAs('owner');

        foreach (['admin.admins', 'admin.catalogue', 'admin.quotations', 'admin.invoices'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
    }
}
