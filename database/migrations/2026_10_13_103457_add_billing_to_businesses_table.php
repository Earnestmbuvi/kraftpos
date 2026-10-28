<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('businesses', function (Blueprint $table) {
        $table->date('subscription_ends_at')->nullable();
        $table->decimal('monthly_fee', 10, 2)->default(0);
        $table->boolean('billing_suspended')->default(false);
    });
}

public function down(): void
{
    Schema::table('businesses', function (Blueprint $table) {
        $table->dropColumn(['subscription_ends_at', 'monthly_fee', 'billing_suspended']);
    });
}
};
