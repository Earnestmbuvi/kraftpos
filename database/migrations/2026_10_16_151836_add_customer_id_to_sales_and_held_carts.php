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
    Schema::table('sales', function (Blueprint $table) {
        $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
    });
    Schema::table('held_carts', function (Blueprint $table) {
        $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
    Schema::table('held_carts', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
}
};
