<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The item master. Three departments fill it in turn (Purchase → Inventory → approval), so the item has no
        // code until Inventory assigns one, and `workflow_stage` says whose turn it is. `approval_status` is the
        // shared approval engine's state; the model keeps the two consistent (see Item).
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false);
            $table->string('code', 30)->nullable();                       // assigned in stage 2
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('item_group_id')->constrained('item_groups')->restrictOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('po_tolerance_percent', 5, 2)->default(0);    // allowed over-receipt against a PO quantity
            $table->string('hsn_code', 8)->nullable();                    // filled in before stage 1 is complete
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->string('workflow_stage', 20)->default('purchase_draft');

            // NULLs do not collide, so any number of items may still be waiting for a code.
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'workflow_stage', 'approval_status'], 'items_stage_index');
            $table->index(['company_id', 'item_group_id']);
        });

        // Alternative units: "1 Box = 12 Nos" is a row with unit = Box and factor = 12 (base units in one of this unit).
        // Rows are deleted outright rather than soft-deleted so the unique keys can be reused.
        Schema::create('item_uoms', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->unique(['item_id', 'unit_id']);
        });

        // Other names the item is known by (searched by lookups). An alias is unique within the company.
        Schema::create('item_aliases', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('alias', 150);
            $table->unique(['company_id', 'alias']);
            $table->index('item_id');
        });

        // Stock levels per branch, in base units: minimum <= reorder level <= maximum (checked in the service).
        Schema::create('item_location_parameters', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->quantity('minimum_stock');
            $table->quantity('reorder_level');
            $table->quantity('maximum_stock');
            $table->unique(['item_id', 'location_id']);
        });

        // Who moved the item between stages, when, and why. Append-only: a correction is a new row.
        Schema::create('item_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('event', 24);                                  // created|sent_to_inventory|returned_to_purchase|submitted|approved|rejected|reopened
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20);
            $table->string('approval_status', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stage_history');
        Schema::dropIfExists('item_location_parameters');
        Schema::dropIfExists('item_aliases');
        Schema::dropIfExists('item_uoms');
        Schema::dropIfExists('items');
    }
};
