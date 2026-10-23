<?php

use App\Modules\CRM\Actions\SeedCrmDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- the CRM's own small masters (plain masters: no approval workflow) ----

        // Where a lead came from. The standard ones (is_system) are relied on by importers and integrations.
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->boolean('is_system')->default(false);
        });

        // What came of a follow-up, and what that means for the lead.
        Schema::create('followup_outcomes', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('counts_as_contact')->default(false);    // the person was actually reached
            $table->boolean('needs_next_followup')->default(false);  // only makes sense with a date for the next step
        });

        Schema::create('lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
        });

        // ---- leads ----

        // A new lead. It belongs to a branch (which numbers it) and to the person it is assigned to; `stage` says where it
        // stands (it has no active/inactive status). `mobile` holds the bare ten digits, which is how duplicates are spotted;
        // it is nullable because a marketplace lead may arrive without one (it is then not "complete").
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('lead_number', 40);
            $table->date('lead_date');
            $table->foreignId('lead_source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('contact_person', 150)->nullable();
            $table->string('mobile', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->foreignId('block_id')->nullable()->constrained('blocks')->restrictOnDelete();
            $table->string('pin_code', 6)->nullable();
            $table->text('requirement')->nullable();
            $table->decimal('expected_value', 15, 2)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();
            $table->string('stage', 16)->default('new');
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->restrictOnDelete();
            $table->text('lost_remarks')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->dateTime('next_followup_at')->nullable();      // derived: the earliest planned follow-up
            $table->dateTime('last_contacted_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();        // where a field entry was made (Phase 8)
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_complete')->default(true);
            $table->string('entry_channel', 16)->default('manual');
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();

            $table->unique(['company_id', 'lead_number']);
            $table->index(['company_id', 'mobile']);
            $table->index(['company_id', 'stage', 'assigned_to']);
            $table->index(['company_id', 'assigned_to', 'next_followup_at']);
            $table->index(['company_id', 'lead_date']);
        });

        // What the lead is interested in: an item of the master, or a description in words. Rows are removed outright.
        Schema::create('lead_interests', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->string('description', 200)->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->index('lead_id');
        });

        // Every change of hands of a lead: from whom, to whom, by whom, when and why. Append-only.
        Schema::create('lead_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();
            $table->foreignId('to_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('transferred_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'id']);
        });

        // Every move of a lead between stages. Append-only.
        Schema::create('lead_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'id']);
        });

        // ---- follow-ups ----

        // Something to do with a lead at a time. `status` is its own state: pending → done (with an outcome) | cancelled.
        // It hangs on its subject through a morph, so deals, quotations and customers can have follow-ups later.
        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('status', 12)->default('pending');
            $table->locationColumn(nullable: true);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->dateTime('scheduled_at');
            $table->string('type', 12);
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('outcome_id')->nullable()->constrained('followup_outcomes')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_id')->nullable()->constrained('followups')->nullOnDelete();
            $table->dateTime('reminder_sent_at')->nullable();   // claimed by the scheduler: one reminder per follow-up
            $table->dateTime('escalated_at')->nullable();       // claimed by the scheduler: the manager is told once

            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['company_id', 'assigned_to', 'status', 'scheduled_at'], 'followups_agenda_index');
            $table->index(['status', 'reminder_sent_at', 'scheduled_at'], 'followups_reminder_index');
        });

        DB::statement('ALTER TABLE lead_interests ADD CONSTRAINT lead_interests_names_something CHECK (item_id IS NOT NULL OR description IS NOT NULL)');
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_coordinates_paired CHECK ((latitude IS NULL) = (longitude IS NULL))');
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_expected_value_valid CHECK (expected_value IS NULL OR expected_value >= 0)');

        // Companies that already exist get the standard sources, outcomes and lost reasons too.
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            app(SeedCrmDefaults::class)->run((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('followups');
        Schema::dropIfExists('lead_stage_history');
        Schema::dropIfExists('lead_transfers');
        Schema::dropIfExists('lead_interests');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('followup_outcomes');
        Schema::dropIfExists('lead_sources');
    }
};
