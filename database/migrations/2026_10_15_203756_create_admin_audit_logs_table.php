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
    Schema::create('admin_audit_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('action', 50);
        $table->string('detail', 255)->nullable();
        $table->timestamps();

        $table->index(['business_id', 'created_at']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
