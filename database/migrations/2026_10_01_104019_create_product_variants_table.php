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
        Schema::create('product_variants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->string('name')->default('Default');   // e.g. "Large / Red"
    $table->json('options')->nullable();          // {"Size":"Large","Colour":"Red"}
    $table->string('sku')->nullable();
    $table->string('barcode')->nullable();
    $table->decimal('cost_price', 12, 2)->default(0);
    $table->decimal('selling_price', 12, 2);
    $table->integer('stock_quantity')->default(0);
    $table->unsignedInteger('low_stock_threshold')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['product_id', 'name']);
    $table->unique(['business_id', 'sku']);
    $table->unique(['business_id', 'barcode']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
