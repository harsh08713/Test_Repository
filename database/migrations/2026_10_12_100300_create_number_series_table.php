<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter per company + branch + document type + financial year.
        // location_id is NOT NULL so the unique key really is unique (NULLs never collide in MySQL).
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('financial_year', 5);          // e.g. 26-27
            $table->string('prefix', 10);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'location_id', 'document_type', 'financial_year'], 'number_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_series');
    }
};
