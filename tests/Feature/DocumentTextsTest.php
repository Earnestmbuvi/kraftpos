<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DocumentText;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\BusinessSetup;
use App\Services\InvoiceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DocumentTextsTest extends TestCase
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

    private function invoice(): Invoice
    {
        $v = Product::create(['name' => 'Sugar', 'type' => 'single'])
            ->variants()->create(['name' => 'Default', 'selling_price' => 100, 'cost_price' => 60, 'stock_quantity' => 20]);
        $c = Customer::create(['name' => 'Jane', 'phone' => '07' . random_int(10000000, 99999999), 'address' => 'Nairobi']);

        return InvoiceService::issue($c->id, null, null, [['variant_id' => $v->id, 'quantity' => 1]], null);
    }

    public function test_defaults_apply_and_a_document_can_switch_texts(): void
    {
        $this->owner();

        foreach ([['terms', 'Standard terms', 'Pay in 30 days'], ['payment', 'M-Pesa', 'Paybill 123456'], ['payment', 'Bank', 'Bank X Acc 99']] as [$kind, $name, $body]) {
            Volt::test('settings.document-texts')->set('kind', $kind)->set('name', $name)->set('body', $body)->call('save')->assertHasNoErrors();
        }

        $terms = DocumentText::where('name', 'Standard terms')->first();
        $mpesa = DocumentText::where('name', 'M-Pesa')->first();
        $bank = DocumentText::where('name', 'Bank')->first();

        Volt::test('settings.document-texts')->call('toggleDefault', $terms->id, 'invoice')->call('toggleDefault', $mpesa->id, 'invoice');

        $inv = $this->invoice();
        $this->assertEquals('Pay in 30 days', $inv->fresh()->terms_text);
        $this->assertEquals('Paybill 123456', $inv->fresh()->payment_text);

        Volt::test('documents.text-blocks', ['kind' => 'invoice', 'documentId' => $inv->id])
            ->assertSee('Paybill 123456')
            ->set('paymentId', (string) $bank->id)->assertSee('Bank X Acc 99')->assertDontSee('Paybill 123456')
            ->set('termsId', '')->assertDontSee('Terms and conditions');

        $this->assertEquals('Bank X Acc 99', $inv->fresh()->payment_text);
        $this->assertNull($inv->fresh()->terms_text);

        // editing a saved text later leaves the document alone
        $bank->update(['body' => 'Changed']);
        $this->assertEquals('Bank X Acc 99', $inv->fresh()->payment_text);
    }

    public function test_duplicate_names_are_refused_and_another_shop_cannot_use_a_text(): void
    {
        $this->owner();
        Volt::test('settings.document-texts')->set('kind', 'terms')->set('name', 'Terms 1')->set('body', 'A')->call('save');
        Volt::test('settings.document-texts')->set('kind', 'terms')->set('name', 'Terms 1')->set('body', 'B')->call('save')->assertHasErrors(['name']);

        $first = DocumentText::first();
        $inv = $this->invoice();

        $this->owner(); // a second shop
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('documents.text-blocks', ['kind' => 'invoice', 'documentId' => $inv->id]);
    }
}
