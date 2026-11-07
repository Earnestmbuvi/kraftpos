<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    

public function up(): void
{
    Schema::create('document_texts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->string('kind', 10);                    // terms | payment
        $t->string('name', 80);
        $t->text('body');
        $t->boolean('default_invoice')->default(false);
        $t->boolean('default_quotation')->default(false);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
        $t->unique(['business_id', 'kind', 'name']);
    });

    foreach (['invoices', 'quotations'] as $table) {
        Schema::table($table, function (Blueprint $t) {
            $t->text('terms_text')->nullable();
            $t->unsignedBigInteger('terms_text_id')->nullable();
            $t->text('payment_text')->nullable();
            $t->unsignedBigInteger('payment_text_id')->nullable();
        });
    }

    // Keep each shop's existing terms: as a saved text (the default) and on the documents already made
    foreach (DB::table('businesses')->get(['id', 'invoice_terms', 'quotation_terms']) as $b) {
        $map = [
            [$b->invoice_terms, 'invoices', 'Invoice terms', 'default_invoice'],
            [$b->quotation_terms, 'quotations', 'Quotation terms', 'default_quotation'],
        ];

        foreach ($map as [$text, $table, $name, $flag]) {
            if (! filled($text)) {
                continue;
            }

            $id = DB::table('document_texts')->insertGetId([
                'business_id' => $b->id, 'kind' => 'terms', 'name' => $name, 'body' => $text,
                $flag => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table($table)->where('business_id', $b->id)->update(['terms_text' => $text, 'terms_text_id' => $id]);
        }
    }
}

public function down(): void
{
    foreach (['invoices', 'quotations'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['terms_text', 'terms_text_id', 'payment_text', 'payment_text_id']));
    }
    Schema::dropIfExists('document_texts');
}
};
