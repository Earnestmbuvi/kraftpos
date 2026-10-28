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
    Schema::table('businesses', function (Blueprint $t) {
        $t->json('receipt_settings')->nullable();
        $t->text('invoice_terms')->nullable();
        $t->text('quotation_terms')->nullable();
    });

    Schema::table('branches', function (Blueprint $t) {
        $t->string('till_number', 20)->nullable();
    });

    Schema::create('leads', function (Blueprint $t) {
        $t->id();
        $t->string('type', 10);                    // contact | demo
        $t->string('name', 100);
        $t->string('email', 150);
        $t->string('phone', 30)->nullable();
        $t->string('business_name', 150)->nullable();
        $t->string('sector', 60)->nullable();
        $t->unsignedSmallInteger('branches_count')->nullable();
        $t->date('preferred_date')->nullable();
        $t->text('message')->nullable();
        $t->string('status', 10)->default('new');  // new | handled
        $t->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('leads');
    Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('till_number'));
    Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn(['receipt_settings', 'invoice_terms', 'quotation_terms']));
}
};
