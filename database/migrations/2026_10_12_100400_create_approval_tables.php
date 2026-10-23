<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuration: who must approve each level of a document type, per company.
        // No rows for a type => one implicit level, approvable by anyone holding `{form}.approve`.
        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->unsignedTinyInteger('level');
            $table->string('name', 80)->nullable();
            $table->string('approver_role')->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('due_hours')->nullable();   // optional decision deadline once the level is actionable
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'level']);
        });

        // Ledger: one row per level per submission cycle. Rows are never deleted or rewritten
        // after a decision, so the full approve/reject history is preserved.
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->morphs('approvable');
            $table->string('document_type', 40);
            $table->unsignedSmallInteger('cycle')->default(1);
            $table->unsignedTinyInteger('level');
            $table->string('level_name', 80)->nullable();
            $table->string('approver_role')->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            // Levels of one submission are decided in order, or all at once when is_parallel.
            $table->boolean('is_parallel')->default(false);
            // sha256 of the document as submitted: approval is refused if it changed since.
            $table->string('document_hash', 64)->nullable();
            $table->unsignedSmallInteger('due_hours')->nullable();
            $table->timestamp('due_at')->nullable();
            // True when decided by someone who was not the designated approver (explicit, audited override).
            $table->boolean('overridden')->default(false);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'cycle', 'level'], 'approvals_lookup');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_levels');
    }
};
