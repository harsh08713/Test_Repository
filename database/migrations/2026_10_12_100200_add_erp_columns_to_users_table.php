<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_company_id')->nullable()->after('password')
                ->constrained('companies')->nullOnDelete();
            $table->string('phone', 20)->nullable()->after('email');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();
        });

        // Companies a user may work in. `all_locations` = unrestricted within that company.
        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('all_locations')->default(false);
            $table->timestamps();

            $table->primary(['company_id', 'user_id']);
            $table->index('user_id');
        });

        // Branches a user is restricted to (ignored when company_user.all_locations is set).
        Schema::create('location_user', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['location_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('company_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_company_id');
            $table->dropColumn(['phone', 'is_active', 'last_login_at']);
        });
    }
};
