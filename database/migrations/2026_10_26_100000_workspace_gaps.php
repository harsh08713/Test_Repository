<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A clock can be paused (the customer is the one we are waiting for): while it is, it cannot run out; resuming moves its deadline on by the
        // time it was paused. `paused_minutes` is the total so far, kept for the record.
        Schema::table('sla_clocks', function (Blueprint $table) {
            $table->dateTime('paused_at')->nullable()->after('escalated_at');
            $table->unsignedInteger('paused_minutes')->default(0)->after('paused_at');
            $table->string('pause_reason', 200)->nullable()->after('paused_minutes');
        });

        foreach (['sla_policies', 'sla_clocks', 'sla_breaches'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY kind VARCHAR(20) NOT NULL");
        }

        // Two more things can be timed: a sent quotation that nobody has answered, and a document waiting for an approver's decision.
        DB::statement('ALTER TABLE sla_policies DROP CONSTRAINT sla_policies_kind_valid');
        DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_kind_valid CHECK (kind IN ('first_response','followup','task','quotation_response','approval'))");

        // Files on a task: kept on a private disk, handed over only to someone who may open the task.
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('path', 300);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['task_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
        DB::statement('ALTER TABLE sla_policies DROP CONSTRAINT sla_policies_kind_valid');
        DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_kind_valid CHECK (kind IN ('first_response','followup','task'))");

        Schema::table('sla_clocks', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'paused_minutes', 'pause_reason']);
        });
    }
};
