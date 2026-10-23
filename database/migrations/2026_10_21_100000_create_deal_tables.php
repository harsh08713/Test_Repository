<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- an inquiry from an existing customer is a lead of a second kind ----
        //
        // `lead_type` tells a new lead (somebody who might buy) from an existing-customer inquiry (a customer already in the
        // ledger master asking for items). The inquiry points at its approved customer ledger and goes through the approval
        // workflow, so the leads table gains the approval columns every approvable record has; a new lead leaves them at
        // 'draft' for good and nothing ever submits it.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('lead_type', 10)->default('new');
            $table->foreignId('customer_ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->string('approval_status', 16)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->index(['company_id', 'lead_type', 'stage']);
        });

        // What the customer expects to pay, per line of an inquiry (a new lead's items of interest are never priced).
        Schema::table('lead_interests', function (Blueprint $table) {
            $table->decimal('expected_rate', 15, 2)->nullable();
        });

        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_type_valid CHECK (lead_type IN ('new', 'existing'))");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_inquiry_names_customer CHECK ((lead_type = 'existing') = (customer_ledger_id IS NOT NULL))");
        DB::statement('ALTER TABLE lead_interests ADD CONSTRAINT lead_interests_rate_valid CHECK (expected_rate IS NULL OR expected_rate >= 0)');

        // ---- deals ----

        // A deal is a qualified lead taken up as a piece of business: what is wanted (its lines), for whom, how likely and by
        // when. It is made only by converting a lead — `lead_id` is unique, so a lead converts once whatever races — and it
        // keeps a snapshot of who the lead was. It is an approval document: its lines and customer are what the approver
        // signs off on; its stage, probability and close date move on without a new approval. `expected_value` is always
        // worked out from the lines on the server. A deal is never deleted: a deal that will not happen is marked lost.
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->locationColumn();
            $table->string('deal_number', 40);
            $table->date('deal_date');
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('customer_ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->string('party_name', 200);
            $table->string('contact_person', 150)->nullable();
            $table->string('mobile', 10)->nullable();
            $table->string('email', 150)->nullable();
            $table->date('expected_close_date');
            $table->unsignedTinyInteger('probability')->default(10);
            $table->decimal('expected_value', 15, 2)->default(0);
            $table->string('stage', 16)->default('discovery');
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->restrictOnDelete();
            $table->text('lost_remarks')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();

            $table->unique(['company_id', 'deal_number']);
            $table->unique('lead_id');
            $table->index(['company_id', 'stage', 'assigned_to']);
            $table->index(['company_id', 'expected_close_date']);
            $table->index('customer_ledger_id');
        });

        // The lines of a deal: an approved item, the unit it is sold in, how many and the rate expected. `amount` is
        // quantity × rate, rounded half up to paise, and is computed on the server. `lead_interest_id` keeps the link to
        // the line of the lead it came from.
        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('lead_interest_id')->nullable()->constrained('lead_interests')->nullOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('expected_rate', 15, 2);
            $table->decimal('amount', 15, 2);

            $table->index(['deal_id', 'line_no']);
            $table->index('item_id');
        });

        // Every move of a deal between stages (and its conversion from the lead, as the first entry). Append-only.
        Schema::create('deal_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['deal_id', 'id']);
        });

        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_probability_valid CHECK (probability BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_value_valid CHECK (expected_value >= 0)');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_stage_valid CHECK (stage IN ('discovery', 'negotiation', 'quotation', 'won', 'lost'))");
        DB::statement('ALTER TABLE deal_items ADD CONSTRAINT deal_items_quantity_valid CHECK (quantity > 0)');
        DB::statement('ALTER TABLE deal_items ADD CONSTRAINT deal_items_rate_valid CHECK (expected_rate >= 0 AND amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_history');
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('deals');

        DB::statement('ALTER TABLE lead_interests DROP CONSTRAINT lead_interests_rate_valid');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_inquiry_names_customer');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_type_valid');

        Schema::table('lead_interests', function (Blueprint $table) {
            $table->dropColumn('expected_rate');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'lead_type', 'stage']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('customer_ledger_id');
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['lead_type', 'approval_status', 'approved_at']);
        });
    }
};
