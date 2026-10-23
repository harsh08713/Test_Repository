<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plain masters: active/inactive, no approval workflow (the brief designates only ledgers, items,
        // rates and the sales documents as approvable).
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->index('name');
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->index('name');
        });

        // Units of measure. `decimal_allowed` decides whether quantities in this unit may be fractional.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->boolean('decimal_allowed')->default(false);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
