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
    Schema::table('businesses', fn (Blueprint $t) => $t->string('logo_path')->nullable());

    Schema::create('delivery_zones', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->string('name', 80);
        $t->decimal('fee', 12, 2)->default(0);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
        $t->unique(['business_id', 'name']);
    });

    foreach (['sales', 'invoices'] as $table) {
        Schema::table($table, function (Blueprint $t) {
            $t->decimal('delivery_charge', 12, 2)->default(0);
            $t->string('delivery_label', 80)->nullable();
        });
    }
}

public function down(): void
{
    foreach (['sales', 'invoices'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['delivery_charge', 'delivery_label']));
    }
    Schema::dropIfExists('delivery_zones');
    Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('logo_path'));
}
};
