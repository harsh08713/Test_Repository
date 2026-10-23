<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Profit Leakage Radar: something the system noticed in the operational records that looks like a loss. A finding is a *proposal*: it
        // becomes a loss incident only when a person reports it (the incident then carries the same source), and it can be dismissed with a reason.
        // One finding per source record and detector (`unique`), however many sweeps see it. The figure is an estimate worked out by
        // LossCalculationService; it is never added to any loss total until an incident's lines are validated.
        Schema::create('radar_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('detector', 40);
            $table->string('source_type', 30);                             // a LossSources type: stock_movement | batch | grn_item | sales_return
            $table->unsignedBigInteger('source_id');
            $table->string('title', 200);
            $table->string('severity', 10);                                // low | medium | high | critical
            $table->string('impact_type', 12);                             // actual | exposure — what the estimate means
            $table->decimal('estimated_amount', 15, 2);
            $table->json('evidence');                                      // source label, formula, inputs, assumptions, method, period, data as of
            $table->string('state', 12)->default('open');                  // open | reported | dismissed | resolved
            $table->dateTime('first_detected_at');
            $table->dateTime('last_seen_at');
            $table->foreignId('loss_incident_id')->nullable()->constrained('loss_incidents')->nullOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dismissed_at')->nullable();
            $table->string('dismiss_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'detector', 'source_type', 'source_id']);
            $table->index(['company_id', 'state', 'severity']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_findings');
    }
};
