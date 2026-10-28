<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A territory: a named patch of the map with one person responsible for it. It is a plain master (not an approval document) and says
        // who *should* work an area; it never moves a lead or a plan by itself.
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->locationColumn(nullable: true);                                       // the branch it works from (none = company-wide)
            $table->string('name', 150);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        // What a territory covers: a state, a district, a block, a PIN code or one customer. A unit of the map belongs to ONE territory
        // (the unique key on the virtual `ref`); the most specific cover wins when a place is looked up (customer, PIN, block, district, state),
        // so a district can be given to one territory with a few PIN codes carved out for another. Coverage is removed for real (a soft-deleted
        // row would still hold the key); the change is written to the territory's audit trail.
        Schema::create('territory_areas', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('territory_id')->constrained('territories')->restrictOnDelete();
            $table->string('kind', 10);                                                   // state | district | block | pin_code | customer
            $table->foreignId('state_id')->nullable()->constrained('states')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->foreignId('block_id')->nullable()->constrained('blocks')->restrictOnDelete();
            $table->char('pin_code', 6)->nullable();                                      // a PIN can sit on several locality rows, so the code itself is kept
            $table->foreignId('ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->string('ref', 10)->virtualAs('COALESCE(state_id, district_id, block_id, ledger_id, pin_code)');

            $table->unique(['company_id', 'kind', 'ref'], 'territory_areas_one_territory_per_unit');
            $table->index('territory_id');
        });

        DB::statement("ALTER TABLE territory_areas ADD CONSTRAINT territory_areas_kind_valid CHECK (kind IN ('state', 'district', 'block', 'pin_code', 'customer'))");
        DB::statement(
            'ALTER TABLE territory_areas ADD CONSTRAINT territory_areas_one_target CHECK ('
            ."(kind = 'state') = (state_id IS NOT NULL) AND (kind = 'district') = (district_id IS NOT NULL) AND (kind = 'block') = (block_id IS NOT NULL) "
            ."AND (kind = 'pin_code') = (pin_code IS NOT NULL) AND (kind = 'customer') = (ledger_id IS NOT NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_areas');
        Schema::dropIfExists('territories');
    }
};
