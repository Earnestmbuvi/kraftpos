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
    Schema::create('subscription_payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
        $table->decimal('amount', 10, 2);
        $table->unsignedTinyInteger('months');
        $table->string('method', 30);
        $table->string('reference', 50)->nullable();
        $table->date('period_start');
        $table->date('period_end');
        $table->string('note', 255)->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
