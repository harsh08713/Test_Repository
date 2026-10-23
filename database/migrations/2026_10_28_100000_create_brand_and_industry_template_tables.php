<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Brands of the company's products. A plain master for now (see docs/loss-intelligence.md decision 1).
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->text('description')->nullable();
            $table->index(['company_id', 'name']);
        });

        // ---- Industry templates: system data shared by every company ------------------------------------------------
        Schema::create('industry_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        // Every child row belongs to one version of its template, so a company pinned to version 1 keeps seeing version 1.
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('code', 60);
            $table->string('name');
            $table->string('formula_key', 60);               // FORMULAS.md §5
            $table->string('numerator_desc')->nullable();
            $table->string('denominator_desc')->nullable();
            $table->string('unit', 20)->default('%');
            $table->decimal('multiplier', 18, 4)->default(1);
            $table->string('direction', 16)->default('higher_better');   // higher_better | lower_better
            $table->decimal('threshold_green', 18, 4)->nullable();
            $table->decimal('threshold_amber', 18, 4)->nullable();
            $table->decimal('threshold_red', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'code'], 'kpi_template_version_code');
        });

        Schema::create('loss_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('loss_categories')->nullOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('default_formula_key', 60)->nullable();   // FORMULAS.md §4
            $table->string('default_severity', 16)->default('medium');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'code'], 'loss_category_template_version_code');
        });

        Schema::create('template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('key', 60);
            $table->string('label');
            $table->string('type', 20);                       // text number decimal date datetime select multiselect boolean
            $table->string('unit', 20)->nullable();
            $table->json('options')->nullable();              // select / multiselect choices
            $table->json('validation_rules')->nullable();     // {"min": 0, "max": 100, "max_length": 200}
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('group', 60)->nullable();
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'key'], 'template_field_version_key');
        });

        // template null = the default workflow every template without its own uses.
        Schema::create('workflow_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->nullable()->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('code', 40);
            $table->string('name');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_final')->default(false);
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'code'], 'workflow_state_template_version_code');
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->nullable()->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('from_state', 40);
            $table->string('to_state', 40);
            $table->string('action', 40);
            $table->string('required_permission', 60)->nullable();
            $table->boolean('requires_comment')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'from_state', 'to_state'], 'workflow_transition_unique');
        });

        Schema::create('template_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('report_key', 60);
            $table->string('name');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['industry_template_id', 'version', 'report_key'], 'template_report_version_key');
        });

        // ---- Per company -----------------------------------------------------------------------------------------
        Schema::create('company_industry_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->restrictOnDelete();
            $table->unsignedSmallInteger('version_pinned');
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('enabled_at');
            $table->timestamps();
            $table->unique(['company_id', 'industry_template_id'], 'company_template_unique');
        });

        // A company's own choices for a template (today: KPI thresholds). Never changes the shared rows.
        Schema::create('industry_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('industry_template_id')->constrained('industry_templates')->cascadeOnDelete();
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'industry_template_id', 'key'], 'industry_setting_unique');
        });
    }

    public function down(): void
    {
        foreach (['industry_settings', 'company_industry_templates', 'template_reports', 'workflow_transitions', 'workflow_states', 'template_fields', 'loss_categories', 'kpi_definitions', 'industry_templates', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
