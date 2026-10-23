<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named list of rates for a kind of customer ("Dealer", "Project"). A rate with no price list is the standard
        // rate. A plain master: no approval workflow (each rate in it is approved on its own).
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
        });

        // What one unit of an item costs at one branch, for a period. Approved rates are the only ones a quotation or
        // order may use (see RateService). The period is inclusive at both ends; an open `effective_to` means "until
        // further notice". Two pending/approved rates for the same item, branch, unit and price list may not overlap
        // (checked by the model on save and submit); the rate is exclusive of GST.
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->locationColumn();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->decimal('rate', 15, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->index(['company_id', 'item_id', 'location_id', 'unit_id', 'price_list_id', 'effective_from'], 'rates_lookup_index');
            $table->index(['company_id', 'approval_status', 'status'], 'rates_state_index');
        });

        // The database refuses what the application should never write: a non-positive rate, or a period that ends
        // before it starts.
        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_rate_positive CHECK (rate > 0)');
        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_period_valid CHECK (effective_to IS NULL OR effective_to >= effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
        Schema::dropIfExists('price_lists');
    }
};
