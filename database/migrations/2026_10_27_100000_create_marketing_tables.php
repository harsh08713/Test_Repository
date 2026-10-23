<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- message templates ----
        //
        // The words of a message, with {{variables}} from a fixed list. A template is an approval document: only an approved template can be sent.
        // `purpose` says what kind of message it is — marketing (needs the person's consent) or transactional (a confirmation or a reminder about
        // something they asked for: it needs only that they have not opted out).
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false);
            $table->string('name', 150);
            $table->string('channel', 10);
            $table->string('purpose', 14)->default('marketing');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('provider_template_id', 100)->nullable();   // a WhatsApp template registered with the provider (Phase 16)
            $table->index(['company_id', 'channel', 'status']);
        });

        // ---- campaigns ----
        //
        // A campaign is an approval document (its audience, its steps and its words are what the approver signs) with its own run state. It is made
        // by someone, approved by someone else, launched once, and then runs by itself: members are chosen when it is launched, and each step goes
        // out to each member at most once.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withStatus: false);
            $table->string('state', 10)->default('draft');
            $table->locationColumn();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->text('objective')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->string('audience_kind', 10);
            $table->json('audience');
            $table->json('exit_stages')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->restrictOnDelete();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->dateTime('launched_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->index(['company_id', 'state', 'start_date']);
        });

        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('delay_hours')->default(0);
            $table->foreignId('message_template_id')->constrained('message_templates')->restrictOnDelete();
            $table->unique(['campaign_id', 'position']);
        });

        Schema::create('campaign_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->string('subject_type', 10);
            $table->unsignedBigInteger('subject_id');
            $table->string('name', 200);
            $table->string('email', 150)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('status', 10)->default('active');
            $table->string('exit_reason', 30)->nullable();
            $table->unsignedSmallInteger('next_step')->default(1);
            $table->dateTime('next_due_at')->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();   // the lead this member became (or was)
            $table->timestamps();
            $table->unique(['campaign_id', 'subject_type', 'subject_id']);
            $table->index(['status', 'next_due_at']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'mobile']);
        });

        // ---- the outbox ----
        //
        // Every message the system sends or refuses to send, with why. `sent` means the mail server accepted it — never "delivered" or "read": those need a
        // provider's report, and there is none here. A message to a channel with no provider connected is kept as `not_connected` rather than pretended.
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('campaign_member_id')->nullable()->constrained('campaign_members')->restrictOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained('message_templates')->restrictOnDelete();
            $table->unsignedBigInteger('workflow_run_id')->nullable();
            $table->string('channel', 10);
            $table->string('purpose', 14);
            $table->string('subject_type', 10)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('to_name', 200)->nullable();
            $table->string('to_address', 150);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('status', 14)->default('queued');
            $table->string('failure', 300)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('idempotency_key', 100);
            $table->string('provider_ref', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'channel', 'to_address', 'queued_at']);
            $table->index(['status', 'queued_at']);
        });

        // ---- consent ----
        //
        // What each person has agreed to or refused, channel by channel: a list of events, never edited. The latest event for an address and channel is
        // the answer. An opt-out always wins over an earlier opt-in.
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('address', 150);
            $table->string('status', 10);
            $table->string('source', 20);
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'channel', 'address', 'id'], 'consents_lookup_index');
        });

        // ---- workflows ----
        //
        // A workflow is a name, a state and a trigger; what it does lives in its versions (immutable: a change is a new version). It runs as the person
        // who last saved it, so everything it does is checked against what that person may do.
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('state', 10)->default('draft');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('trigger', 24);
            $table->boolean('dry_run')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('run_as')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('max_runs_per_hour')->default(120);
            $table->dateTime('activated_at')->nullable();
            $table->index(['company_id', 'state', 'trigger']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('definition');
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workflow_id', 'version']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->restrictOnDelete();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('trigger_key', 120);
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 10);
            $table->string('reason', 300)->nullable();
            $table->json('results')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->dateTime('executed_at');
            $table->timestamps();
            $table->unique(['workflow_id', 'trigger_key']);
            $table->index(['workflow_id', 'status']);
            $table->index(['company_id', 'executed_at']);
        });

        // ---- where a lead came from ----
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('lead_source_id')->constrained('campaigns')->restrictOnDelete();
            $table->json('utm')->nullable()->after('campaign_id');
            $table->string('attribution', 10)->nullable()->after('utm');   // manual | link | inferred
            $table->index(['company_id', 'campaign_id']);
        });

        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_channel_valid CHECK (channel IN ('email','whatsapp','sms'))");
        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_purpose_valid CHECK (purpose IN ('marketing','transactional'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_state_valid CHECK (state IN ('draft','running','paused','completed','cancelled'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_audience_kind_valid CHECK (audience_kind IN ('leads','customers'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_dates_valid CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE campaign_members ADD CONSTRAINT campaign_members_status_valid CHECK (status IN ('active','completed','exited','suppressed'))");
        DB::statement("ALTER TABLE communications ADD CONSTRAINT communications_status_valid CHECK (status IN ('queued','sending','sent','failed','suppressed','not_connected','cancelled'))");
        DB::statement("ALTER TABLE communications ADD CONSTRAINT communications_channel_valid CHECK (channel IN ('email','whatsapp','sms'))");
        DB::statement("ALTER TABLE contact_consents ADD CONSTRAINT contact_consents_status_valid CHECK (status IN ('opted_in','opted_out'))");
        DB::statement("ALTER TABLE workflows ADD CONSTRAINT workflows_state_valid CHECK (state IN ('draft','active','paused'))");
        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_valid CHECK (status IN ('running','ok','failed','skipped','dry_run'))");
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropIndex(['company_id', 'campaign_id']);
            $table->dropColumn(['campaign_id', 'utm', 'attribution']);
        });

        foreach (['workflow_runs', 'workflow_versions', 'workflows', 'contact_consents', 'communications', 'campaign_members', 'campaign_steps', 'campaigns', 'message_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
