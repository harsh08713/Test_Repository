<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_documents', function (Blueprint $table) {
            $table->id();
            $table->masterColumns();
            $table->locationColumn(nullable: true);
            $table->string('name');
            $table->amount('total');      // decimal(15,2)
            $table->quantity('qty');      // decimal(15,3)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_documents');
    }
};
