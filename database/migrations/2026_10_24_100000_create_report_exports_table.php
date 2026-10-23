<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One asked-for export of a report. The file is made later by a queue worker, kept on a private disk for a few days, and handed over
        // only to the person who asked for it (and only while they may still export that report).
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report', 60);
            $table->string('format', 5);
            $table->string('status', 10)->default('queued');
            $table->json('filters');
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'id']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('xlsx','pdf'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('queued','running','ready','failed','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
