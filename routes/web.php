<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('features', 'marketing.features')->name('marketing.features');
Route::view('why-us', 'marketing.why')->name('marketing.why');
Route::view('about', 'marketing.about')->name('marketing.about');
Route::view('contact', 'marketing.contact')->name('marketing.contact');
Route::view('book-a-demo', 'marketing.demo')->name('marketing.demo');

Route::view('admin/leads', 'admin-leads')->middleware(['auth', 'verified', 'super.admin'])->name('admin.leads');
$adm = ['auth', 'verified', 'super.admin'];

Route::view('admin/admins', 'admin-page', ['title' => 'Super admins', 'component' => 'admin.admins'])->middleware($adm)->name('admin.admins');
Route::view('admin/catalogue', 'admin-page', ['title' => 'Catalogue', 'component' => 'admin.catalogue'])->middleware($adm)->name('admin.catalogue');
Route::view('admin/quotations', 'admin-page', ['title' => 'Quotations', 'component' => 'admin.documents', 'props' => ['type' => 'quotation']])->middleware($adm)->name('admin.quotations');
Route::view('admin/invoices', 'admin-page', ['title' => 'Invoices', 'component' => 'admin.documents', 'props' => ['type' => 'invoice']])->middleware($adm)->name('admin.invoices');
Route::get('admin/documents/new/{type}', fn (string $type) => view('admin-page', ['title' => 'New ' . $type, 'component' => 'admin.document-form', 'props' => ['type' => $type]]))
    ->whereIn('type', ['quotation', 'invoice'])->middleware($adm)->name('admin.document.create');
Route::get('admin/documents/{id}', fn (int $id) => view('admin-page', ['title' => 'Document', 'component' => 'admin.document-show', 'props' => ['documentId' => $id]]))
    ->whereNumber('id')->middleware($adm)->name('admin.document');
Route::get('dashboard', function () {
    return auth()->user()->is_super_admin
        ? redirect()->route('admin.businesses')
        : view('dashboard');
})->middleware(['auth', 'verified', 'business.active'])->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth', 'business.active'])
    ->name('profile');

 Route::view('categories', 'categories')
    ->middleware(['auth', 'verified', 'business.active', 'permission:manage-categories'])
    ->name('categories');
Route::view('products', 'products')
    ->middleware(['auth', 'verified', 'business.active', 'permission:view-products'])
    ->name('products');
Route::get('setup/{tab?}', function (?string $tab = null) {
    $tabs = \App\Support\Menu::setupTabs(auth()->user());
    abort_if(empty($tabs), 403);

    $tab ??= array_key_first($tabs);
    abort_unless(array_key_exists($tab, $tabs), 404);

    return view('setup', ['tab' => $tab, 'title' => $tabs[$tab]]);
})->middleware(['auth', 'verified', 'business.active', 'permission:manage-categories|manage-products|manage-settings'])
  ->name('setup');
Route::view('stock', 'stock')
    ->middleware(['auth', 'verified', 'business.active', 'permission:adjust-stock'])
    ->name('stock');
Route::view('sell', 'sell')
    ->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
    ->name('sell');

Route::get('sales/{sale}/receipt', function (\App\Models\Sale $sale) {
    // Cashiers see only their own receipts; reports roles see all of their shop's
    abort_unless(auth()->user()->can('view-reports') || $sale->user_id === auth()->id(), 403);

    return view('receipt', ['sale' => $sale->load('items', 'payments', 'cashier', 'business', 'customer')]);
})->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
  ->name('sales.receipt');

  Route::view('sales', 'sales')
    ->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
    ->name('sales');

Route::view('customers', 'customers')
    ->middleware(['auth', 'verified', 'business.active', 'permission:manage-customers'])
    ->name('customers');
Route::view('quotations', 'quotations')
    ->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
    ->name('quotations');

Route::view('quotations/create', 'quotations-create')
    ->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
    ->name('quotations.create');

Route::get('quotations/{quotation}', function (\App\Models\Quotation $quotation) {
    // Cashiers see only their own quotations; reports roles see all of their shop's
    abort_unless(auth()->user()->can('view-reports') || $quotation->user_id === auth()->id(), 403);

    return view('quotation', ['quotation' => $quotation->load('items', 'customer', 'user', 'business')]);
})->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
  ->name('quotations.show');
Route::view('invoices', 'invoices')
    ->middleware(['auth', 'verified', 'business.active', 'permission:issue-invoices'])
    ->name('invoices');

Route::view('invoices/create', 'invoices-create')
    ->middleware(['auth', 'verified', 'business.active', 'permission:issue-invoices'])
    ->name('invoices.create');

Route::get('invoices/{invoice}', fn (\App\Models\Invoice $invoice) => view('invoice', ['invoice' => $invoice]))
    ->middleware(['auth', 'verified', 'business.active', 'permission:issue-invoices'])
    ->name('invoices.show');
Route::view('reports', 'reports')
    ->middleware(['auth', 'verified', 'business.active', 'permission:view-reports'])
    ->name('reports');
