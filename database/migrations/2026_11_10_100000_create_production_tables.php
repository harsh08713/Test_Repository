<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bill of materials: what it takes to make `output_quantity` of a finished item. An approval master (like a rate): only an approved, active one can start a production order.
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->masterColumns();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('output_quantity', 15, 3)->default(1);          // in the item's base unit
            $table->index(['company_id', 'item_id']);
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('bom_id')->constrained('boms')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);                              // per `output_quantity`, in the component's base unit
            $table->decimal('scrap_percent', 5, 2)->default(0);              // expected wastage, added to what is required
            $table->unique(['bom_id', 'line_no']);
            $table->unique(['bom_id', 'item_id']);
        });

        // A production order: make `planned_quantity` of one item at one branch from one bill of materials. Its state says where it stands; what was issued and produced is worked out from the entries.
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->string('order_number', 40);
            $table->foreignId('bom_id')->constrained('boms')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->decimal('planned_quantity', 15, 3);
            $table->date('planned_start');
            $table->date('planned_end');
            $table->string('state', 12)->default('draft');                  // draft | released | in_progress | completed | cancelled
            $table->dateTime('released_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('completion_note', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'state', 'planned_start']);
        });

        // What the order requires, copied from the bill of materials when the order was made (a later change of the bill moves nothing already planned).
        Schema::create('production_components', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('item_name', 200);
            $table->decimal('required_quantity', 15, 3);                     // base units, scrap allowance included
            $table->unique(['production_order_id', 'line_no']);
        });

        // Material taken from stock for an order. Append-only evidence: a mistake is a note and a stock correction, never an edit. `entry_key` makes a double click one entry.
        Schema::create('production_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_component_id')->constrained('production_components')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('entry_key', 40);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->timestamps();
            $table->unique(['production_order_id', 'entry_key']);
        });

        // Finished goods (and rejects) reported for an order. Append-only.
        Schema::create('production_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->decimal('good_quantity', 15, 3)->default(0);
            $table->decimal('rejected_quantity', 15, 3)->default(0);
            $table->string('reject_reason', 300)->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('unit_cost', 15, 4)->default(0);                // material cost per good unit when it was received
            $table->string('entry_key', 40);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->unique(['production_order_id', 'entry_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_outputs');
        Schema::dropIfExists('production_issues');
        Schema::dropIfExists('production_components');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
    }
};
