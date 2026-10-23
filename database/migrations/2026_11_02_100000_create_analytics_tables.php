<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which industry a product belongs to (optional, set by Purchase). It is what makes "revenue by industry" honest: without it a sale
        // cannot be placed in an industry, and the dashboard says "Unassigned" instead of guessing.
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('industry_template_id')->nullable()->after('brand_id')->constrained('industry_templates')->restrictOnDelete();
            $table->index('industry_template_id');
        });

        // The value of an industry KPI at a moment, kept by the scheduled snapshot job so a trend can be drawn and the dashboard can say how fresh
        // a figure is. `location_key` 0 = the whole company. A snapshot is rewritten if taken again for the same day (idempotent).
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('location_key')->default(0);
            $table->foreignId('industry_template_id')->constrained('industry_templates')->restrictOnDelete();
            $table->foreignId('kpi_definition_id')->constrained('kpi_definitions')->restrictOnDelete();
            $table->unsignedSmallInteger('kpi_version');
            $table->string('period_type', 8)->default('day');
            $table->date('period_start');
            $table->decimal('value', 18, 4)->nullable();            // null = nothing to divide by (shown as "—")
            $table->decimal('numerator', 18, 4)->nullable();
            $table->decimal('denominator', 18, 4)->nullable();
            $table->dateTime('source_freshness_at');                // how recent the data behind it is
            $table->dateTime('computed_at');

            $table->unique(['company_id', 'location_key', 'kpi_definition_id', 'period_type', 'period_start'], 'kpi_snapshot_once');
            $table->index(['company_id', 'industry_template_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');

        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['industry_template_id']);
            $table->dropIndex(['industry_template_id']);
            $table->dropColumn('industry_template_id');
        });
    }
};
