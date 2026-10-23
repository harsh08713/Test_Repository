<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The employee master. A person's *employment* state (active / on notice / exited) is its own column, so the plain active/inactive `status` is left out.
        // Linked to a login (`user_id`) when the person has one; reports to another employee (the org chart is this one column).
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withStatus: false);          // code = the employee code
            $table->locationColumn();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('work_email', 150)->nullable();
            $table->string('personal_email', 150)->nullable();
            $table->string('mobile', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();                               // male | female | other
            $table->string('marital_status', 12)->nullable();
            $table->string('blood_group', 4)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pin_code', 6)->nullable();

            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('employment_type', 12)->default('permanent');           // permanent | probation | contract | intern | consultant
            $table->date('joining_date');
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->string('employment_status', 10)->default('active')->index();    // active | notice | exited
            $table->date('notice_date')->nullable();                                // the day the resignation / termination was recorded
            $table->date('exit_date')->nullable();                                  // the last working day
            $table->string('exit_reason', 500)->nullable();

            $table->index(['company_id', 'employment_status']);
            $table->index(['company_id', 'department_id']);
            $table->index('reports_to_id');
        });

        // Whom to call. A person may have several; one is the first to call.
        Schema::create('employee_contacts', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('relationship', 50);
            $table->string('mobile', 10);
            $table->boolean('is_primary')->default(false);
            $table->index('employee_id');
        });

        // Papers kept on file (ID, offer letter, certificates). The file lives on the private disk and is handed over only through a controller that checks the right.
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('doc_type', 20);                                         // id_proof | address_proof | offer_letter | contract | certificate | other
            $table->string('title', 150);
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            $table->index(['employee_id', 'expires_on']);
        });

        // What happened to a person's employment and when. Append-only evidence: a correction is a new row.
        Schema::create('employment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('event', 20);                                            // joined | department | designation | branch | manager | type | confirmed | notice | exited | rejoined
            $table->date('effective_on');
            $table->string('from_value', 200)->nullable();
            $table->string('to_value', 200)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_events');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_contacts');
        Schema::dropIfExists('employees');
    }
};
