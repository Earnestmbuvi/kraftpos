<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function owner()
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = $this->makeActiveUser();
        $u->assignRole('owner');
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        return $u;
    }

    public function test_zones_are_managed_and_a_sale_carries_the_delivery_charge(): void
    {
        $this->owner();

        Volt::test('settings.delivery-zones')->set('name', 'Westlands')->set('fee', '300')->call('save')->assertHasNoErrors();
        Volt::test('settings.delivery-zones')->set('name', 'Westlands')->set('fee', '100')->call('save')->assertHasErrors(['name']);
        $zone = DeliveryZone::first();

        $v = Product::create(['name' => 'Diffuser', 'type' => 'single'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 1000, 'stock_quantity' => 20]);
        $cash = PaymentMethod::where('name', 'Cash')->first()->id;

        $sale = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, [['method_id' => $cash, 'amount' => 1300]],
            null, deliveryCharge: 300, deliveryZoneId: $zone->id);

        $this->assertEquals(300, (float) $sale->delivery_charge);
        $this->assertEquals('Delivery: Westlands', $sale->delivery_label);
        $this->assertEquals(1300, (float) $sale->total);

        $custom = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, [['method_id' => $cash, 'amount' => 1150]],
            null, deliveryCharge: 150);
        $this->assertEquals('Delivery', $custom->delivery_label);

        $this->expectException(ValidationException::class);
        SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null, [['method_id' => $cash, 'amount' => 900]],
            null, deliveryCharge: -5);
    }

    public function test_a_logo_can_be_uploaded_and_removed(): void
    {

        if (! extension_loaded('gd')) {
    $this->markTestSkipped('GD extension is not installed.');
}
        Storage::fake('public');
        $u = $this->owner();

        Volt::test('settings.receipts')->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))->call('save')->assertHasNoErrors();
        $path = $u->business->fresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        Volt::test('settings.receipts')->set('removeLogo', true)->call('save');
        $this->assertNull($u->business->fresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }
    public function test_the_amount_follows_the_delivery_fee_in_the_payment_popup(): void
    {
    $this->owner();
    $v = Product::create(['name' => 'Diffuser', 'type' => 'single'])
        ->variants()->create(['name' => 'Default', 'selling_price' => 1000, 'cost_price' => 500, 'stock_quantity' => 20]);
    $zone = DeliveryZone::create(['name' => 'Westlands', 'fee' => 300]);

    $c = Volt::test('sell.till')->call('addVariant', $v->id)->call('openPayment');
    $base = (float) $c->get('payments')[0]['amount'];
    $this->assertGreaterThan(0, $base);

    $c->set('deliveryZone', (string) $zone->id);
    $this->assertEquals($base + 300, (float) $c->get('payments')[0]['amount']);

    $c->set('deliveryAmount', '450');
    $this->assertEquals($base + 450, (float) $c->get('payments')[0]['amount']);
    }
}
