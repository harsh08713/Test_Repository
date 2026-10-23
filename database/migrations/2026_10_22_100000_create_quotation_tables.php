<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- quotations ----
        //
        // One row is one *revision*: Rev 0 is the first, and every later revision is a new row with the same quotation number and
        // the next revision number — an earlier revision is never edited once it has left draft (the model refuses), it is only
        // marked superseded. `unique(company, number, revision)` is what makes two people revising the same quotation at the same
        // moment end with one new revision. A quotation comes from a deal (the usual way) or straight from an approved inquiry,
        // so `lead_id` is always known and `deal_id` is not. The customer, the address, the place of supply and every figure are
        // snapshots: the document keeps saying what it said when it was sent, whatever happens to the masters afterwards.
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('quotation_number', 40);
            $table->unsignedSmallInteger('revision_no')->default(0);
            $table->foreignId('previous_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->restrictOnDelete();
            $table->foreignId('customer_ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->string('state', 12)->default('draft');

            $table->date('quotation_date');
            $table->date('valid_until');
            $table->date('price_date');
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
            $table->foreignId('place_of_supply_state_id')->constrained('states')->restrictOnDelete();
            $table->string('supply_type', 5);
            $table->string('supplier_gstin', 15)->nullable();
            $table->string('supplier_state_code', 2)->nullable();
            $table->text('terms')->nullable();

            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('taxable_total', 15, 2)->default(0);
            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->string('accepted_note', 500)->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->string('declined_reason', 500)->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('expiry_warned_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->dateTime('superseded_at')->nullable();

            $table->unique(['company_id', 'quotation_number', 'revision_no']);
            $table->index(['company_id', 'state', 'valid_until']);
            $table->index('lead_id');
            $table->index('deal_id');
            $table->index('customer_ledger_id');
        });

        // The lines of a revision. `list_rate` is what RateService answered; `rate` is what the line is quoted at — the same, or
        // a rate somebody with `rate.override` changed (then `rate_overridden` is set and the reason is here and in the audit
        // log). Every amount is worked out by TaxService on the server and stored so the document never changes under a reader.
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('deal_item_id')->nullable()->constrained('deal_items')->nullOnDelete();
            $table->foreignId('lead_interest_id')->nullable()->constrained('lead_interests')->nullOnDelete();
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

            $table->index(['quotation_id', 'line_no']);
            $table->index('item_id');
        });

        // Where a revision has been: every move between states, with who and why. Append-only.
        Schema::create('quotation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->string('from_state', 12)->nullable();
            $table->string('to_state', 12);
            $table->string('comment', 1000)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['quotation_id', 'id']);
        });

        // Every time a revision was sent to a customer — by e-mail through the configured mail transport, or recorded as handed
        // over some other way. A failed attempt is kept too (with a message that never carries a secret). Append-only.
        Schema::create('quotation_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('to_email', 150)->nullable();
            $table->string('cc_email', 150)->nullable();
            $table->string('subject', 200)->nullable();
            $table->string('status', 10);
            $table->string('error', 500)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['quotation_id', 'id']);
        });

        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_state_valid CHECK (state IN ('draft', 'sent', 'accepted', 'declined', 'expired', 'superseded', 'cancelled'))");
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_supply_valid CHECK (supply_type IN ('intra', 'inter'))");
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_totals_valid CHECK (gross_total >= 0 AND discount_total >= 0 AND taxable_total >= 0 AND grand_total >= 0)');
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_validity_valid CHECK (valid_until >= quotation_date)');
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_quantity_valid CHECK (quantity > 0)');
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_amounts_valid CHECK (rate >= 0 AND list_rate >= 0 AND gross_amount >= 0 AND discount_amount >= 0 AND taxable_amount >= 0 AND line_total >= 0 AND discount_percent BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE quotation_dispatches ADD CONSTRAINT quotation_dispatches_channel_valid CHECK (channel IN ('email', 'manual') AND status IN ('sent', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_dispatches');
        Schema::dropIfExists('quotation_history');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
