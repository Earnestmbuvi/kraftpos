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
    foreach (['stock_batches', 'quotations', 'held_carts', 'expenses'] as $table) {
        Schema::table($table, function (Blueprint $t) {
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        });
    }
}

public function down(): void
{
    foreach (['stock_batches', 'quotations', 'held_carts', 'expenses'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
    }
}
};
