<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Expense;
use App\Models\HeldCart;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Scopes\BranchViewScope;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\BranchService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Sale::class, Invoice::class, SaleReturn::class, Expense::class, Quotation::class,
          HeldCart::class, StockMovement::class, StockBatch::class] as $class) {
    $class::addGlobalScope(new BranchViewScope());

    $class::creating(function ($model) {
        $u = auth()->user();

        if ($model->branch_id === null && $u && $u->business_id) {
            $model->branch_id = BranchService::currentId($u);
        }
    });
}
    }
}
