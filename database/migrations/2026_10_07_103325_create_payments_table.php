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
        Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
    $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
    $table->string('method_name', 30);
    $table->string('type', 10);
    $table->decimal('amount', 12, 2);
    $table->string('reference', 50)->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
