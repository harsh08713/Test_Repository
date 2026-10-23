<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- tasks ----
        //
        // Something a person has to do, personal or for the team, optionally about a record (a lead, a deal, a quotation, an order, a customer).
        // `status` is its own state: open → in_progress → done | cancelled. A recurring task makes the next one when it is done
        // (`previous_id` chains them). Who it was given to, and when it changed hands, is `task_assignments` (append-only).
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('status', 12)->default('open');
            $table->string('priority', 8)->default('normal');
            $table->locationColumn(nullable: true);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('recurrence', 8)->default('none');
            $table->date('recurrence_until')->nullable();
            $table->text('completion_note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('previous_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->dateTime('reminder_sent_at')->nullable();      // claimed by the scheduler: one reminder per task
            $table->dateTime('overdue_notified_at')->nullable();   // claimed by the scheduler: the assignee is told once it is late

            $table->index(['company_id', 'assigned_to', 'status', 'due_at'], 'tasks_agenda_index');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'reminder_sent_at', 'remind_at'], 'tasks_reminder_index');
        });

        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['task_id', 'id']);
        });

        // ---- SLA ----
        //
        // A policy says how long a kind of thing may wait: a new lead for a first response, a planned follow-up past its time, a task past its
        // deadline. It applies by lead source, team and (for tasks) priority; the most specific matching policy wins. With `business_hours` the
        // minutes are counted only inside the company's working hours, skipping its holidays.
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->string('name', 120);
            $table->string('kind', 14);
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();
            $table->string('priority', 8)->nullable();
            $table->unsignedInteger('target_minutes');
            $table->boolean('business_hours')->default(false);
            $table->boolean('escalate_to_manager')->default(true);
            $table->index(['company_id', 'kind', 'status']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->unique(['company_id', 'holiday_date']);
        });

        // One clock for each thing being timed. The sweep (`sla:check`) starts it, stops it when the thing is answered, closed, handed over or called off,
        // and notices when it runs out. `breached_at` is when it ran out; a clock that was answered late is `met` and still has `breached_at`.
        Schema::create('sla_clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sla_policy_id')->constrained('sla_policies')->restrictOnDelete();
            $table->string('kind', 14);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('due_at');
            $table->string('state', 8)->default('running');
            $table->dateTime('stopped_at')->nullable();
            $table->string('stop_reason', 16)->nullable();
            $table->dateTime('breached_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'due_at']);
            $table->index(['subject_type', 'subject_id', 'kind']);
            $table->index(['company_id', 'kind', 'state']);
        });

        // Evidence: every time a clock ran out — who was told, and when. Written once, never changed.
        Schema::create('sla_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sla_clock_id')->constrained('sla_clocks')->restrictOnDelete();
            $table->foreignId('sla_policy_id')->constrained('sla_policies')->restrictOnDelete();
            $table->string('kind', 14);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at');
            $table->dateTime('detected_at');
            $table->boolean('answered_late')->default(false);   // true when the thing was answered after its time before anyone noticed
            $table->unique('sla_clock_id');
            $table->index(['company_id', 'kind', 'detected_at']);
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_valid CHECK (status IN ('open','in_progress','done','cancelled'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_valid CHECK (priority IN ('low','normal','high','urgent'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_recurrence_valid CHECK (recurrence IN ('none','daily','weekly','monthly'))");
        DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_kind_valid CHECK (kind IN ('first_response','followup','task'))");
        DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_target_positive CHECK (target_minutes <= 525600)');
        DB::statement("ALTER TABLE sla_clocks ADD CONSTRAINT sla_clocks_state_valid CHECK (state IN ('running','met','stopped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_breaches');
        Schema::dropIfExists('sla_clocks');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('tasks');
    }
};
