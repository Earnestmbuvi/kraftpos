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
    Schema::create('batch_allocations', function (Blueprint $table) {
        $table->id();
        $table->foreignId('business_id')->constrained()->cascadeOnDelete();
        $table->foreignId('stock_batch_id')->constrained()->cascadeOnDelete();
        $table->morphs('allocatable');
        $table->unsignedInteger('quantity');
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('batch_allocations');
}


};
