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
        $table->boolean('discounts_enabled')->default(true);
    });
}

public function down(): void
{
    Schema::table('businesses', function (Blueprint $table) {
        $table->dropColumn('discounts_enabled');
    });
}
};
