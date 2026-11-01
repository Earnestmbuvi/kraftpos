<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_notes_list_items_without_prices_for_sales_and_invoices(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = $this->makeActiveUser();
        $u->assignRole('owner');
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        $v = Product::create(['name' => 'Diffuser', 'type' => 'single'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 777, 'stock_quantity' => 20]);
        $sale = SaleService::complete([['variant_id' => $v->id, 'quantity' => 3]], null,
            [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => 2331]]);
        $c = Customer::create(['name' => 'Jane', 'phone' => '0711111111', 'address' => 'Isekete']);
        $inv = InvoiceService::issue($c->id, null, null, [['variant_id' => $v->id, 'quantity' => 2]], null);

        $this->get(route('sales.delivery-note', $sale))->assertOk()->assertSee('DELIVERY NOTE')->assertSee('Diffuser')->assertDontSee('777.00');
        $this->get(route('invoices.delivery-note', $inv))->assertOk()->assertSee('Jane')->assertDontSee('777.00');
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Delivery note')->assertSee('COMPLETE');
    }
}
