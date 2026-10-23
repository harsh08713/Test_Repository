<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A place costs are collected: a plant, a department, a production line. A plain master; may sit under another.
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
        });

        // A budget for a cost centre in a month, optionally for one loss category (none = the cost centre's losses of every category).
        // An approval document: an approved budget is what budget-against-actual compares with.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withStatus: false);
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('loss_category_id')->nullable()->constrained('loss_categories')->restrictOnDelete();
            $table->unsignedBigInteger('category_key')->virtualAs('COALESCE(`loss_category_id`, 0)');
            $table->date('period_start');                                  // the first day of the month
            $table->decimal('amount', 15, 2);

            $table->unique(['company_id', 'cost_center_id', 'category_key', 'period_start'], 'budget_once');
            $table->index(['company_id', 'approval_status', 'period_start']);
        });

        // What an item cost, when that changed, and why. Append-only evidence: a standard cost edited on the item, or the moving average after a receipt.
        Schema::create('item_cost_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('method', 16);                                  // standard | moving_average
            $table->decimal('unit_cost', 18, 4)->nullable();               // per base unit; null = the standard cost was cleared
            $table->decimal('previous_cost', 18, 4)->nullable();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('effective_at');
            $table->index(['item_id', 'effective_at']);
        });

        Schema::table('loss_incidents', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('department_id')->constrained('cost_centers')->restrictOnDelete();
            $table->index(['company_id', 'cost_center_id']);
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_valid CHECK (amount >= 0)');
        DB::statement("ALTER TABLE item_cost_history ADD CONSTRAINT cost_history_method_valid CHECK (method IN ('standard', 'moving_average'))");
    }

    public function down(): void
    {
        Schema::table('loss_incidents', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['company_id', 'cost_center_id']);
            $table->dropColumn('cost_center_id');
        });

        foreach (['item_cost_history', 'budgets', 'cost_centers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
