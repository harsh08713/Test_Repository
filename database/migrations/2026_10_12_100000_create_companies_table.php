<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            // A company is the tenant root: it has no owning company and no approval workflow.
            $table->masterColumns(withCompany: false, withCode: false, withApproval: false);
            $table->string('code', 30)->unique();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable()->index();
            $table->string('pan', 10)->nullable();
            $table->string('cin', 21)->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 2)->nullable();   // GST state code, e.g. 24 = Gujarat
            $table->string('pin_code', 6)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();

            // April–March by default; drives the financial-year segment of document numbers.
            $table->unsignedTinyInteger('financial_year_start_month')->default(4);
            $table->char('base_currency', 3)->default('INR');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
