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
    Schema::table('businesses', fn (Blueprint $t) => $t->unsignedBigInteger('last_item_number')->default(0));
}

public function down(): void
{
    Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('last_item_number'));
}
};
