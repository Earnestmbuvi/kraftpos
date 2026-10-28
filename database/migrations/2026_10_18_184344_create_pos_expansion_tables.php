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
    Schema::create('branches', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->string('name', 80);
        $t->boolean('is_main')->default(false);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
        $t->unique(['business_id', 'name']);
    });

    Schema::create('branch_stocks', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
        $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
        $t->integer('quantity')->default(0);
        $t->timestamps();
        $t->unique(['branch_id', 'product_variant_id']);
    });

    Schema::create('stock_transfers', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->foreignId('from_branch_id')->constrained('branches');
        $t->foreignId('to_branch_id')->constrained('branches');
        $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $t->unsignedInteger('transfer_number');
        $t->string('note', 255)->nullable();
        $t->timestamps();
        $t->unique(['business_id', 'transfer_number']);
    });

    Schema::create('stock_transfer_items', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
        $t->foreignId('product_variant_id')->constrained();
        $t->string('product_name');
        $t->string('variant_name')->nullable();
        $t->unsignedInteger('quantity');
        $t->timestamps();
    });

    Schema::create('sale_returns', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        $t->foreignId('sale_id')->constrained('sales');
        $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $t->unsignedInteger('return_number');
        $t->string('type', 10);                       // return | exchange
        $t->decimal('value', 12, 2);                  // what the returned goods were worth
        $t->decimal('refund_total', 12, 2)->default(0); // money paid back out
        $t->foreignId('refund_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
        $t->string('refund_method_name', 30)->nullable();
        $t->string('refund_type', 10)->nullable();
        $t->string('reference', 50)->nullable();
        $t->string('reason', 255);
        $t->foreignId('exchange_sale_id')->nullable()->constrained('sales')->nullOnDelete();
        $t->timestamps();
        $t->unique(['business_id', 'return_number']);
    });

    Schema::create('sale_return_items', function (Blueprint $t) {
        $t->id();
        $t->foreignId('business_id')->constrained()->cascadeOnDelete();
        $t->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
        $t->foreignId('sale_item_id')->constrained('sale_items');
        $t->foreignId('product_variant_id')->constrained();
        $t->string('product_name');
        $t->string('variant_name')->nullable();
        $t->unsignedInteger('quantity');
        $t->decimal('amount', 12, 2);
        $t->decimal('tax_amount', 12, 2)->default(0);
        $t->decimal('cost', 12, 2)->default(0);
        $t->timestamps();
    });

    Schema::table('businesses', function (Blueprint $t) {
        $t->boolean('allow_zero_stock_sales')->default(true);
        $t->unsignedInteger('last_return_number')->default(0);
        $t->unsignedInteger('last_transfer_number')->default(0);
    });

    Schema::table('products', fn (Blueprint $t) => $t->string('tax_class', 10)->default('standard'));
    foreach (['sale_items', 'invoice_items', 'quotation_items'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->string('tax_class', 10)->default('standard'));
    }

    Schema::table('sales', function (Blueprint $t) {
        $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        $t->decimal('credit_applied', 12, 2)->default(0);
    });
    foreach (['invoices', 'stock_movements', 'users'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete());
    }
}

public function down(): void
{
    foreach (['invoices', 'stock_movements', 'users', 'sales'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
    }
    Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('credit_applied'));
    foreach (['sale_items', 'invoice_items', 'quotation_items', 'products'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->dropColumn('tax_class'));
    }
    Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn(['allow_zero_stock_sales', 'last_return_number', 'last_transfer_number']));
    foreach (['sale_return_items', 'sale_returns', 'stock_transfer_items', 'stock_transfers', 'branch_stocks', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }
}
};
