<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company's payment options (cash, cheque, NEFT, UPI, ... and whatever else it adds). Customers and
        // suppliers record which of them they prefer. A plain master: no approval workflow.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
