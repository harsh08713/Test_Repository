<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who the document is for, where it goes and the figures — the same on an order and on the invoice made from it. */
    private function party(Blueprint $table): void
    {
        $table->foreignId('customer_ledger_id')->constrained('ledgers')->restrictOnDelete();
        $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
        $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();

        $table->string('party_name', 200);
        $table->string('contact_person', 150)->nullable();
        $table->string('mobile', 10)->nullable();
        $table->string('email', 150)->nullable();
        $table->string('bill_line1', 200)->nullable();
        $table->string('bill_line2', 200)->nullable();
        $table->string('bill_city', 100)->nullable();
        $table->string('bill_pin_code', 6)->nullable();
        $table->string('customer_gstin', 15)->nullable();
        $table->string('ship_line1', 200)->nullable();
        $table->string('ship_line2', 200)->nullable();
        $table->string('ship_city', 100)->nullable();
        $table->string('ship_pin_code', 6)->nullable();
        $table->foreignId('place_of_supply_state_id')->constrained('states')->restrictOnDelete();
        $table->string('supply_type', 5);
        $table->string('supplier_gstin', 15)->nullable();
        $table->string('supplier_state_code', 2)->nullable();
        $table->string('customer_po_number', 60)->nullable();
        $table->date('customer_po_date')->nullable();
        $table->text('terms')->nullable();

        $table->decimal('gross_total', 15, 2)->default(0);
        $table->decimal('discount_total', 15, 2)->default(0);
        $table->decimal('taxable_total', 15, 2)->default(0);
        $table->decimal('cgst_total', 15, 2)->default(0);
        $table->decimal('sgst_total', 15, 2)->default(0);
        $table->decimal('igst_total', 15, 2)->default(0);
        $table->decimal('round_off', 6, 2)->default(0);
        $table->decimal('grand_total', 15, 2)->default(0);
    }

    /** What one line says, with the figures it was priced and taxed at. */
    private function lineFigures(Blueprint $table): void
    {
        $table->unsignedSmallInteger('line_no');
        $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
        $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
        $table->string('item_name', 200);
        $table->string('hsn_code', 20)->nullable();
        $table->decimal('quantity', 15, 3);
        $table->foreignId('rate_id')->nullable()->constrained('rates')->nullOnDelete();
        $table->string('rate_source', 12)->default('standard');
        $table->decimal('list_rate', 15, 4);
        $table->decimal('rate', 15, 4);
        $table->boolean('rate_overridden')->default(false);
        $table->string('override_reason', 1000)->nullable();
        $table->decimal('discount_percent', 5, 2)->default(0);
        $table->decimal('gross_amount', 15, 2);
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('taxable_amount', 15, 2);
        $table->decimal('gst_rate', 5, 2)->default(0);
        $table->decimal('cgst_amount', 15, 2)->default(0);
        $table->decimal('sgst_amount', 15, 2)->default(0);
        $table->decimal('igst_amount', 15, 2)->default(0);
        $table->decimal('line_total', 15, 2);
    }

    public function up(): void
    {
        // ---- sales orders ----
        //
        // An order is made from an approved quotation, from an approved deal, or directly for a regular customer. It is an approval
        // document; approving it is what makes the invoice (SalesInvoice, below). Everything about the customer, the addresses, the
        // place of supply, the prices and the tax is a copy taken when the order is saved. `live_quotation_id` / `live_deal_id` are
        // the source ids of an order that has not been cancelled: their unique keys are what let one quotation (or deal) have one
        // live order whatever races, and a cancelled order frees its source again.
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('so_number', 40);
            $table->date('order_date');
            $table->date('delivery_date');
            $table->string('source_type', 10);
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->string('state', 10)->default('open');
            $table->string('pricing_policy', 8)->default('rates');
            $table->date('price_date');
            $this->party($table);

            // the credit check: what the customer's limit was, what was already owed on orders still in their credit days, and the answer
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('credit_exposure', 15, 2)->default(0);
            $table->string('credit_result', 12)->default('not_checked');
            $table->dateTime('credit_checked_at')->nullable();
            $table->string('credit_override_reason', 500)->nullable();
            $table->foreignId('credit_override_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('invoiced_at')->nullable();
            $table->dateTime('invoice_attempted_at')->nullable();
            $table->string('invoice_error', 500)->nullable();

            $table->unsignedBigInteger('live_quotation_id')->nullable()->virtualAs("CASE WHEN `state` = 'cancelled' THEN NULL ELSE `quotation_id` END");
            $table->unsignedBigInteger('live_deal_id')->nullable()->virtualAs("CASE WHEN `state` = 'cancelled' THEN NULL ELSE `deal_id` END");

            $table->unique(['company_id', 'so_number']);
            $table->unique('live_quotation_id');
            $table->unique('live_deal_id');
            $table->index(['company_id', 'state', 'order_date']);
            $table->index(['assigned_to', 'order_date']);
            $table->index('customer_ledger_id');
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('quotation_item_id')->nullable()->constrained('quotation_items')->nullOnDelete();
            $table->foreignId('deal_item_id')->nullable()->constrained('deal_items')->nullOnDelete();
            $this->lineFigures($table);
            // for invoicing in parts later: how much of the line has been invoiced (the first release invoices all of it at once)
            $table->decimal('invoiced_quantity', 15, 3)->default(0);

            $table->index(['sales_order_id', 'line_no']);
            $table->index('item_id');
        });

        Schema::create('sales_order_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->string('from_state', 10)->nullable();
            $table->string('to_state', 10);
            $table->string('comment', 1000)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sales_order_id', 'id']);
        });

        // ---- sales invoices ----
        //
        // An invoice is a document made from an approved order and linked to it and to its lines: it carries its own number series and
        // copies of every figure, so it never changes when the masters do. It posts nothing to stock or to accounts. `source_key`
        // says which part of the order it covers — 'full' for now; invoicing in parts later gives each part its own key — and
        // `unique(sales_order_id, source_key)` is what makes "one invoice for a fully invoiced order" true whatever races or retries.
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('invoice_number', 40);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->string('source_key', 40)->default('full');
            $table->string('status', 10)->default('issued');
            $table->date('price_date');
            $this->party($table);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['sales_order_id', 'source_key']);
            $table->index(['company_id', 'status', 'invoice_date']);
            $table->index('customer_ledger_id');
        });

        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete();
            $this->lineFigures($table);

            $table->unique(['sales_invoice_id', 'sales_order_item_id']);
            $table->index(['sales_invoice_id', 'line_no']);
        });

        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_state_valid CHECK (state IN ('open', 'invoiced', 'cancelled'))");
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_source_valid CHECK (source_type IN ('quotation', 'deal', 'direct'))");
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_source_named CHECK ((source_type = 'quotation') = (quotation_id IS NOT NULL) AND (source_type <> 'direct' OR deal_id IS NULL))");
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_supply_valid CHECK (supply_type IN ('intra', 'inter'))");
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_credit_valid CHECK (credit_result IN ('not_checked', 'ignored', 'no_limit', 'ok', 'warn', 'blocked', 'overridden'))");
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_dates_valid CHECK (delivery_date >= order_date)');
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_totals_valid CHECK (gross_total >= 0 AND taxable_total >= 0 AND grand_total >= 0)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_quantity_valid CHECK (quantity > 0 AND invoiced_quantity >= 0 AND invoiced_quantity <= quantity)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_amounts_valid CHECK (rate >= 0 AND list_rate >= 0 AND line_total >= 0 AND discount_percent BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_valid CHECK (status IN ('issued', 'cancelled'))");
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_supply_valid CHECK (supply_type IN ('intra', 'inter'))");
        DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_totals_valid CHECK (gross_total >= 0 AND taxable_total >= 0 AND grand_total >= 0)');
        DB::statement('ALTER TABLE sales_invoice_items ADD CONSTRAINT sales_invoice_items_quantity_valid CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('sales_order_history');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
