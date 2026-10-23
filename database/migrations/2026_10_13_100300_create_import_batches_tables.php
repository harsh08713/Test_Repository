<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per uploaded spreadsheet, whatever is being imported (geography now; leads, route plans later).
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('importer', 40);                          // key of the Importer class, e.g. "geography"
            $table->string('original_filename');
            $table->string('stored_path');                           // private disk, randomised name
            $table->string('status', 16)->default('uploaded');      // uploaded|checking|checked|queued|processing|completed|failed|cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('summary')->nullable();                     // importer-specific counters, e.g. districts created
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'importer', 'status']);
        });

        // Every rejected row, with the reasons and the original cells, so nothing is ever dropped silently.
        Schema::create('import_batch_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');                   // row in the spreadsheet (heading row = 1)
            $table->string('phase', 8);                              // check | apply
            $table->json('messages');
            $table->json('raw');
            $table->index(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_errors');
        Schema::dropIfExists('import_batches');
    }
};
