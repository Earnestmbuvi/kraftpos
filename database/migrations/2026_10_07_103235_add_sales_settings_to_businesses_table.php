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
        $table->decimal('tax_rate', 5, 2)->default(0);
        $table->boolean('tax_inclusive')->default(false);
        $table->unsignedInteger('last_receipt_number')->default(0);
    });
}

public function down(): void
{
    Schema::create('payment_methods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->string('name', 30);
    $table->string('type', 10)->default('other'); // cash, mobile, card, other
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['business_id', 'name']);
});
}
};
