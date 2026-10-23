<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A team: one manager and the salespeople under them. Plain master (no approval workflow).
        Schema::create('sales_teams', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withApproval: false);
            $table->string('name');
            $table->foreignId('manager_user_id')->constrained('users')->restrictOnDelete();
            $table->unique(['company_id', 'name']);
        });

        // Who is on a team, and for how long. A person leaves a team by getting a `left_on` date (inclusive), never by
        // deletion, so a team's history — and who counted towards its targets in a past month — is kept. One open
        // membership per person and company, enforced by the database through a generated column (NULL once they have left).
        Schema::create('sales_team_members', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('sales_team_id')->constrained('sales_teams')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->unsignedBigInteger('open_user_id')->nullable()->virtualAs('IF(left_on IS NULL AND deleted_at IS NULL, user_id, NULL)');

            $table->unique(['company_id', 'open_user_id'], 'team_members_one_open_membership');
            $table->index(['sales_team_id', 'joined_on']);
            $table->index(['user_id', 'joined_on']);
        });

        // A target for one salesperson or one team over one month or quarter, by value and/or quantity, optionally for an
        // item group and/or a branch. `slot_key` makes an exact duplicate impossible even under a race (NULL when the
        // target was explicitly allowed to overlap); overlapping periods are checked by TargetService under a lock.
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->restrictOnDelete();
            $table->string('period_type', 12);                    // monthly | quarterly
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 15, 2)->nullable();   // order value, net of tax
            $table->decimal('target_quantity', 15, 3)->nullable(); // in each item's base unit
            $table->foreignId('item_group_id')->nullable()->constrained('item_groups')->restrictOnDelete();
            $table->locationColumn(nullable: true);
            $table->boolean('allow_overlap')->default(false);
            $table->string('slot_key', 40)->nullable();

            $table->unique(['company_id', 'slot_key']);
            $table->index(['company_id', 'period_start', 'period_end']);
            $table->index(['user_id', 'period_start']);
            $table->index(['sales_team_id', 'period_start']);
        });

        // One day's plan for one salesperson at one branch. `status` is the plan's own state: draft → published → cancelled.
        // Only a published plan lets the person mark attendance. One plan per person and day, ever: a cancelled plan is
        // reinstated rather than replaced, and only an unused draft is deleted.
        Schema::create('route_plans', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->locationColumn();
            $table->date('plan_date');
            $table->string('title', 150)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->nullable();   // plan-wide radius; NULL = the company default

            $table->unique(['company_id', 'user_id', 'plan_date']);
            $table->index(['company_id', 'plan_date']);
        });

        // The ordered stops of a plan: an area, a block, a PIN code or a customer, with a place on the map. A stop without
        // coordinates is a reminder; only stops with coordinates are geofences.
        Schema::create('route_plan_stops', function (Blueprint $table) {
            $table->id();
            $table->masterColumns(withCode: false, withApproval: false, withStatus: false);
            $table->foreignId('route_plan_id')->constrained('route_plans')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('stop_type', 12);                      // area | block | pin_code | customer
            $table->string('label', 150);
            $table->foreignId('block_id')->nullable()->constrained('blocks')->restrictOnDelete();
            $table->string('pin_code', 6)->nullable();
            $table->foreignId('ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_m')->nullable();  // NULL = the plan's, then the company's, radius
            $table->time('visit_from')->nullable();
            $table->time('visit_to')->nullable();
            $table->string('visit_status', 12)->default('pending'); // pending | visited | skipped

            $table->unique(['route_plan_id', 'sequence']);
        });

        // Every attempt that was refused, whatever the reason: the log of the geofence and the route-plan rule. Append-only.
        // Only valid coordinates are stored as numbers; anything else the device sent is kept as text.
        Schema::create('geofence_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('route_plan_id')->nullable()->constrained('route_plans')->restrictOnDelete();
            $table->foreignId('route_stop_id')->nullable()->constrained('route_plan_stops')->restrictOnDelete();
            $table->string('attempt_type', 16)->default('attendance');   // attendance now; lead_save in Phase 8
            $table->string('reason', 24);
            $table->string('message');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('raw_coordinates', 80)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 1)->nullable();
            $table->unsignedSmallInteger('radius_m')->nullable();
            $table->string('source', 12)->default('web');
            $table->string('client_info')->nullable();
            $table->timestamp('attempted_at');

            $table->index(['company_id', 'attempted_at']);
            $table->index(['user_id', 'attempted_at']);
        });

        // A day's attendance, marked against the day's plan from the person's own position. Append-only; one per person
        // and day. `decision` says whether the position was inside the geofence or a manager accepted it anyway.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('route_plan_id')->constrained('route_plans')->restrictOnDelete();
            $table->foreignId('route_stop_id')->nullable()->constrained('route_plan_stops')->restrictOnDelete();
            $table->date('attendance_date');
            $table->timestamp('marked_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2);
            $table->decimal('distance_m', 10, 1);
            $table->unsignedSmallInteger('radius_m');
            $table->string('decision', 24);                       // accepted | accepted_override
            $table->string('source', 12)->default('web');
            $table->string('client_info')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('override_reason')->nullable();
            $table->foreignId('violation_id')->nullable()->unique()->constrained('geofence_violations')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'user_id', 'attendance_date']);
            $table->index(['company_id', 'attendance_date']);
        });

        // The database refuses what the application should never write.
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_one_subject CHECK ((user_id IS NULL) <> (sales_team_id IS NULL))');
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_has_goal CHECK (target_value IS NOT NULL OR target_quantity IS NOT NULL)');
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_goal_positive CHECK ((target_value IS NULL OR target_value > 0) AND (target_quantity IS NULL OR target_quantity > 0))');
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_period_valid CHECK (period_end >= period_start)');
        DB::statement('ALTER TABLE route_plan_stops ADD CONSTRAINT route_stops_coordinates_paired CHECK ((latitude IS NULL) = (longitude IS NULL))');
        DB::statement('ALTER TABLE route_plan_stops ADD CONSTRAINT route_stops_coordinates_valid CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT attendance_coordinates_valid CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('geofence_violations');
        Schema::dropIfExists('route_plan_stops');
        Schema::dropIfExists('route_plans');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_team_members');
        Schema::dropIfExists('sales_teams');
    }
};
