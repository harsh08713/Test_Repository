<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum's device tokens. One row is one signed-in device of one user: it carries what a person needs to recognise
        // the device in their list (name, last use, address, app version) and is revoked by deleting the row. Only a hash of
        // the token is stored. `tokenable_type` holds the enforced morph alias ('user'), never a class name.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name', 100);                          // the device's own name, e.g. "Ravi's Pixel"
            $table->string('device_id', 64)->nullable();          // a stable id the app keeps, so signing in again replaces the old token
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();                // ['read'] and/or ['write']
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id', 'device_id'], 'tokens_device_index');
        });

        // What a create/convert request did, remembered under the caller's Idempotency-Key so a retry (a flaky network, an
        // offline queue replaying) gets the same answer instead of a second record. Unique per user and key: the database,
        // not the application, decides which of two simultaneous requests runs. Only a successful response is kept.
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('idempotency_key', 80);
            $table->char('fingerprint', 64);                      // sha256 of method, path, company and body: the same key must mean the same request
            $table->string('method', 8);
            $table->string('path', 160);
            $table->string('state', 12)->default('started');      // started | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->index();

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('personal_access_tokens');
    }
};
