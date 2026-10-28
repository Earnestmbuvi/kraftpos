<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('businesses', function (Blueprint $table) {
        $table->unsignedInteger('last_payment_number')->default(0);
    });
    Schema::table('invoice_payments', function (Blueprint $table) {
        $table->unsignedInteger('receipt_number')->nullable();
    });

    // Number the payments that already exist, per shop, oldest first
    $counters = [];
    foreach (DB::table('invoice_payments')->orderBy('id')->get(['id', 'business_id']) as $p) {
        $counters[$p->business_id] = ($counters[$p->business_id] ?? 0) + 1;
        DB::table('invoice_payments')->where('id', $p->id)->update(['receipt_number' => $counters[$p->business_id]]);
    }
    foreach ($counters as $businessId => $n) {
        DB::table('businesses')->where('id', $businessId)->update(['last_payment_number' => $n]);
    }

    Schema::table('invoice_payments', function (Blueprint $table) {
        $table->unique(['business_id', 'receipt_number']);
    });
}

public function down(): void
{
    Schema::table('invoice_payments', function (Blueprint $table) {
        $table->dropUnique(['business_id', 'receipt_number']);
        $table->dropColumn('receipt_number');
    });
    Schema::table('businesses', function (Blueprint $table) {
        $table->dropColumn('last_payment_number');
    });
}
};
