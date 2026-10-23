<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How an item is tracked in stock (Inventory stage): by batch/lot or just counted; whether it expires.
        Schema::table('items', function (Blueprint $table) {
            $table->string('tracking', 8)->default('none')->after('gst_rate');
            $table->boolean('has_expiry')->default(false)->after('tracking');
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('has_expiry');
        });

        // ---- batches ----
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('batch_no', 60);
            $table->string('lot_no', 60)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 12)->default('active');          // active | quarantine | expired
            $table->dateTime('expiry_alerted_at')->nullable();

            $table->unique(['company_id', 'item_id', 'batch_no']);
            $table->index(['company_id', 'expiry_date']);
        });

        // ---- the stock ledger: every change of quantity, signed, in the item's base unit. Never edited or removed. ----
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->string('movement_type', 20);        // receipt issue transfer_in transfer_out adjustment_in adjustment_out scrap purchase_return
            $table->decimal('quantity', 15, 3);         // + in, - out
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('value', 15, 2);            // quantity × unit cost, signed
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('moved_at');
            $table->timestamp('created_at')->useCurrent();

            // A document line posts a given kind of movement once. NULL sources (manual entries) never collide.
            $table->unique(['company_id', 'source_type', 'source_id', 'source_line_id', 'movement_type'], 'stock_movement_once');
            $table->index(['item_id', 'location_id', 'moved_at']);
            $table->index(['company_id', 'moved_at']);
        });

        // ---- what is on hand now, per branch, item and batch: derived from the ledger, kept in step by StockService, rebuildable ----
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedBigInteger('batch_key')->default(0);   // the batch id, or 0 for stock without a batch (a NULL could not be unique)
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('value', 15, 2)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['company_id', 'location_id', 'item_id', 'batch_key'], 'stock_balance_unique');
            $table->index(['company_id', 'item_id']);
        });

        // ---- purchase orders ----
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('po_number', 40);
            $table->date('po_date');
            $table->date('expected_date');
            $table->foreignId('supplier_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->string('supplier_name', 200);
            $table->string('state', 10)->default('open');          // open | cancelled
            $table->text('terms')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);     // quantity × rate, before tax (GST is not worked out on purchase orders yet)
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->unique(['company_id', 'po_number']);
            $table->index(['company_id', 'approval_status', 'po_date']);
            $table->index('supplier_ledger_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->decimal('quantity', 15, 3);                     // in the line's unit
            $table->decimal('base_quantity', 15, 3);                // in the item's base unit
            $table->decimal('rate', 15, 4);                         // per line unit
            $table->decimal('amount', 15, 2);
            $table->decimal('tolerance_percent', 5, 2)->default(0); // the item's PO tolerance when the order was made

            $table->unique(['purchase_order_id', 'line_no']);
            $table->index('item_id');
        });

        // ---- goods received notes ----
        Schema::create('grns', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('grn_number', 40);
            $table->date('grn_date');
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->string('supplier_name', 200);
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->dateTime('posted_at')->nullable();              // stock was posted (claimed once)
            $table->dateTime('post_attempted_at')->nullable();
            $table->string('post_error', 500)->nullable();

            $table->unique(['company_id', 'grn_number']);
            $table->index(['company_id', 'approval_status', 'grn_date']);
            $table->index('purchase_order_id');
        });

        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('grn_id')->constrained('grns')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->decimal('accepted_quantity', 15, 3);            // in the line's unit
            $table->decimal('rejected_quantity', 15, 3)->default(0);
            $table->decimal('base_accepted', 15, 3);                // in the item's base unit
            $table->decimal('base_received', 15, 3);                // accepted + rejected, in base units: what counts against the order
            $table->decimal('unit_cost', 18, 4);                    // per base unit: the order's rate converted
            $table->string('batch_no', 60)->nullable();
            $table->string('lot_no', 60)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reject_reason', 500)->nullable();

            $table->unique(['grn_id', 'purchase_order_item_id']);
            $table->index('purchase_order_item_id');
        });

        DB::statement("ALTER TABLE batches ADD CONSTRAINT batches_status_valid CHECK (status IN ('active', 'quarantine', 'expired'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_state_valid CHECK (state IN ('open', 'cancelled'))");
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT po_items_valid CHECK (quantity > 0 AND base_quantity > 0 AND rate >= 0 AND amount >= 0)');
        DB::statement('ALTER TABLE grn_items ADD CONSTRAINT grn_items_valid CHECK (accepted_quantity >= 0 AND rejected_quantity >= 0 AND accepted_quantity + rejected_quantity > 0 AND unit_cost >= 0)');
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_not_negative CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        foreach (['grn_items', 'grns', 'purchase_order_items', 'purchase_orders', 'stock_balances', 'stock_movements', 'batches'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['tracking', 'has_expiry', 'shelf_life_days']);
        });
    }
};
