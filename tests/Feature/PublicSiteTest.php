<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\SaleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_public_page_opens_without_logging_in(): void
    {
        foreach (['/', '/features', '/why-us', '/about', '/contact', '/book-a-demo'] as $url) {
            $this->get($url)->assertOk()->assertSee('KraftPOS');
        }
    }

    public function test_demo_and_contact_requests_are_saved_and_bots_are_dropped(): void
    {
        Volt::test('public.lead-form', ['type' => 'demo'])
            ->set('name', 'Wanjiru')->set('email', 'w@shop.test')->set('phone', '0712345678')->set('business', 'Mama Shop')
            ->call('submit')->assertHasNoErrors()->assertSet('sent', true);
        $this->assertEquals('demo', Lead::first()->type);

        Volt::test('public.lead-form', ['type' => 'contact'])->call('submit')->assertHasErrors(['name', 'email', 'message']);

        Volt::test('public.lead-form', ['type' => 'contact'])
            ->set('website', 'spam')->set('name', 'Bot')->call('submit');
        $this->assertEquals(1, Lead::count());
    }

    public function test_receipt_settings_control_the_receipt_and_terms_are_saved(): void
    {
        $u = $this->makeActiveUser();
        $u->assignRole('owner');
        BusinessSetup::run($u->business);
        $this->actingAs($u);

        $branch = \App\Models\Branch::first();

        Volt::test('settings.receipts')
            ->set('o.show_till', true)->set('o.show_cashier', false)
            ->set('footerText', 'Karibu tena')->set('headerText', 'PIN P000123')
            ->set('tills.' . $branch->id, 'Till 7')
            ->set('invoiceTerms', 'Pay in 30 days')->call('save')->assertHasNoErrors();

        $this->assertEquals('Pay in 30 days', $u->business->fresh()->invoice_terms);

        $v = Product::create(['name' => 'Sugar', 'type' => 'single'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 100, 'stock_quantity' => 10]);
        $sale = SaleService::complete([['variant_id' => $v->id, 'quantity' => 1]], null,
            [['method_id' => PaymentMethod::where('name', 'Cash')->first()->id, 'amount' => 100]]);

        $this->get(route('sales.receipt', $sale))->assertOk()
            ->assertSee('Karibu tena')->assertSee('PIN P000123')->assertSee('Till 7')->assertDontSee('Served by');
    }
}
