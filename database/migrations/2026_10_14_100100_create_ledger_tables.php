<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One central ledger master. The type decides which columns apply (customer: kind, credit, ...; supplier:
        // lead time, credit days, ...). Only an approved, active ledger may be used by a transaction. This is the
        // party / account *master*: it posts nothing and is not a double-entry ledger.
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->masterColumns();                                   // code = account code; approval workflow columns
            $table->string('ledger_type', 12);                         // customer|supplier|bank|cash|expense|general
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->foreignId('account_group_id')->constrained('account_groups')->restrictOnDelete();
            $table->string('customer_kind', 3)->nullable();            // b2b|b2c (customers only)
            $table->char('gstin', 15)->nullable();
            $table->char('pan', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable();   // customers and suppliers
            $table->decimal('credit_limit', 15, 2)->nullable();        // customers only
            $table->unsignedSmallInteger('lead_time_days')->nullable(); // suppliers only
            $table->json('payment_preferences')->nullable();           // list of payment method codes

            // A name identifies a ledger within its type (a party may legitimately be both customer and supplier).
            $table->unique(['company_id', 'ledger_type', 'name']);
            $table->index(['company_id', 'ledger_type', 'approval_status', 'status'], 'ledgers_lookup_index');
            $table->index(['company_id', 'gstin']);
        });

        Schema::create('ledger_addresses', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->string('label', 60)->nullable();
            $table->boolean('is_billing')->default(true);
            $table->boolean('is_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->char('pin_code', 6);
            $table->char('gstin', 15)->nullable();                     // a registration specific to this address
            $table->string('phone', 20)->nullable();
            $table->index(['company_id', 'ledger_id']);
        });

        Schema::create('ledger_contacts', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('ledger_id')->constrained('ledgers')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('designation', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->index(['company_id', 'ledger_id']);
        });

        // Sensitive: the account number is encrypted at rest, masked everywhere by default and kept out of the audit
        // log. `account_number_hash` (a keyed hash) lets us fingerprint and de-duplicate without decrypting.
        Schema::create('ledger_bank_details', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('ledger_id')->unique()->constrained('ledgers')->restrictOnDelete();
            $table->string('bank_name', 120);
            $table->string('branch', 120)->nullable();
            $table->string('account_holder', 150)->nullable();
            $table->string('account_type', 12)->nullable();            // current|savings|cash_credit|overdraft
            $table->text('account_number');                            // encrypted
            $table->char('account_last4', 4);
            $table->char('account_number_hash', 64);
            $table->char('ifsc', 11);
            $table->index(['company_id', 'account_number_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_bank_details');
        Schema::dropIfExists('ledger_contacts');
        Schema::dropIfExists('ledger_addresses');
        Schema::dropIfExists('ledgers');
    }
};
