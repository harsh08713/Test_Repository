<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The brand of an item and its standard cost (both set by Purchase, stage 1). The cost is what an invoice line snapshots.
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('item_group_id')->constrained('brands')->restrictOnDelete();
            $table->decimal('standard_cost', 18, 4)->nullable()->after('gst_rate');
            $table->index('brand_id');
        });

        // The cost of what was sold, taken when the invoice is made and never recalculated (FORMULAS.md §2). Null = the item had no cost
        // then: unknown is not zero, and the figures say how many lines are unknown. Invoices made before this column existed stay null.
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 4)->nullable()->after('line_total');   // per unit of the line (the sold unit)
            $table->decimal('cost_amount', 15, 2)->nullable()->after('unit_cost');
            $table->string('cost_source', 16)->nullable()->after('cost_amount');
        });

        // ---- sales returns and credit notes ----
        //
        // A return (goods come back) or a credit note (money is credited, goods stay) against one issued invoice. Both reduce net revenue the
        // same way; they differ for stock (Phase L3). An approval document: pending and approved ones never change.
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('return_number', 40);
            $table->date('return_date');
            $table->string('return_type', 12);
            $table->text('reason');
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('customer_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('party_name', 200);
            $table->string('supply_type', 5);
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('taxable_total', 15, 2)->default(0);
            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->nullable();

            $table->unique(['company_id', 'return_number']);
            $table->index(['company_id', 'approval_status', 'return_date']);
            $table->index('sales_invoice_id');
            $table->index('customer_ledger_id');
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->foreignId('sales_invoice_item_id')->constrained('sales_invoice_items')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->string('hsn_code', 20)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('rate', 15, 4);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('cost_amount', 15, 2)->nullable();

            $table->unique(['sales_return_id', 'sales_invoice_item_id'], 'return_line_once');
            $table->index('sales_invoice_item_id');
        });

        DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_type_valid CHECK (return_type IN ('return', 'credit_note'))");
        DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_supply_valid CHECK (supply_type IN ('intra', 'inter'))");
        DB::statement('ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_totals_valid CHECK (gross_total >= 0 AND taxable_total >= 0 AND grand_total >= 0)');
        DB::statement('ALTER TABLE sales_return_items ADD CONSTRAINT sales_return_items_quantity_valid CHECK (quantity > 0 AND rate >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');

        Schema::table('sales_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_amount', 'cost_source']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropIndex(['brand_id']);
            $table->dropColumn(['brand_id', 'standard_cost']);
        });
    }
};
