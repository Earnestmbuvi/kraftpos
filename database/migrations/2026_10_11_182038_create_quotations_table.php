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
    Schema::create('quotations', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('customer_id')->constrained();
        $table->unsignedInteger('quote_number');
        $table->string('status', 20)->default('open'); // open, accepted, declined, converted
        $table->date('valid_until');
        $table->string('notes', 255)->nullable();

        $table->decimal('subtotal', 12, 2);
        $table->string('discount_type', 10)->nullable();
        $table->decimal('discount_value', 12, 2)->default(0);
        $table->decimal('discount_amount', 12, 2)->default(0);
        $table->decimal('tax_rate', 5, 2)->default(0);
        $table->boolean('tax_inclusive')->default(false);
        $table->decimal('tax_amount', 12, 2)->default(0);
        $table->decimal('total', 12, 2);
        $table->timestamps();

        $table->unique(['business_id', 'quote_number']);
        $table->index(['business_id', 'created_at']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
