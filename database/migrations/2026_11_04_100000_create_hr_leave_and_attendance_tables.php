<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- leave -------------------------------------------------------------------------------------------------------------------

        // A kind of leave and its rules. Days are in halves (decimal 5,1).
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name', 100);
            $table->boolean('is_paid')->default(true);
            $table->decimal('annual_quota', 5, 1)->default(0);
            $table->string('accrual', 8)->default('yearly');                       // yearly (all at the start of the year) | monthly | none (HR grants it)
            $table->decimal('carry_forward_max', 5, 1)->default(0);                // how much of an unused balance moves into the next year; the rest lapses
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_negative')->default(false);                     // unpaid leave: the balance may go below zero
            $table->unsignedSmallInteger('max_days_per_request')->nullable();
        });

        // The leave ledger: every day granted, taken, given back or lapsed. Append-only; the balance is the sum. `period_key` makes each entry happen once
        // (accrual 'Y2026' / 'M2026-10', carry-forward 'C2026', a request 'R12'), so a retry or a second run writes nothing twice.
        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('leave_year');                            // the year the financial year started in
            $table->string('entry_type', 12);                                      // opening | accrual | taken | reversal | adjustment | lapse
            $table->decimal('days', 6, 1);                                         // signed
            $table->string('period_key', 30);
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->date('entry_date');
            $table->string('remarks', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->unique(['employee_id', 'leave_type_id', 'entry_type', 'period_key'], 'leave_entry_once');
            $table->index(['employee_id', 'leave_type_id', 'leave_year']);
        });

        // A request for leave. Decided by the employee's manager (or someone above, or HR); never by the person themself.
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('half_day', 6)->nullable();                              // first | second (a single day only)
            $table->decimal('days', 4, 1);                                          // working days, after holidays and weekly offs
            $table->string('reason', 500);
            $table->string('state', 10)->default('pending');                        // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->index(['employee_id', 'from_date']);
            $table->index(['company_id', 'state']);
        });

        // ---- attendance --------------------------------------------------------------------------------------------------------------

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->unsignedSmallInteger('full_day_minutes')->default(480);        // worked at least this long: present
            $table->unsignedSmallInteger('half_day_minutes')->default(240);        // at least this long: half day
            $table->boolean('is_default')->default(false);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('employment_type')->constrained('shifts')->nullOnDelete();
        });

        // Every punch in or out. Append-only evidence: a correction is a new punch (source "regularised") that points at the request that allowed it.
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->dateTime('punched_at');
            $table->string('direction', 3);                                         // in | out
            $table->string('source', 12)->default('web');                           // web | regularised
            $table->unsignedBigInteger('regularisation_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at');
            $table->index(['employee_id', 'punched_at']);
        });

        // What a day came to for a person, worked out by the day-close from the punches, the leave, the holidays and the weekly off. Rewritten when a
        // regularisation changes the punches; the punches themselves are the evidence.
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 10);                                           // present | half_day | absent | leave | holiday | weekly_off
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->decimal('leave_days', 3, 1)->default(0);
            $table->boolean('missing_out')->default(false);                         // punched in, never out
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->dateTime('closed_at');
            $table->unique(['employee_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });

        // "I forgot to punch": the person gives the times, the manager (or HR) agrees, and two punches are added.
        Schema::create('attendance_regularisations', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->locationColumn();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->time('in_time');
            $table->time('out_time');
            $table->string('reason', 500);
            $table->string('state', 10)->default('pending');                        // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->index(['employee_id', 'work_date']);
            $table->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularisations');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_punches');
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('shift_id'));
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_ledger');
        Schema::dropIfExists('leave_types');
    }
};
