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
       Schema::create('sales', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->unsignedInteger('receipt_number');
    $table->string('status', 20)->default('completed'); // completed, voided

    $table->decimal('subtotal', 12, 2);
    $table->string('discount_type', 10)->nullable();    // percent, fixed
    $table->decimal('discount_value', 12, 2)->default(0);
    $table->decimal('discount_amount', 12, 2)->default(0);
    $table->decimal('tax_rate', 5, 2)->default(0);
    $table->boolean('tax_inclusive')->default(false);
    $table->decimal('tax_amount', 12, 2)->default(0);
    $table->decimal('total', 12, 2);
    $table->decimal('amount_paid', 12, 2);
    $table->decimal('change_given', 12, 2)->default(0);

    $table->timestamp('voided_at')->nullable();
    $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('void_reason', 255)->nullable();
    $table->timestamps();

    $table->unique(['business_id', 'receipt_number']);
    $table->index(['business_id', 'created_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
