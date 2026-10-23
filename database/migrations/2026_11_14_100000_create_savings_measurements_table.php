<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verified Savings Ledger: did a corrective action really save money? One measurement per corrective action (unique). It compares the actual
        // validated loss of the incident's category (at its branch, and item if it has one) in a baseline period with the same in a measurement window
        // after the action was done, normalised for volume (or for days), less what the action cost. The losses are read from LossFigures when the
        // draft is saved and again when it is submitted, and kept as the evidence the approver saw. Once submitted nothing about it changes; Finance
        // verifies it (someone other than who measured it or owns the action) or rejects it. After that the recurrence sweep may flag it.
        Schema::create('savings_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('corrective_action_id')->constrained('corrective_actions')->restrictOnDelete();
            $table->foreignId('loss_category_id')->constrained('loss_categories')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->date('baseline_from');
            $table->date('baseline_to');
            $table->date('measure_from');
            $table->date('measure_to');
            $table->decimal('baseline_loss', 15, 2);
            $table->decimal('measure_loss', 15, 2);
            $table->string('volume_unit', 30)->nullable();
            $table->decimal('baseline_volume', 15, 3)->nullable();
            $table->decimal('measure_volume', 15, 3)->nullable();
            $table->string('method', 10);                                  // volume | days — how the baseline was normalised
            $table->decimal('normalised_baseline', 15, 2);
            $table->decimal('gross_saving', 15, 2);                        // normalised baseline − loss in the window (negative = it got worse)
            $table->decimal('implementation_cost', 15, 2)->default(0);
            $table->decimal('net_saving', 15, 2);                          // gross − cost: the figure Finance verifies
            $table->text('evidence')->nullable();
            $table->string('state', 12)->default('draft');                 // draft | submitted | verified | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->dateTime('recurred_at')->nullable();
            $table->foreignId('recurrence_incident_id')->nullable()->constrained('loss_incidents')->nullOnDelete();
            $table->timestamps();

            $table->unique('corrective_action_id');
            $table->index(['company_id', 'state', 'decided_at'], 'savings_state_decided_index');
            $table->index(['company_id', 'loss_category_id', 'location_id'], 'savings_loss_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_measurements');
    }
};
