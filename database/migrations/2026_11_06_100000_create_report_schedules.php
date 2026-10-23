<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A report a person has asked to be e-mailed to them on a schedule. It runs as that person; `next_run_at` is what a run claims with a conditional update.
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report_key', 60);
            $table->string('format', 5);
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->unsignedTinyInteger('send_hour')->default(7);
            $table->string('date_range', 20)->default('none');
            $table->json('filters');
            $table->dateTime('next_run_at');
            $table->dateTime('last_run_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('paused_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['active', 'next_run_at']);
            $table->index(['company_id', 'user_id']);
        });

        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_frequency_check CHECK (frequency IN ('daily','weekly','monthly'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN ('xlsx','pdf'))");

        Schema::table('report_exports', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropColumn('schedule_id');
        });
        Schema::dropIfExists('report_schedules');
    }
};
