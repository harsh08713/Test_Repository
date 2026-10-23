<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a WhatsApp or SMS provider later reports about a message it accepted. `sent_at` stays "the provider accepted it".
        Schema::table('communications', function (Blueprint $table) {
            $table->dateTime('delivered_at')->nullable()->after('sent_at');
            $table->dateTime('read_at')->nullable()->after('delivered_at');
            $table->index(['company_id', 'channel', 'provider_ref'], 'communications_provider_ref_index');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex('communications_provider_ref_index');
            $table->dropColumn(['delivered_at', 'read_at']);
        });
    }
};
