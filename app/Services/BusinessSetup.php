<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Unit;
use App\Models\ProductOption;
use App\Models\PaymentMethod;
use App\Models\ExpenseCategory;


class BusinessSetup
{
    public static function run(Business $business): void
    {
        Unit::seedDefaultsFor($business);
        ProductOption::seedDefaultsFor($business);
        PaymentMethod::seedDefaultsFor($business);
        ExpenseCategory::seedDefaultsFor($business);
        \App\Services\BranchService::mainId($business->id);
        // later: default payment methods, variant options, etc.
    }
}
