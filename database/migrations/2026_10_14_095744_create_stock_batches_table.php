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
    Schema::create('stock_batches', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
        $table->string('batch_number', 50)->nullable();
        $table->date('expiry_date')->nullable(); // empty means it never expires
        $table->integer('quantity')->default(0);          // what is left
        $table->integer('received_quantity')->default(0); // what came in
        $table->timestamps();

        $table->index(['product_variant_id', 'expiry_date']);
        $table->index(['business_id', 'expiry_date']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
