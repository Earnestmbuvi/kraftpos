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
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->string('name', 100);
        $table->string('phone', 20)->nullable();
        $table->string('email', 100)->nullable();
        $table->string('address', 255)->nullable();
        $table->string('notes', 255)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->unique(['business_id', 'phone']);
        $table->index(['business_id', 'name']);
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
