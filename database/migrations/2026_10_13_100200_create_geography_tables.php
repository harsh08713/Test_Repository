<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // State → District → Block → PIN code. Company-scoped like every other master, so one company's import
        // can never alter another's data. Districts, blocks and PIN rows have no natural code in the source
        // data, so they are keyed by name (documented exception to the "code on every master" convention).
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);   // code = short code, e.g. GJ
            $table->char('gst_code', 2);                   // GST state code, e.g. 24
            $table->string('name');
            $table->unique(['company_id', 'gst_code']);
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withCode: false);
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->string('name');
            $table->unique(['company_id', 'state_id', 'name']);
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withCode: false);
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->string('name');
            $table->unique(['company_id', 'district_id', 'name']);
        });

        // One row per PIN + district + locality. A PIN can span several localities (post offices) and blocks.
        // `area` is NOT NULL ('' = whole PIN) so the unique key is a real one — NULLs never collide in MySQL.
        Schema::create('pin_codes', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withCode: false);
            $table->char('pin_code', 6);
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('block_id')->nullable()->constrained('blocks')->restrictOnDelete();
            $table->string('area', 150)->default('');
            $table->unique(['company_id', 'pin_code', 'district_id', 'area'], 'pin_codes_unique_locality');
            $table->index(['company_id', 'pin_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_codes');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('states');
    }
};
