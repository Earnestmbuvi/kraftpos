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
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('business_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('user_name', 100)->nullable();
        $t->unsignedBigInteger('branch_id')->nullable();
        $t->string('action', 30);                    // created | updated | login | logout | failed_login
        $t->string('subject_type', 60)->nullable();
        $t->unsignedBigInteger('subject_id')->nullable();
        $t->string('subject_label', 150)->nullable();
        $t->string('description', 500);
        $t->json('changes')->nullable();
        $t->string('ip', 45)->nullable();
        $t->timestamp('created_at')->useCurrent();

        $t->index(['business_id', 'created_at']);
        $t->index(['subject_type', 'subject_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('activity_logs');
}
};
