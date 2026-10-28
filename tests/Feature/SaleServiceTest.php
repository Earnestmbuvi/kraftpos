<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actAs(string $role = 'cashier', array $business = [])
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

    private function variant(int $stock = 10, float $price = 200)
    {
        $product = Product::create(['name' => 'Sugar 1kg', 'type' => 'single']);

        return $product->variants()->create([
            'name' => 'Default', 'selling_price' => $price, 'cost_price' => 150, 'stock_quantity' => $stock,
        ]);
    }

    private function cash(float $amount): array
    {
        return [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => $amount]];
    }

    public function test_simple_sale_totals_stock_and_receipt(): void
    {
        $this->actAs();
        $v = $this->variant(10, 200);

        $sale = SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 3]], null, $this->cash(1000)
        );

        $this->assertEquals(600, $sale->total);
        $this->assertEquals(400, $sale->change_given);
        $this->assertEquals('0001', $sale->receipt_label);
        $this->assertEquals(7, $v->fresh()->stock_quantity);

        $m = StockMovement::where('type', 'sale')->first();
        $this->assertEquals(-3, $m->quantity_change);
        $this->assertEquals('Sugar 1kg', $sale->items->first()->product_name);
    }

    public function test_line_and_cart_discounts_then_exclusive_tax(): void
    {
        $this->actAs('manager', ['tax_rate' => 16, 'tax_inclusive' => false]);

        $v = $this->variant(10, 200);

        // 3 x 200 = 600, line 10% off = 540, cart 40 off = 500, tax 16% = 80, total 580
        $sale = SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 3, 'discount_type' => 'percent', 'discount_value' => 10]],
            ['type' => 'fixed', 'value' => 40],
            $this->cash(600)
        );

        $this->assertEquals(540, $sale->subtotal);
        $this->assertEquals(40, $sale->discount_amount);
        $this->assertEquals(80, $sale->tax_amount);
        $this->assertEquals(580, $sale->total);
        $this->assertEquals(20, $sale->change_given);
    }

    public function test_inclusive_tax_does_not_change_the_total(): void
    {
        $this->actAs('cashier', ['tax_rate' => 16, 'tax_inclusive' => true]);
        $v = $this->variant(10, 500);

        $sale = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(500));

        $this->assertEquals(500, $sale->total);
        $this->assertEquals(68.97, (float) $sale->tax_amount);
    }

    public function test_stock_can_go_negative_on_a_sale(): void
    {
        $this->actAs();
        $v = $this->variant(2, 100);

        SaleService::complete([['variant_id' => $v->id, 'quantity' => 5]], null, $this->cash(500));

        $this->assertEquals(-3, $v->fresh()->stock_quantity);
    }

    public function test_underpayment_leaves_no_trace(): void
    {
        $user = $this->actAs();
        $v = $this->variant(10, 200);

        try {
            SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(50));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        $this->assertEquals(0, Sale::count());
        $this->assertEquals(10, $v->fresh()->stock_quantity);
        $this->assertEquals(0, $user->business->fresh()->last_receipt_number);
    }

    public function test_change_cannot_come_from_mobile_money(): void
    {
        $this->actAs();
        $v = $this->variant(10, 200);
        $mpesa = PaymentMethod::where('name', 'M-Pesa')->first();

        $this->expectException(ValidationException::class);
        SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 1]], null,
            [['method_id' => $mpesa->id, 'amount' => 300, 'reference' => 'QWE123']]
        );
    }

    public function test_split_payment_with_mpesa_and_cash(): void
    {
        $this->actAs();
        $v = $this->variant(10, 200);
        $mpesa = PaymentMethod::where('name', 'M-Pesa')->first();
        $cash = PaymentMethod::where('name', 'Cash')->first();

        $sale = SaleService::complete(
            [['variant_id' => $v->id, 'quantity' => 2]], null,
            [
                ['method_id' => $mpesa->id, 'amount' => 300, 'reference' => 'QWE123'],
                ['method_id' => $cash->id, 'amount' => 100],
            ]
        );

        $this->assertCount(2, $sale->payments);
        $this->assertEquals(0, $sale->change_given);
    }

    public function test_receipt_numbers_are_per_business(): void
    {
        $this->actAs();
        $a = $this->variant(10);
        $s1 = SaleService::complete([['variant_id' => $a->id, 'quantity' => 1]], null, $this->cash(200));

        $this->actAs();
        $b = $this->variant(10);
        $s2 = SaleService::complete([['variant_id' => $b->id, 'quantity' => 1]], null, $this->cash(200));

        $this->assertEquals(1, $s1->receipt_number);
        $this->assertEquals(1, $s2->receipt_number);
        $this->assertEquals(1, Sale::count()); // the second shop only sees its own
    }

    public function test_cannot_sell_another_businesss_item_or_a_disabled_one(): void
    {
        $this->actAs();
        $other = $this->variant(10);

        $this->actAs();
        try {
            SaleService::complete([['variant_id' => $other->id, 'quantity' => 1]], null, $this->cash(500));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $mine = $this->variant(10);
        $mine->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        SaleService::complete([['variant_id' => $mine->id, 'quantity' => 1]], null, $this->cash(500));
    }

    public function test_sales_and_their_items_cannot_be_deleted(): void
    {
        $this->actAs();
        $v = $this->variant(10);
        $sale = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, $this->cash(200));

        try {
            $sale->items->first()->delete();
            $this->fail('Item delete should be blocked.');
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        }

        $this->expectException(\LogicException::class);
        $sale->delete();
    }
}
