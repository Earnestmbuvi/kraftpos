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
    Schema::create('expenses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
        $table->string('category_name', 50);
        $table->decimal('amount', 12, 2);
        $table->date('expense_date');
        $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
        $table->string('method_name', 30)->nullable();
        $table->string('reference', 50)->nullable();
        $table->string('description', 255);
        $table->string('status', 20)->default('recorded'); // recorded, voided
        $table->timestamp('voided_at')->nullable();
        $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        $table->string('void_reason', 255)->nullable();
        $table->timestamps();

        $table->index(['business_id', 'expense_date']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
