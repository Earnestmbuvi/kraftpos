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
        Schema::create('businesses', function (Blueprint $table) {
    $table->id();

    // Business details
    $table->string('name');
    $table->string('trading_name')->nullable();
    $table->enum('business_type', ['sole_proprietor', 'partnership', 'limited_company'])->default('sole_proprietor');
    $table->string('registration_number')->nullable();
    $table->string('category')->nullable();
    $table->string('phone');
    $table->string('email')->nullable();
    $table->string('address')->nullable();
    $table->string('county')->nullable();
    $table->string('town')->nullable();
    $table->unsignedSmallInteger('expected_branches')->default(1);

    // Owner details
    $table->string('owner_name');
    $table->string('owner_id_number');
    $table->string('owner_phone')->nullable();
    $table->string('owner_email')->nullable();
    $table->date('owner_dob')->nullable();

    // Admin and review
    $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('pending');
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->text('status_reason')->nullable();
    $table->text('notes')->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
