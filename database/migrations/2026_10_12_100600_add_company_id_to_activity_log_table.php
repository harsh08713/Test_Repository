<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lets the audit viewer be tenant-scoped without joining the subject table.
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('log_name');
                $table->index(['company_id', 'created_at']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropIndex(['company_id', 'created_at']);
                $table->dropColumn('company_id');
            });
    }
};
