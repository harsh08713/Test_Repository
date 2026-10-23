<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A candidate's résumé: one file on the private disk (a new one replaces the old).
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('resume_path', 255)->nullable();
            $table->string('resume_name', 200)->nullable();
            $table->unsignedInteger('resume_size')->nullable();
        });

        // When a late checklist line was last reminded about (a conditional update claims it, so a line is reminded once a week at most).
        Schema::table('employee_checklist_items', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('employee_checklist_items', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['resume_path', 'resume_name', 'resume_size']);
        });
    }
};
