<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Something that is maintained: a machine, a vehicle, an instrument. Owned by a branch; its code is typed (like an item's) and unique in the company.
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->locationColumn();
            $table->string('name', 150);
            $table->string('category', 12);                                    // machine | vehicle | instrument | building | other
            $table->string('make_model', 120)->nullable();
            $table->string('serial_no', 60)->nullable();
            $table->date('installed_on')->nullable();
            $table->string('criticality', 8)->default('medium');              // low | medium | high
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->index(['company_id', 'location_id', 'status']);
        });

        // A preventive schedule: do this to that asset every N days / weeks / months. A work order is made `lead_days` before it falls due, once.
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('checklist')->nullable();                             // one task per line
            $table->unsignedSmallInteger('every_n');
            $table->string('unit', 6);                                         // days | weeks | months
            $table->unsignedSmallInteger('lead_days')->default(3);
            $table->decimal('estimated_hours', 5, 1)->nullable();
            $table->date('next_due_on');
            $table->date('last_done_on')->nullable();
            $table->index(['status', 'next_due_on']);
        });

        // A maintenance work order: a breakdown to fix, a scheduled job, or a corrective job. Its state says where it stands; downtime, labour and parts value are worked out from the entries.
        Schema::create('maintenance_orders', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('order_number', 40);
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained('maintenance_plans')->restrictOnDelete();
            $table->date('plan_due_on')->nullable();                           // which due date of the plan this order is for
            $table->string('type', 10);                                        // breakdown | preventive | corrective
            $table->string('priority', 8)->default('normal');                  // low | normal | high | urgent
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('state', 12)->default('open');                      // open | in_progress | completed | cancelled
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stopped_at')->nullable();                        // a breakdown: when the asset stopped
            $table->dateTime('restored_at')->nullable();                       // a breakdown: when it was running again
            $table->dateTime('completed_at')->nullable();
            $table->string('completion_note', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('loss_incident_id')->nullable()->constrained('loss_incidents')->nullOnDelete();
            $table->unique(['company_id', 'order_number']);
            $table->unique(['maintenance_plan_id', 'plan_due_on']);
            $table->index(['company_id', 'state', 'due_on']);
            $table->index(['asset_id', 'type', 'state']);
        });

        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('maintenance_order_id')->constrained('maintenance_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 200);
            $table->dateTime('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['maintenance_order_id', 'line_no']);
        });

        // Spare parts taken from stock for an order, and hours worked. Append-only evidence; `entry_key` makes a double click one entry.
        Schema::create('maintenance_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('maintenance_order_id')->constrained('maintenance_orders')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('entry_key', 40);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->timestamps();
            $table->unique(['maintenance_order_id', 'entry_key']);
        });

        Schema::create('maintenance_labour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('maintenance_order_id')->constrained('maintenance_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->string('note', 200)->nullable();
            $table->string('entry_key', 40);
            $table->timestamps();
            $table->unique(['maintenance_order_id', 'entry_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_labour');
        Schema::dropIfExists('maintenance_parts');
        Schema::dropIfExists('maintenance_tasks');
        Schema::dropIfExists('maintenance_orders');
        Schema::dropIfExists('maintenance_plans');
        Schema::dropIfExists('assets');
    }
};
