<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What to check on an item at one stage (incoming goods, in process, final). An approval master like a bill of materials: once waiting or approved it never changes.
        Schema::create('inspection_plans', function (Blueprint $table) {
            $table->id();
            $table->masterColumns();
            $table->string('name', 150);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('stage', 10);                                      // incoming | in_process | final
            $table->index(['company_id', 'item_id', 'stage']);
        });

        Schema::create('inspection_plan_parameters', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('inspection_plan_id')->constrained('inspection_plans')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('name', 120);
            $table->string('kind', 8);                                        // measure (a number between limits) | check (pass / fail)
            $table->string('unit_text', 20)->nullable();
            $table->decimal('min_value', 15, 4)->nullable();
            $table->decimal('max_value', 15, 4)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->string('method', 200)->nullable();
            $table->unique(['inspection_plan_id', 'line_no']);
        });

        // One inspection of one lot. Its parameters are copied from the plan when it starts. The state says where it stands.
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('inspection_number', 40);
            $table->foreignId('inspection_plan_id')->constrained('inspection_plans')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->string('stage', 10);
            $table->string('source_type', 20)->nullable();                    // grn | production_order | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_label', 60)->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->boolean('held_batch')->default(false);                   // this inspection put the batch on hold (so only it releases it)
            $table->decimal('lot_quantity', 15, 3);
            $table->string('state', 10)->default('open');                     // open | accepted | failed | rejected | deviation | cancelled
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->unique(['company_id', 'inspection_number']);
            $table->index(['company_id', 'state', 'created_at']);
            $table->index(['batch_id', 'state']);
        });

        Schema::create('inspection_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('inspection_id')->constrained('inspections')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('name', 120);
            $table->string('kind', 8);
            $table->string('unit_text', 20)->nullable();
            $table->decimal('min_value', 15, 4)->nullable();
            $table->decimal('max_value', 15, 4)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->decimal('measured_value', 15, 4)->nullable();
            $table->boolean('passed')->nullable();                           // worked out by the service from the limits (or the check), never typed
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->unique(['inspection_id', 'line_no']);
        });

        // Every time a batch was put on hold or released, and why. Append-only evidence.
        Schema::create('batch_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->string('action', 8);                                      // hold | release
            $table->string('reason', 300);
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedBigInteger('non_conformance_id')->nullable();
            $table->foreignId('by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('at');
            $table->index(['batch_id', 'id']);
        });

        // A non-conformance report: something that did not meet its requirement, what was decided about it, and the loss incident and corrective action it led to.
        Schema::create('non_conformances', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('ncr_number', 40);
            $table->foreignId('inspection_id')->nullable()->constrained('inspections')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity_affected', 15, 3)->default(0);
            $table->string('severity', 8);                                    // minor | major | critical
            $table->text('description');
            $table->string('state', 12)->default('open');                     // open | decided | closed
            $table->string('disposition', 20)->nullable();                    // rework | scrap | use_as_is | return_to_supplier | sort
            $table->string('disposition_note', 500)->nullable();
            $table->foreignId('dispositioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispositioned_at')->nullable();
            $table->foreignId('loss_incident_id')->nullable()->constrained('loss_incidents')->nullOnDelete();
            $table->foreignId('corrective_action_id')->nullable()->constrained('corrective_actions')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->string('closing_note', 500)->nullable();
            $table->unique(['company_id', 'ncr_number']);
            $table->index(['company_id', 'state', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformances');
        Schema::dropIfExists('batch_holds');
        Schema::dropIfExists('inspection_results');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('inspection_plan_parameters');
        Schema::dropIfExists('inspection_plans');
    }
};
