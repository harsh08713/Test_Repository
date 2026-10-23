<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- recruitment -------------------------------------------------------------------------------------------------------------

        // A position the company is hiring for. `state` is its own (draft → open → closed), so the plain active/inactive status is left out.
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withStatus: false);          // code = the opening's reference
            $table->locationColumn();
            $table->string('title', 150);
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->foreignId('hiring_manager_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('positions')->default(1);
            $table->text('description')->nullable();
            $table->string('state', 8)->default('draft');                           // draft | open | closed
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('close_reason', 300)->nullable();
            $table->index(['company_id', 'state']);
        });

        // A person who applied (or was found). One file per person; the mobile number is unique in the company so the same person is not entered twice.
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('name', 150);
            $table->string('mobile', 10)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('source', 30)->default('other');                         // referral | portal | agency | walk_in | other
            $table->string('current_employer', 150)->nullable();
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->text('notes')->nullable();
            $table->unique(['company_id', 'mobile']);
        });

        // A candidate's application to one opening, and where it stands.
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('job_opening_id')->constrained('job_openings')->restrictOnDelete();
            $table->string('stage', 10)->default('applied');                        // applied | screening | interview | offer | hired | rejected | withdrawn
            $table->string('reject_reason', 300)->nullable();
            $table->foreignId('hired_employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->unique(['candidate_id', 'job_opening_id']);
            $table->index(['job_opening_id', 'stage']);
        });

        // Every move of an application between stages. Append-only evidence.
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->string('from_stage', 10)->nullable();
            $table->string('to_stage', 10);
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->index('application_id');
        });

        // A round of interview. Only the interviewer writes the feedback.
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->unsignedTinyInteger('round')->default(1);
            $table->dateTime('scheduled_at');
            $table->string('mode', 10)->default('in_person');                       // in_person | phone | video
            $table->foreignId('interviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('state', 10)->default('scheduled');                      // scheduled | done | cancelled | no_show
            $table->unsignedTinyInteger('rating')->nullable();                      // 1 to 5
            $table->string('recommendation', 8)->nullable();                        // hire | hold | reject
            $table->text('feedback')->nullable();
            $table->index(['interviewer_id', 'state']);
            $table->index('application_id');
        });

        // The offer for an application. Money is not here: salary belongs to payroll (H3).
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('application_id')->unique()->constrained('applications')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->date('joining_date');
            $table->string('state', 10)->default('draft');                          // draft | sent | accepted | declined | withdrawn
            $table->date('responded_on')->nullable();
            $table->string('note', 300)->nullable();
        });

        // ---- performance -------------------------------------------------------------------------------------------------------------

        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withStatus: false);
            $table->string('name', 100);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('state', 8)->default('draft');                           // draft | open | closed
        });

        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('review_cycle_id')->constrained('review_cycles')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('description', 1000)->nullable();
            $table->unsignedTinyInteger('weight');                                  // percent; the goals of one person in one cycle add up to at most 100
            $table->unsignedTinyInteger('progress')->default(0);                    // percent done
            $table->index(['employee_id', 'review_cycle_id']);
        });

        // One review for each person in a cycle: their own rating, their manager's, and the final one HR fixes.
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('review_cycle_id')->constrained('review_cycles')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();   // the manager's login, fixed when the review is made
            $table->string('state', 12)->default('pending');                        // pending | self_done | manager_done | finalised
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->string('self_comments', 2000)->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->string('manager_comments', 2000)->nullable();
            $table->unsignedTinyInteger('final_rating')->nullable();
            $table->dateTime('finalised_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['employee_id', 'review_cycle_id']);
            $table->index(['review_cycle_id', 'state']);
        });

        // ---- training ----------------------------------------------------------------------------------------------------------------

        Schema::create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false, withStatus: false);
            $table->string('title', 200);
            $table->string('trainer', 150)->nullable();
            $table->string('mode', 10)->default('in_person');                       // in_person | online
            $table->string('venue', 200)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('capacity')->default(20);
            $table->string('state', 10)->default('planned');                        // planned | completed | cancelled
        });

        Schema::create('training_enrolments', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('training_program_id')->constrained('training_programs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('state', 10)->default('enrolled');                       // enrolled | completed | absent | dropped
            $table->decimal('score', 5, 1)->nullable();
            $table->unique(['training_program_id', 'employee_id']);
            $table->index(['employee_id', 'state']);
        });
    }

    public function down(): void
    {
        foreach (['training_enrolments', 'training_programs', 'performance_reviews', 'performance_goals', 'review_cycles', 'offers', 'interviews', 'application_events', 'applications', 'candidates', 'job_openings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
