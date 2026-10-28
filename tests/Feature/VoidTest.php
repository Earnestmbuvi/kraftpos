<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VoidTest extends TestCase
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

    private function sell(int $qty = 2)
    {
        $product = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);
        $v = $product->variants()->create([
            'name' => 'Default', 'selling_price' => 200, 'stock_quantity' => 10,
        ]);
        $cash = PaymentMethod::where('name', 'Cash')->first();

        $sale = SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => $qty]], null,
            [['method_id' => $cash->id, 'amount' => 200 * $qty]]
        );

        return [$sale, $v];
    }

    public function test_voiding_restores_stock_and_marks_the_sale(): void
    {
        $manager = $this->actAs('manager');
        [$sale, $v] = $this->sell(3);
        $this->assertEquals(7, $v->fresh()->stock_quantity);

        SaleService::void($sale, 'Wrong item');

        $sale->refresh();
        $this->assertEquals('voided', $sale->status);
        $this->assertEquals('Wrong item', $sale->void_reason);
        $this->assertEquals($manager->id, $sale->voided_by);
        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals(1, StockMovement::where('type', 'return')->count());
        $this->assertEquals(1, Sale::count()); // nothing deleted
    }

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        $this->actAs('manager');
        [$sale, $v] = $this->sell(2);

        SaleService::void($sale, 'Mistake');

        try {
            SaleService::void($sale, 'Again');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertEquals(10, $v->fresh()->stock_quantity); // restored once only
    }

    public function test_a_reason_is_required(): void
    {
        $this->actAs('manager');
        [$sale] = $this->sell();

        $this->expectException(ValidationException::class);
        SaleService::void($sale, '   ');
    }

    public function test_cashier_cannot_void(): void
    {
        $this->actAs('cashier');
        [$sale] = $this->sell();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        SaleService::void($sale, 'Trying');
    }

    public function test_cannot_void_another_businesss_sale(): void
    {
        $this->actAs('manager');
        [$sale] = $this->sell();

        $this->actAs('manager'); // second business
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        SaleService::void($sale, 'Not mine');
    }

   public function test_cashier_sees_only_their_own_sales_in_the_list(): void
{
    $a = $this->actAs('cashier');
    $this->sell();

    $b = User::factory()->create(['is_active' => true]);
    $b->business_id = $a->business_id;
    $b->save();
    $b->assignRole('cashier');

    // The seller sees their sale
    Volt::test('sales.list')->assertSee('#0001');

    // Another cashier in the same shop does not
    $this->actingAs($b->fresh());
    Volt::test('sales.list')->assertDontSee('#0001');
}

    public function test_manager_can_void_from_the_list(): void
    {
        $this->actAs('manager');
        [$sale, $v] = $this->sell(2);

        Volt::test('sales.list')
            ->call('startVoid', $sale->id)
            ->set('reason', 'Customer changed mind')
            ->call('confirmVoid')
            ->assertHasNoErrors();

        $this->assertEquals('voided', $sale->fresh()->status);
        $this->assertEquals(10, $v->fresh()->stock_quantity);
    }
}
