<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- loss incidents ----
        //
        // A loss somebody reports: where, under which industry template (and the version it was made under), what kind, what and how much, how
        // serious, who looks into it and who answers for it. Its `state` moves only through the template's workflow (LossWorkflowService).
        // A second incident about the same source record (the same scrap, expired batch, rejection or return) is kept but linked to the first
        // (`primary_incident_id`) and its money is not counted twice.
        Schema::create('loss_incidents', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('incident_number', 40);
            $table->foreignId('industry_template_id')->constrained('industry_templates')->restrictOnDelete();
            $table->unsignedSmallInteger('template_version');
            $table->foreignId('loss_category_id')->constrained('loss_categories')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('process', 150)->nullable();
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->string('asset_ref', 100)->nullable();
            $table->string('source_type', 30)->nullable();                 // stock_movement | batch | grn_item | sales_return
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('primary_incident_id')->nullable()->constrained('loss_incidents')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->dateTime('reported_at');
            $table->decimal('quantity', 15, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->char('currency', 3)->default('INR');
            $table->string('severity', 10);                                // low | medium | high | critical
            $table->boolean('is_safety')->default(false);
            $table->boolean('is_quality')->default(false);
            $table->boolean('is_compliance')->default(false);
            $table->string('title', 200);
            $table->text('description');
            $table->json('custom_fields')->nullable();                     // validated against the template's fields of `template_version`
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('investigator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 40);
            $table->dateTime('contained_at')->nullable();
            $table->text('containment_notes')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->unique(['company_id', 'incident_number']);
            $table->index(['company_id', 'state']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['industry_template_id', 'loss_category_id']);
        });

        // The money of an incident, one figure a line. `impact_type` keeps actual loss, estimated exposure and recoverable opportunity apart —
        // they are never added together. A recalculation never edits a line: it marks it superseded and adds a new one.
        Schema::create('loss_impact_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('loss_incident_id')->constrained('loss_incidents')->restrictOnDelete();
            $table->string('impact_type', 12);                             // actual | exposure | opportunity
            $table->string('formula_key', 30);                             // FORMULAS.md §4
            $table->unsignedSmallInteger('formula_version');
            $table->json('inputs');                                        // what the formula was given
            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->decimal('amount', 15, 2);
            $table->unsignedTinyInteger('confidence_pct')->nullable();
            $table->string('target_period', 40)->nullable();
            $table->text('assumptions')->nullable();
            $table->string('validation_state', 10)->default('pending');    // pending | validated | rejected
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('loss_impact_lines')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loss_incident_id', 'impact_type']);
            $table->index(['company_id', 'impact_type', 'validation_state']);
        });

        Schema::create('root_cause_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('loss_incident_id')->constrained('loss_incidents')->restrictOnDelete();
            $table->string('method', 12);                                  // five_whys | fishbone
            $table->string('cause_category', 16)->nullable();              // man machine method material measurement environment other
            $table->text('root_cause');
            $table->text('findings')->nullable();
            $table->json('whys')->nullable();
            $table->json('contributing_factors')->nullable();
            $table->foreignId('analysed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('analysed_at');
            $table->timestamps();
            $table->index('loss_incident_id');
        });

        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->foreignId('loss_incident_id')->constrained('loss_incidents')->restrictOnDelete();
            $table->foreignId('root_cause_analysis_id')->nullable()->constrained('root_cause_analyses')->nullOnDelete();
            $table->string('action_number', 40);
            $table->string('action_type', 12);                             // corrective | preventive
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            $table->decimal('expected_benefit_amount', 15, 2)->nullable();
            $table->decimal('baseline_value', 15, 2)->nullable();
            $table->string('state', 12)->default('open');                  // open | in_progress | done | verified | rejected
            $table->dateTime('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->decimal('verified_result_amount', 15, 2)->nullable();
            $table->dateTime('escalated_at')->nullable();

            $table->unique(['company_id', 'action_number']);
            $table->index(['company_id', 'state', 'due_date']);
            $table->index('loss_incident_id');
            $table->index('owner_id');
        });

        // State history of anything with a workflow (loss incidents, corrective actions). Append-only evidence.
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'id']);
        });

        DB::statement("ALTER TABLE loss_incidents ADD CONSTRAINT loss_incidents_severity_valid CHECK (severity IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE loss_impact_lines ADD CONSTRAINT loss_impact_type_valid CHECK (impact_type IN ('actual', 'exposure', 'opportunity'))");
        DB::statement("ALTER TABLE loss_impact_lines ADD CONSTRAINT loss_impact_validation_valid CHECK (validation_state IN ('pending', 'validated', 'rejected'))");
        DB::statement('ALTER TABLE loss_impact_lines ADD CONSTRAINT loss_impact_amount_valid CHECK (amount >= 0 AND (confidence_pct IS NULL OR confidence_pct BETWEEN 0 AND 100))');
        DB::statement("ALTER TABLE root_cause_analyses ADD CONSTRAINT rca_method_valid CHECK (method IN ('five_whys', 'fishbone'))");
        DB::statement("ALTER TABLE corrective_actions ADD CONSTRAINT capa_type_valid CHECK (action_type IN ('corrective', 'preventive'))");
        DB::statement("ALTER TABLE corrective_actions ADD CONSTRAINT capa_state_valid CHECK (state IN ('open', 'in_progress', 'done', 'verified', 'rejected'))");
    }

    public function down(): void
    {
        foreach (['workflow_events', 'corrective_actions', 'root_cause_analyses', 'loss_impact_lines', 'loss_incidents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
