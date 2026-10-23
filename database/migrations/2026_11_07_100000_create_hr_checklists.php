<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company's standard list of things to do when someone joins ("onboarding") or leaves ("exit"): what, who is responsible, and by when (days after the start).
        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name', 150);
            $table->string('kind', 12);                      // onboarding | exit
            $table->string('responsible', 10);               // hr | manager | employee
            $table->unsignedSmallInteger('due_days')->default(0);
        });

        // One person's copy of those things, made when the list is started. A copy keeps its own wording, so changing the standard list later changes nothing already started.
        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('kind', 12);
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->string('title', 150);
            $table->string('responsible', 10);
            $table->date('due_on');
            $table->dateTime('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'kind', 'source_item_id'], 'employee_checklist_once');
            $table->index(['company_id', 'done_at', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('checklist_template_items');
    }
};
