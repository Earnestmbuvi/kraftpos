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
       Schema::create('sale_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_variant_id')->constrained();
    $table->string('product_name');
    $table->string('variant_name')->nullable();
    $table->string('sku', 50)->nullable();
    $table->string('unit', 30)->nullable();
    $table->unsignedInteger('quantity');
    $table->decimal('unit_price', 12, 2);
    $table->decimal('unit_cost', 12, 2)->default(0);
    $table->string('discount_type', 10)->nullable();
    $table->decimal('discount_value', 12, 2)->default(0);
    $table->decimal('discount_amount', 12, 2)->default(0);
    $table->decimal('line_total', 12, 2);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
