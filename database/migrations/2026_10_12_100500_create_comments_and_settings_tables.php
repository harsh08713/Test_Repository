<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comments on any record, so users without edit rights (e.g. Operations on Item Master) can still annotate it.
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->morphs('commentable');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            // internal = staff only; customer = may be shown on the customer portal (later phase).
            $table->string('visibility', 16)->default('internal');
            $table->timestamps();
            $table->softDeletes();
        });

        // Per-company settings. Secrets (API keys) are stored encrypted.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('comments');
    }
};