Route::view('staff', 'staff')
    ->middleware(['auth', 'verified', 'business.active', 'permission:manage-users'])
    ->name('staff');
Route::get('reports/export/{report}', \App\Http\Controllers\ReportExportController::class)
    ->middleware(['auth', 'verified', 'business.active', 'permission:view-reports'])
    ->name('reports.export');
Route::view('admin/merchants', 'admin-merchants')
    ->middleware(['auth', 'verified', 'super.admin'])
    ->name('admin.businesses');
Route::view('admin/billing', 'admin-billing')
    ->middleware(['auth', 'verified', 'super.admin'])
    ->name('admin.billing');
Route::view('expenses', 'expenses')
    ->middleware(['auth', 'verified', 'business.active', 'permission:record-expenses'])
    ->name('expenses');
Route::get('admin/merchants/{business}', fn (\App\Models\Business $business) => view('admin-merchant', ['business' => $business]))
    ->middleware(['auth', 'verified', 'super.admin'])
    ->name('admin.merchant');
Route::get('products/export', [\App\Http\Controllers\ProductCsvController::class, 'export'])
    ->middleware(['auth', 'verified', 'business.active', 'permission:view-products'])
    ->name('products.export');

Route::get('products/import-template', [\App\Http\Controllers\ProductCsvController::class, 'template'])
    ->middleware(['auth', 'verified', 'business.active', 'permission:manage-products'])
    ->name('products.import-template');

Route::view('products/import', 'products-import')
    ->middleware(['auth', 'verified', 'business.active', 'permission:manage-products'])
    ->name('products.import');

Route::view('stock/movements', 'stock-movements')
    ->middleware(['auth', 'verified', 'business.active', 'permission:adjust-stock'])
    ->name('stock.movements');

Route::get('stock/movements/export', \App\Http\Controllers\StockMovementExportController::class)
    ->middleware(['auth', 'verified', 'business.active', 'permission:adjust-stock'])
    ->name('stock.movements.export');

Route::view('stock/take', 'stock-take')
    ->middleware(['auth', 'verified', 'business.active', 'permission:adjust-stock'])
    ->name('stock.take');

Route::get('quotations/{quotation}/edit', fn (\App\Models\Quotation $quotation) => view('quotations-edit', ['quotation' => $quotation]))
    ->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])
    ->name('quotations.edit');
Route::get('invoices/{invoice}/payments/{payment}/receipt', function (\App\Models\Invoice $invoice, int $payment) {
    $p = $invoice->payments()->with('user')->findOrFail($payment);

    return view('invoice-receipt', ['invoice' => $invoice->load('customer', 'business'), 'payment' => $p]);
})->middleware(['auth', 'verified', 'business.active', 'permission:issue-invoices'])
  ->name('invoices.payment-receipt');
foreach (['stock-list', 'additions', 'price-list', 'expenses', 'income'] as $kind) {
    $middleware = ['auth', 'verified', 'business.active', 'permission:view-reports'];
    if ($kind === 'income') {
        $middleware[] = 'permission:view-cost-prices'; // it shows cost of goods
    }

    Route::view("reports/{$kind}", 'reports-extra', ['kind' => $kind])
        ->middleware($middleware)
        ->name("reports.{$kind}");
}
Route::view('returns', 'returns')->middleware(['auth', 'verified', 'business.active', 'permission:process-returns'])->name('returns');
Route::view('returns/new', 'returns-form', ['mode' => 'return'])->middleware(['auth', 'verified', 'business.active', 'permission:process-returns'])->name('returns.create');
Route::view('exchanges/new', 'returns-form', ['mode' => 'exchange'])->middleware(['auth', 'verified', 'business.active', 'permission:process-returns'])->name('exchanges.create');
Route::get('returns/{saleReturn}', fn (\App\Models\SaleReturn $saleReturn) => view('return-note', ['ret' => $saleReturn->load('items', 'sale', 'exchangeSale', 'user')]))
    ->middleware(['auth', 'verified', 'business.active', 'permission:process-returns'])->name('returns.show');

Route::view('stock/transfers', 'stock-transfers')->middleware(['auth', 'verified', 'business.active', 'permission:transfer-stock'])->name('stock.transfers');

Route::view('reports/sale-items', 'reports-sale-items')->middleware(['auth', 'verified', 'business.active', 'permission:make-sales'])->name('reports.sale-items');
Route::view('reports/customers', 'reports-customers')->middleware(['auth', 'verified', 'business.active', 'permission:view-reports'])->name('reports.customers');
Route::get('reports/detail-export/{kind}', \App\Http\Controllers\SalesDetailExportController::class)
    ->middleware(['auth', 'verified', 'business.active'])->name('reports.detail-export');
Route::view('reports/tax', 'reports-tax')
    ->middleware(['auth', 'verified', 'business.active', 'permission:view-reports'])
    ->name('reports.tax');

require __DIR__.'/auth.php';
