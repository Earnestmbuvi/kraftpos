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
    Schema::create('branch_product', function (Blueprint $t) {
        $t->id();
        $t->foreignId('product_id')->constrained()->cascadeOnDelete();
        $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
        $t->unique(['product_id', 'branch_id']);
    });

    Schema::create('staff_roles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->string('label', 50);
        $t->string('role_name', 80)->unique();
        $t->timestamps();
        $t->unique(['business_id', 'label']);
    });

    Schema::create('platform_items', function (Blueprint $t) {
        $t->id();
        $t->string('name', 150)->unique();
        $t->string('kind', 10)->default('service'); // service | product
        $t->string('description', 255)->nullable();
        $t->decimal('price', 12, 2)->default(0);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    Schema::create('platform_documents', function (Blueprint $t) {
        $t->id();
        $t->string('type', 10);                    // quotation | invoice
        $t->unsignedInteger('number');
        $t->foreignId('business_id')->constrained();
        $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $t->string('status', 12);                  // open|converted|declined | issued|voided
        $t->date('issue_date');
        $t->date('due_date')->nullable();          // valid until, or payment due
        $t->decimal('tax_rate', 5, 2)->default(0);
        $t->decimal('subtotal', 12, 2);
        $t->decimal('tax_amount', 12, 2)->default(0);
        $t->decimal('total', 12, 2);
        $t->decimal('amount_paid', 12, 2)->default(0);
        $t->string('notes', 255)->nullable();
        $t->unsignedBigInteger('from_document_id')->nullable();
        $t->timestamp('closed_at')->nullable();
        $t->string('close_reason', 255)->nullable();
        $t->timestamps();
        $t->unique(['type', 'number']);
    });

    Schema::create('platform_document_items', function (Blueprint $t) {
        $t->id();
        $t->foreignId('platform_document_id')->constrained()->cascadeOnDelete();
        $t->unsignedBigInteger('platform_item_id')->nullable();
        $t->string('name', 150);
        $t->string('description', 255)->nullable();
        $t->unsignedInteger('quantity');
        $t->decimal('unit_price', 12, 2);
        $t->decimal('line_total', 12, 2);
        $t->timestamps();
    });

    Schema::create('platform_document_payments', function (Blueprint $t) {
        $t->id();
        $t->foreignId('platform_document_id')->constrained()->cascadeOnDelete();
        $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $t->string('method', 30);
        $t->string('reference', 50)->nullable();
        $t->decimal('amount', 12, 2);
        $t->timestamps();
    });

    Schema::create('platform_sequences', function (Blueprint $t) {
        $t->string('type', 20)->primary();
        $t->unsignedInteger('last')->default(0);
    });
}

public function down(): void
{
    foreach (['platform_sequences', 'platform_document_payments', 'platform_document_items', 'platform_documents',
              'platform_items', 'staff_roles', 'branch_product'] as $table) {
        Schema::dropIfExists($table);
    }
}
};
