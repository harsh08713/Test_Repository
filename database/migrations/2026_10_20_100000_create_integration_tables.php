<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per company and lead provider (IndiaMART, Just Dial): how it is wired up and how it is doing. It holds NO
        // secret: the provider's API key and the webhook signing secret are company settings (encrypted, write-only). The
        // `public_key` is the unguessable part of the company's webhook address.
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('provider', 20);
            $table->boolean('enabled')->default(false);
            $table->string('mode', 10)->default('webhook');                        // pull | webhook | both
            $table->string('public_key', 40)->unique();
            $table->foreignId('default_location_id')->nullable()->constrained('locations')->restrictOnDelete();   // branch new leads go to
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();               // owner; none = the pool
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();             // who the leads are entered "as"
            $table->unsignedSmallInteger('pull_interval_minutes')->default(15);
            $table->unsignedSmallInteger('initial_lookback_hours')->default(24);
            $table->dateTime('cursor_at')->nullable();                             // pull watermark: everything before it has been fetched
            $table->dateTime('next_pull_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_received_at')->nullable();                      // last webhook delivery
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('failure_kind', 20)->nullable();                        // auth | rate_limited | unavailable | invalid
            $table->text('last_error')->nullable();                                // redacted

            $table->unique(['company_id', 'provider']);
        });

        // Every lead a provider handed over, with the payload as received (secrets removed) and what became of it. The row is
        // written first — before anything is created — so a delivery is never lost, and it is the dead-letter queue: a row
        // that failed keeps its payload and can be retried by hand. (company, provider, provider lead id) is unique: the same
        // enquiry delivered twice, by pull and by push, or on a retry, is one row.
        Schema::create('lead_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('provider_lead_id', 100);
            $table->string('via', 10);                                             // pull | webhook | manual
            $table->string('status', 16)->default('received');                     // received | processing | created | linked | failed
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('received_count')->default(1);           // how many times the provider delivered it
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->text('error')->nullable();                                     // redacted
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider', 'provider_lead_id'], 'lead_api_logs_provider_lead_unique');
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_mode_valid CHECK (mode IN ('pull', 'webhook', 'both'))");
        DB::statement("ALTER TABLE lead_api_logs ADD CONSTRAINT lead_api_logs_status_valid CHECK (status IN ('received', 'processing', 'created', 'linked', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_api_logs');
        Schema::dropIfExists('integration_connections');
    }
};
