<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Analytics\Services\KpiSnapshotService;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\AttendanceService;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Services\AttendanceService as StaffAttendanceService;
use App\Modules\HR\Services\ChecklistService;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\LeaveAccrual;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\PerformanceService;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Services\TrainingService;
use App\Modules\HR\Services\WorkCalendar;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Integrations\Exceptions\IntakeProblem;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use App\Modules\Integrations\Services\LeadIntake;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Services\CapaService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Loss\Services\LossSources;
use App\Modules\Loss\Services\LossWorkflowService;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Services\AssetService;
use App\Modules\Maintenance\Services\MaintenanceOrderService;
use App\Modules\Maintenance\Services\MaintenancePlanService;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\TemplateService;
use App\Modules\Marketing\Services\WorkflowService;
use App\Modules\Masters\Actions\SeedCompanyDefaults;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Imports\GeographyImporter;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\Brand;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Masters\Services\RateMaintenance;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Quality\Services\InspectionPlanService;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NonConformanceService;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderPricer;
use App\Modules\Sales\Services\QuotationPricer;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Rules\Gstin;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Models\Holiday;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\SlaSweep;
use App\Modules\Workspace\Services\TaskService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for local development: two companies (to show tenant isolation and the company
 * switcher), branches, reference and sample master data, and one user per standard role. Every demo
 * account uses the password "password" — never run this seeder in production.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        // Fictitious identifiers; the GSTINs carry valid check characters so they pass the same validation as real ones.
        $demo = $this->company('FEZ', 'FormEzy Demo Industries Pvt Ltd', [
            'gstin' => '24AAACF1234A1ZL', 'pan' => 'AAACF1234A', 'state' => 'Gujarat', 'state_code' => '24',
            'city' => 'Ahmedabad', 'pin_code' => '380001', 'email' => 'info@formezy.test', 'phone' => '079-40000000',
        ]);

        $branches = [
            'AHD' => ['Ahmedabad Head Office', 'Ahmedabad', 'Gujarat', '24', '380001', 23.0225, 72.5714],
            'MUM' => ['Mumbai Branch', 'Mumbai', 'Maharashtra', '27', '400001', 19.0760, 72.8777],
            'SRT' => ['Surat Branch', 'Surat', 'Gujarat', '24', '395003', 21.1702, 72.8311],
        ];

        $locations = [];
        foreach ($branches as $code => [$name, $city, $state, $stateCode, $pin, $lat, $lng]) {
            $locations[$code] = $this->location($demo, $code, $name, $city, $state, $stateCode, $pin, $lat, $lng);
        }

        $other = $this->company('BFP', 'Bharat Fasteners Pvt Ltd', [
            'gstin' => '27AAACB5678B1ZT', 'pan' => 'AAACB5678B', 'state' => 'Maharashtra', 'state_code' => '27',
            'city' => 'Pune', 'pin_code' => '411001', 'email' => 'info@bharatfasteners.test',
        ]);
        $this->location($other, 'PUN', 'Pune Plant', 'Pune', 'Maharashtra', '27', '411001', 18.5204, 73.8567);

        // Reference data every company gets (36 states, units, standard chart of accounts), then a little sample
        // master data so the Masters screens are not empty.
        foreach ([$demo, $other] as $company) {
            app(SeedCompanyDefaults::class)->run($company);
        }
        $this->sampleMasters($demo);
        $this->sampleGeography($demo, true);
        $this->sampleGeography($other, false);

        // [name, email, role, companies, branch codes (null = every branch)]
        $users = [
            ['Super Admin', 'admin@formezy.test', User::SUPER_ADMIN_ROLE, [$demo, $other], null],
            ['Charu Company Admin', 'companyadmin@formezy.test', 'Company Admin', [$demo], null],
            ['Ravi Approver', 'approver@formezy.test', 'Approver', [$demo], null],
            ['Pooja Purchase', 'purchase@formezy.test', 'Purchase User', [$demo], null],
            ['Imran Inventory', 'inventory@formezy.test', 'Inventory User', [$demo], null],
            ['Anita Accounts', 'accounts@formezy.test', 'Accounts User', [$demo], null],
            ['Omkar Operations', 'operations@formezy.test', 'Operations User', [$demo], null],
            ['Sandeep Sales Manager', 'manager@formezy.test', 'Sales Manager', [$demo], null],
            ['Bhavin BDE', 'bde@formezy.test', 'BDE / Field Salesperson', [$demo], ['AHD']],
            ['Chirag BDE', 'bde2@formezy.test', 'BDE / Field Salesperson', [$demo], ['MUM']],
            ['Tanvi Telecaller', 'telecaller@formezy.test', 'Tele-caller', [$demo], ['AHD', 'SRT']],
            ['Qasim Quality', 'quality@formezy.test', 'Quality/Loss Manager', [$demo], null],
            ['Isha Investigator', 'investigator@formezy.test', 'Investigator', [$demo], null],
            ['Farhan Finance', 'finance@formezy.test', 'Finance Controller', [$demo], null],
            ['Meera Management', 'management@formezy.test', 'Management', [$demo], null],
            ['Hema HR', 'hr@formezy.test', 'HR Manager', [$demo], null],
            ['Pranav Production', 'production@formezy.test', 'Production Manager', [$demo], null],
            ['Olivia Operator', 'operator@formezy.test', 'Production Operator', [$demo], ['AHD']],
            ['Ishaan Inspector', 'inspector@formezy.test', 'Quality Inspector', [$demo], ['AHD']],
            ['Mahesh Maintenance', 'maintenance@formezy.test', 'Maintenance Manager', [$demo], null],
            ['Tarun Technician', 'technician@formezy.test', 'Maintenance Technician', [$demo], ['AHD']],
        ];

        foreach ($users as [$name, $email, $role, $companies, $branchCodes]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password', 'default_company_id' => $companies[0]->id, 'is_active' => true],
            );

            $user->syncRoles([$role]);

            foreach ($companies as $company) {
                DB::table('company_user')->updateOrInsert(
                    ['company_id' => $company->id, 'user_id' => $user->id],
                    ['all_locations' => $branchCodes === null, 'created_at' => now(), 'updated_at' => now()],
                );
            }

            if ($branchCodes !== null) {
                $user->locations()->syncWithoutDetaching(array_map(fn ($code) => $locations[$code]->id, $branchCodes));
            }
        }

        // Ledgers need the users above: Purchase and Accounts create them, the Approver (or Accounts) signs them off.
        $this->sampleLedgers($demo);
        $this->sampleItems($demo, $locations);
        $this->sampleRates($demo, $locations);
        $this->sampleProcurement($demo, $locations);
        $this->sampleLoss($demo, $locations);
        $this->sampleFinance($demo, $locations);
        $this->sampleFieldSales($demo, $locations);
        $this->sampleLeads($demo, $locations);
        $this->sampleMarketplace($demo, $locations);
        $this->sampleDeals($demo, $locations);
        $this->sampleQuotations($demo);
        $this->sampleOrders($demo, $locations);
        $this->sampleWorkspace($demo);
        $this->sampleMarketing($demo, $locations);
        $this->sampleAnalytics($demo);
        $this->sampleHr($demo, $locations);
        $this->sampleHrLeave($demo);
        $this->sampleHrTalent($demo);
        $this->sampleHrChecklists($demo);
        $this->sampleProduction($demo, $locations);
        $this->sampleQuality($demo, $locations);
        $this->sampleMaintenance($demo, $locations);
    }

    /**
     * The employee files of the demo people, through the real service: an org chart under the management account, one person on probation, one serving notice
     * and one who has left (the last two have no login). Everyone with a login is linked to their file. Fictitious people, dates and codes.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleHr(Company $company, array $locations): void
    {
        $hr = User::query()->where('email', 'hr@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($hr, $locations) {
            if (Employee::query()->exists()) {
                return;
            }

            Auth::setUser($hr);
            $service = app(EmployeeService::class);
            $dept = fn (string $code) => $code === '' ? '' : (string) Department::query()->where('code', $code)->value('id');
            $desig = fn (string $code) => (string) Designation::query()->where('code', $code)->value('id');

            $people = $this->hrPeople();

            /** @var array<string, Employee> $made */
            $made = [];
            foreach ($people as $i => [$who, $d, $g, $branch, $boss, $joined, $dob, $type]) {
                $user = User::query()->where('email', $who)->first();   // a plain name has no login

                if ($user === null && str_contains($who, '@')) {
                    throw new \RuntimeException('The demo login '.$who.' is missing.');
                }

                $employee = $service->create([
                    'code' => 'EMP-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT), 'name' => $user === null ? $who : $user->name, 'location_id' => (string) $locations[$branch]->id,
                    'department_id' => $dept($d), 'designation_id' => $desig($g), 'reports_to_id' => $boss === null ? '' : (string) $made[$boss]->id,
                    'employment_type' => $type, 'joining_date' => $joined, 'probation_end_date' => $type === 'probation' ? '2026-11-18' : '', 'date_of_birth' => $dob,
                    'work_email' => $user === null ? '' : $user->email,
                ], $hr);

                if ($user !== null) {
                    $service->linkUser($employee, $user, $hr);
                }

                $made[$who] = $employee;
            }

            $service->addContact($made['bde@formezy.test'], ['name' => 'Kiran Bhatt', 'relationship' => 'Father', 'mobile' => '9000000201', 'is_primary' => true], $hr);
            $service->recordNotice($made['Rohan Desai'], now()->addDays(20)->toDateString(), 'Moving to another city', $hr);

            $left = $made['Kavita Menon'];
            DB::table('employees')->where('id', $left->id)->update(['employment_status' => 'exited', 'notice_date' => now()->subDays(40)->toDateString(), 'exit_date' => now()->subDays(12)->toDateString(), 'exit_reason' => 'Joined a competitor']);
            DB::table('employment_events')->insert(['company_id' => $left->company_id, 'employee_id' => $left->id, 'event' => 'exited', 'effective_on' => now()->subDays(12)->toDateString(), 'remarks' => 'Joined a competitor', 'recorded_by' => $hr->id, 'created_at' => now()]);
        });
    }

    /**
     * Leave and attendance for the demo people, through the real services: leave types, a shift, the holidays, this year's grants, a few requests in every state
     * (waiting, approved, turned down, a half day), and the last two weeks of punches closed into attendance days (some late, one half day, one missed punch out,
     * one absence). Fictitious dates; the festival days are only examples.
     */
    private function sampleHrLeave(Company $company): void
    {
        $hr = User::query()->where('email', 'hr@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($hr) {
            if (LeaveType::query()->exists()) {
                return;
            }

            Auth::setUser($hr);

            foreach ([
                ['CL', 'Casual leave', true, '12', 'yearly', '5', true, false],
                ['SL', 'Sick leave', true, '12', 'monthly', '0', false, false],
                ['PL', 'Privilege leave', true, '15', 'monthly', '10', true, false],
                ['LWP', 'Leave without pay', false, '0', 'none', '0', true, true],
            ] as [$code, $name, $paid, $quota, $accrual, $carry, $half, $negative]) {
                (new LeaveType(['code' => $code, 'name' => $name, 'is_paid' => $paid, 'annual_quota' => $quota, 'accrual' => $accrual, 'carry_forward_max' => $carry, 'allow_half_day' => $half, 'allow_negative' => $negative]))->save();
            }

            (new Shift(['code' => 'DAY', 'name' => 'Day shift', 'start_time' => '09:30', 'end_time' => '18:30', 'grace_minutes' => 15, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'is_default' => true]))->save();

            foreach ([['2026-08-15', 'Independence Day'], ['2026-10-02', 'Gandhi Jayanti'], ['2026-10-20', 'Dussehra'], ['2026-11-08', 'Diwali'], ['2026-12-25', 'Christmas'], ['2027-01-26', 'Republic Day']] as [$date, $name]) {
                Holiday::query()->create(['holiday_date' => $date, 'name' => $name]);
            }

            app(LeaveAccrual::class)->run();

            $leave = app(LeaveService::class);
            $calendar = app(WorkCalendar::class);
            $access = app(EmployeeAccess::class);
            $by = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
            $file = function (string $email) use ($access, $by) {
                $access->flush();
                Auth::setUser($by($email));

                return $access->own($by($email));
            };
            $type = fn (string $code) => LeaveType::query()->where('code', $code)->firstOrFail();
            // the first working day on or after $date, so a sample request is never refused for landing on a holiday or a Sunday
            $working = function (CarbonImmutable $date) use ($calendar) {
                while ($calendar->workingDays($date, $date) === []) {
                    $date = $date->addDay();
                }

                return $date->toDateString();
            };
            $today = CarbonImmutable::today();
            $as = function (string $email, \Closure $step) use ($by) {
                Auth::setUser($by($email));

                return $step();
            };

            $past = $working($today->subDays(9));
            $request = $as('bde@formezy.test', fn () => $leave->apply($file('bde@formezy.test'), $type('CL'), $past, $working(CarbonImmutable::parse($past)->addDay()), null, 'Cousin’s wedding', $by('bde@formezy.test')));
            $as('manager@formezy.test', fn () => $leave->approve($request, $by('manager@formezy.test'), 'Enjoy the wedding'));

            $soon = $working($today->addDays(6));
            $as('bde@formezy.test', fn () => $leave->apply($file('bde@formezy.test'), $type('CL'), $soon, $soon, null, 'Bank work', $by('bde@formezy.test')));

            $sick = $working($today->subDays(3));
            $sickRequest = $as('telecaller@formezy.test', fn () => $leave->apply($file('telecaller@formezy.test'), $type('SL'), $sick, $sick, null, 'Fever', $by('telecaller@formezy.test')));
            $as('manager@formezy.test', fn () => $leave->approve($sickRequest, $by('manager@formezy.test')));

            $half = $working($today->addDays(2));
            $halfRequest = $as('accounts@formezy.test', fn () => $leave->apply($file('accounts@formezy.test'), $type('CL'), $half, $half, 'second', 'Bank visit', $by('accounts@formezy.test')));
            $as('hr@formezy.test', fn () => $leave->approve($halfRequest, $by('hr@formezy.test')));

            $long = $working($today->addDays(12));
            $turnedDown = $as('investigator@formezy.test', fn () => $leave->apply($file('investigator@formezy.test'), $type('PL'), $long, $working(CarbonImmutable::parse($long)->addDays(3)), null, 'Family trip', $by('investigator@formezy.test')));
            $as('quality@formezy.test', fn () => $leave->reject($turnedDown, $by('quality@formezy.test'), 'An audit is due that week: please ask for the week after.'));

            // two weeks of punches for everyone who has a login, then the day-close
            Auth::setUser($hr);
            $attendance = app(StaffAttendanceService::class);
            $people = Employee::query()->whereNotNull('user_id')->orderBy('id')->get();
            $punch = function (Employee $e, CarbonImmutable $day, string $in, ?string $out) {
                foreach ([['in', $in], ['out', $out]] as [$direction, $time]) {
                    if ($time !== null) {
                        (new AttendancePunch)->forceFill(['company_id' => $e->company_id, 'employee_id' => $e->id, 'punched_at' => CarbonImmutable::parse($day->toDateString().' '.$time), 'direction' => $direction, 'source' => 'web', 'created_at' => $day->setTime(23, 0)])->save();
                    }
                }
            };

            foreach ($people as $i => $employee) {
                for ($back = 14; $back >= 1; $back--) {
                    $day = $today->subDays($back);

                    if ($day->lt($employee->joining_date) || $calendar->workingDays($day, $day) === []) {
                        continue;
                    }

                    $pattern = ($i * 3 + $back) % 11;
                    match (true) {
                        $pattern === 0 => null,                                                     // did not come
                        $pattern === 1 => $punch($employee, $day, '09:28', null),                  // forgot to punch out
                        $pattern === 2 => $punch($employee, $day, '09:30', '13:45'),               // half day
                        $pattern === 3, $pattern === 4 => $punch($employee, $day, '09:52', '18:40'), // late
                        default => $punch($employee, $day, sprintf('09:%02d', 15 + ($i + $back) % 14), sprintf('18:%02d', 30 + ($i * $back) % 25)),
                    };
                }

                $attendance->recloseRange($employee, $today->subDays(14), $today->subDay());
            }

        });
    }

    /** A standard onboarding and exit list, started for the people on probation and the one serving notice (examples, not a policy). */
    private function sampleHrChecklists(Company $company): void
    {
        $hr = User::query()->where('email', 'hr@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($hr) {
            if (ChecklistTemplateItem::query()->exists()) {
                return;
            }

            Auth::setUser($hr);

            foreach ([
                ['LAPTOP', 'Issue a laptop and e-mail account', 'onboarding', 'hr', 1], ['DESK', 'Show the desk and introduce the team', 'onboarding', 'manager', 1],
                ['HANDBOOK', 'Read the employee handbook', 'onboarding', 'employee', 7], ['GOALS', 'Agree the first-quarter goals', 'onboarding', 'manager', 14],
                ['HANDOVER', 'Hand over open work', 'exit', 'manager', 7], ['RETURN', 'Return the laptop and cards', 'exit', 'employee', 0], ['DUES', 'Clear dues and issue the letter', 'exit', 'hr', 3],
            ] as [$code, $name, $kind, $who, $days]) {
                (new ChecklistTemplateItem(['code' => $code, 'name' => $name, 'kind' => $kind, 'responsible' => $who, 'due_days' => $days]))->save();
            }

            $service = app(ChecklistService::class);

            foreach (Employee::query()->where('employment_type', 'probation')->get() as $employee) {
                $service->start($employee, 'onboarding');
            }

            foreach (Employee::query()->where('employment_status', 'notice')->get() as $employee) {
                $service->start($employee, 'exit');
            }
        });
    }

    /**
     * Maintenance (M1), through the real services: three assets (a hydraulic press and a forklift at Ahmedabad, a cutting machine at Mumbai), a monthly schedule for the press that has
     * come due (so its work order is made by the generator), a breakdown a week ago that was repaired with a spare part and hours logged, and a breakdown now, assigned to the technician
     * and still down. Fictitious.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleMaintenance(Company $company, array $locations): void
    {
        $manager = User::query()->where('email', 'maintenance@formezy.test')->firstOrFail();
        $technician = User::query()->where('email', 'technician@formezy.test')->firstOrFail();
        $inventory = User::query()->where('email', 'inventory@formezy.test')->firstOrFail();
        $production = User::query()->where('email', 'production@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $technician, $inventory, $production, $locations) {
            if (Asset::query()->exists()) {
                return;
            }

            $bolt = app(ItemLookup::class)->search('Hex Bolt', 1)->firstOrFail();

            Auth::setUser($inventory);
            app(StockService::class)->adjustIn($bolt, $locations['AHD']->id, '40', '3.40', null, 'Spare fasteners for the maintenance demo', $inventory);

            Auth::setUser($manager);
            $assets = app(AssetService::class);
            $asset = fn (string $code, string $name, string $branch, string $category, string $model, string $criticality) => $assets->create([
                'code' => $code, 'name' => $name, 'location_id' => (string) $locations[$branch]->id, 'category' => $category, 'make_model' => $model, 'serial_no' => 'SN-'.$code, 'installed_on' => '2021-06-15',
                'criticality' => $criticality, 'cost_center_id' => '', 'remarks' => '',
            ], $manager);
            $press = $asset('PRESS-01', 'Hydraulic press', 'AHD', 'machine', 'HydroMax 200', 'high');
            $asset('FORK-02', 'Forklift', 'AHD', 'vehicle', 'LiftWell 3T', 'low');
            $asset('CUT-01', 'Plate cutting machine', 'MUM', 'machine', 'CutPro 12', 'medium');

            app(MaintenancePlanService::class)->create([
                'code' => 'PM-PRESS-01', 'asset_id' => (string) $press->id, 'name' => 'Grease and inspect the hydraulics', 'checklist' => "Grease all points\nCheck the oil level and top up\nInspect the hoses for leaks\nTest the safety guard",
                'every_n' => '1', 'unit' => 'months', 'lead_days' => '5', 'estimated_hours' => '3', 'next_due_on' => now()->addDays(2)->toDateString(),
            ], $manager);

            $orders = app(MaintenanceOrderService::class);
            $week = now()->subDays(8)->setTime(9, 0);

            $past = $this->at($week, function () use ($orders, $press, $manager, $technician, $bolt) {
                $order = $orders->create(['asset_id' => (string) $press->id, 'type' => 'breakdown', 'priority' => 'normal', 'title' => 'Ram seal leaking', 'stopped_at' => now()->subHours(4)->format('Y-m-d\TH:i'),
                    'assigned_to' => (string) $technician->id, 'checklist' => "Isolate and drain\nReplace the ram seal\nTest at full load"], $manager);
                Auth::setUser($technician);

                foreach ($order->tasks as $task) {
                    $orders->tick($order->fresh(), $task->id, $technician);
                }

                $orders->issuePart($order->fresh(), $bolt->id, null, '4', 'demo-part-1', $technician);
                $orders->logHours($order->fresh(), '3.5', now()->toDateString(), 'Drained, replaced the seal, tested', 'demo-hours-1', $technician);

                return $orders->complete($order->fresh(), 'Ram seal replaced and tested at full load', now()->subMinutes(10)->format('Y-m-d\TH:i'), $technician);
            });

            Auth::setUser($production);
            $now = $orders->create(['asset_id' => (string) $press->id, 'type' => 'breakdown', 'priority' => 'high', 'title' => 'Press not building pressure', 'stopped_at' => now()->subHours(2)->format('Y-m-d\TH:i'),
                'description' => 'Gauge stays at half pressure after warm-up', 'checklist' => "Check the oil level\nInspect the pump and relief valve"], $production);
            Auth::setUser($manager);
            $orders->assign($now, $technician->id, $manager);

            $orders->generateDue();
        });
    }

    /**
     * Quality (Q1), through the real services: a final-inspection plan for the demo bolt (made by the Quality Manager, approved by the Approver); three inspections at Ahmedabad — one
     * accepted, one rejected (which raised a non-conformance report, then decided) and one still in progress — and a minor report raised by hand. Fictitious readings.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleQuality(Company $company, array $locations): void
    {
        $manager = User::query()->where('email', 'quality@formezy.test')->firstOrFail();
        $inspector = User::query()->where('email', 'inspector@formezy.test')->firstOrFail();
        $approver = User::query()->where('email', 'approver@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $inspector, $approver, $locations) {
            if (InspectionPlan::query()->exists()) {
                return;
            }

            $bolt = app(ItemLookup::class)->search('Hex Bolt', 1)->firstOrFail();

            Auth::setUser($manager);
            $plan = app(InspectionPlanService::class)->create([
                'code' => 'QP-BOLT-FINAL', 'name' => 'Final inspection of hex bolts', 'item_id' => (string) $bolt->id, 'stage' => 'final', 'remarks' => 'One sample of 20 from each lot',
                'items' => [
                    ['name' => 'Length', 'kind' => 'measure', 'unit_text' => 'mm', 'min_value' => '49.5', 'max_value' => '50.5', 'is_critical' => false, 'method' => 'Vernier caliper'],
                    ['name' => 'Head diameter', 'kind' => 'measure', 'unit_text' => 'mm', 'min_value' => '16.4', 'max_value' => '17', 'is_critical' => false, 'method' => 'Micrometer'],
                    ['name' => 'Thread fit', 'kind' => 'check', 'unit_text' => '', 'min_value' => '', 'max_value' => '', 'is_critical' => true, 'method' => 'Go / no-go gauge'],
                ],
            ], $manager);
            $approvals = app(ApprovalService::class);
            $approvals->submit($plan, $manager);
            Auth::setUser($approver);
            $approvals->approve($plan->fresh(), $approver);

            Auth::setUser($inspector);
            $service = app(InspectionService::class);
            $start = fn (string $lot, string $label) => $service->start(['inspection_plan_id' => (string) $plan->id, 'location_id' => (string) $locations['AHD']->id, 'lot_quantity' => $lot, 'source_label' => $label], $inspector);
            $fill = fn ($inspection, string $length, string $head, string $thread) => $service->record($inspection->fresh(), $inspection->results()->get()->mapWithKeys(fn ($r) => [$r->id => match ($r->name) {
                'Length' => ['value' => $length], 'Head diameter' => ['value' => $head], default => ['check' => $thread],
            }])->all(), $inspector);

            $good = $start('1200', 'Line 2, morning shift');
            $fill($good, '50.1', '16.8', 'pass');
            $service->complete($good->fresh(), $inspector);

            $bad = $start('800', 'Line 1, night shift');
            $fill($bad, '51.2', '16.7', 'pass');
            $service->complete($bad->fresh(), $inspector);
            Auth::setUser($manager);
            $service->decide($bad->fresh(), 'reject', 'Too long: a die has worn', $manager);

            Auth::setUser($inspector);
            $start('600', 'Line 2, afternoon shift');

            $ncrs = app(NonConformanceService::class);
            $ncrs->create(['location_id' => (string) $locations['AHD']->id, 'item_id' => (string) $bolt->id, 'quantity_affected' => '40', 'severity' => 'minor', 'description' => 'Cartons of the last dispatch arrived crushed at the customer'], $inspector);

            Auth::setUser($manager);
            $rejected = NonConformance::query()->where('inspection_id', $bad->id)->firstOrFail();
            $ncrs->disposition($rejected, 'sort', 'Sort the lot by length and keep the good ones', $manager);
        });
    }

    /**
     * Production (P1), through the real services: a bill of materials for the demo bolt (made by the Production Manager, approved by the Approver), steel and HDPE put into stock at
     * Ahmedabad, one order partly done (material issued, 1,180 good and 20 rejected) and one still a draft. Fictitious quantities.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleProduction(Company $company, array $locations): void
    {
        $manager = User::query()->where('email', 'production@formezy.test')->firstOrFail();
        $operator = User::query()->where('email', 'operator@formezy.test')->firstOrFail();
        $approver = User::query()->where('email', 'approver@formezy.test')->firstOrFail();
        $inventory = User::query()->where('email', 'inventory@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $operator, $approver, $inventory, $locations) {
            if (Bom::query()->exists()) {
                return;
            }

            $lookup = app(ItemLookup::class);
            $bolt = $lookup->search('Hex Bolt', 1)->firstOrFail();
            $steel = $lookup->search('Mild Steel Sheet', 1)->firstOrFail();
            $hdpe = $lookup->search('HDPE', 1)->firstOrFail();

            Auth::setUser($inventory);
            $stock = app(StockService::class);
            $stock->adjustIn($steel, $locations['AHD']->id, '400', '58', null, 'Opening stock for the production demo', $inventory);
            $stock->adjustIn($hdpe, $locations['AHD']->id, '40', '92', null, 'Opening stock for the production demo', $inventory);

            Auth::setUser($manager);
            $bom = app(BomService::class)->create([
                'code' => 'BOM-BOLT-01', 'item_id' => (string) $bolt->id, 'output_quantity' => '100', 'remarks' => 'Cold-forged bolt, coated',
                'items' => [['item_id' => (string) $steel->id, 'quantity' => '8', 'scrap_percent' => '3'], ['item_id' => (string) $hdpe->id, 'quantity' => '0.5', 'scrap_percent' => '0']],
            ], $manager);
            $approvals = app(ApprovalService::class);
            $approvals->submit($bom, $manager);
            Auth::setUser($approver);
            $approvals->approve($bom->fresh(), $approver);

            Auth::setUser($manager);
            $orders = app(ProductionOrderService::class);
            $input = fn (string $quantity, string $start, string $end) => ['location_id' => (string) $locations['AHD']->id, 'bom_id' => (string) $bom->id, 'planned_quantity' => $quantity, 'planned_start' => $start, 'planned_end' => $end];

            $order = $orders->release($orders->create($input('2000', now()->subDays(3)->toDateString(), now()->addDays(2)->toDateString()), $manager), $manager);
            $orders->create($input('500', now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString()), $manager);

            Auth::setUser($operator);
            foreach ($order->components()->get() as $component) {
                $orders->issue($order->fresh(), $component->id, null, (string) $component->required_quantity, 'demo-issue-'.$component->id, $operator);
            }

            $orders->recordOutput($order->fresh(), ['good_quantity' => '1180', 'rejected_quantity' => '20', 'reject_reason' => 'Thread damaged in forging'], 'demo-output-1', $operator);
        });
    }

    /**
     * Recruitment, performance and training for the demo company, through the real services: three openings (open, draft, closed), candidates at every stage including an
     * interview already done and an offer waiting for an answer; a review cycle with goals and reviews at different points; and two training programs (one finished with outcomes,
     * one coming up). Fictitious people and dates.
     */
    private function sampleHrTalent(Company $company): void
    {
        app(TenantContext::class)->withCompany($company->id, function () {
            if (JobOpening::query()->exists()) {
                return;
            }

            $by = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
            $as = function (string $email, \Closure $step) use ($by) {
                Auth::setUser($by($email));
                app(EmployeeAccess::class)->flush();

                return $step();
            };
            $hr = $by('hr@formezy.test');
            $recruitment = app(RecruitmentService::class);
            $performance = app(PerformanceService::class);
            $training = app(TrainingService::class);
            $today = CarbonImmutable::today();
            $employee = fn (string $email) => Employee::query()->where('user_id', $by($email)->id)->firstOrFail();

            // ---- recruitment
            $location = (string) Location::query()->where('code', 'AHD')->value('id');
            $sales = (string) Department::query()->where('code', 'SAL')->value('id');
            $accounts = (string) Department::query()->where('code', 'ACC')->value('id');
            $bde = (string) Designation::query()->where('code', 'BDE')->value('id');
            $exe = (string) Designation::query()->where('code', 'EXE')->value('id');

            $openings = $as('hr@formezy.test', function () use ($recruitment, $hr, $location, $sales, $accounts, $bde, $exe, $employee) {
                $open = $recruitment->saveOpening(['code' => 'JOB-0001', 'title' => 'Business Development Executive', 'location_id' => $location, 'department_id' => $sales, 'designation_id' => $bde,
                    'hiring_manager_id' => (string) $employee('manager@formezy.test')->id, 'positions' => '2', 'description' => 'Field sales for industrial customers in Ahmedabad and Surat.'], $hr);
                $recruitment->openOpening($open, $hr);

                $draft = $recruitment->saveOpening(['code' => 'JOB-0002', 'title' => 'Accounts Executive', 'location_id' => $location, 'department_id' => $accounts, 'designation_id' => $exe, 'positions' => '1'], $hr);

                $closed = $recruitment->saveOpening(['code' => 'JOB-0003', 'title' => 'Store Keeper', 'location_id' => $location, 'positions' => '1'], $hr);
                $recruitment->openOpening($closed, $hr);
                $recruitment->closeOpening($closed, 'The position was filled internally.', $hr);

                return [$open, $draft, $closed];
            });

            [$open] = $openings;
            $people = [
                ['Kavya Iyer', '9000000301', 'referral', 'Sunrise Fasteners', '3.5'], ['Manav Trivedi', '9000000302', 'portal', 'Alpha Steel', '2'], ['Pallavi Rao', '9000000303', 'portal', null, '1'],
                ['Rajan Bhatt', '9000000304', 'agency', 'Metro Polymers', '5'], ['Sneha Joshi', '9000000305', 'walk_in', null, '0.5'],
            ];
            $applications = $as('hr@formezy.test', fn () => array_map(fn (array $p) => $recruitment->apply(
                $recruitment->saveCandidate(['name' => $p[0], 'mobile' => $p[1], 'email' => strtolower(str_replace(' ', '.', $p[0])).'@mail.test', 'source' => $p[2], 'current_employer' => (string) $p[3], 'experience_years' => $p[4]], $hr),
                $open, $hr), $people));

            // Pallavi: screening. Manav: interview booked for the week ahead with the manager. Rajan: interviewed, recommended, offer waiting. Sneha: rejected.
            $as('hr@formezy.test', fn () => $recruitment->moveStage($applications[2], 'screening', null, $hr));
            $as('hr@formezy.test', fn () => $recruitment->scheduleInterview($applications[1], $today->addDays(3)->setTime(11, 0)->format('Y-m-d\TH:i'), 'in_person', $by('manager@formezy.test')->id, $hr));
            $past = $today->subDays(4)->setTime(15, 0);
            $this->at($past->subDay(), fn () => $as('hr@formezy.test', fn () => $recruitment->scheduleInterview($applications[3], $past->format('Y-m-d\TH:i'), 'video', $by('manager@formezy.test')->id, $hr)));
            $interview = Interview::query()->where('application_id', $applications[3]->id)->firstOrFail();
            $this->at($past->addHour(), fn () => $as('manager@formezy.test', fn () => $recruitment->recordFeedback($interview, 4, 'hire', 'Clear on pricing and follow-up discipline; has handled industrial buyers before.', $by('manager@formezy.test'))));
            $as('hr@formezy.test', fn () => $recruitment->makeOffer($applications[3]->fresh(), null, $today->addDays(21)->toDateString(), 'Subject to reference check.', $hr));
            $as('hr@formezy.test', fn () => $recruitment->moveStage($applications[4], 'rejected', 'Not enough experience for field sales.', $hr));

            // ---- performance
            $cycle = $as('hr@formezy.test', function () use ($performance, $hr) {
                $year = CarbonImmutable::today()->month >= 4 ? CarbonImmutable::today()->year : CarbonImmutable::today()->year - 1;
                $cycle = $performance->saveCycle(['code' => 'FY'.$year, 'name' => 'Annual review FY'.$year.'-'.substr((string) ($year + 1), 2), 'period_start' => $year.'-04-01', 'period_end' => ($year + 1).'-03-31'], $hr);
                $performance->openCycle($cycle, $hr);

                return $cycle->fresh();
            });

            $manager = $by('manager@formezy.test');
            foreach ([['bde@formezy.test', [['Win 12 new industrial accounts', 60, 70], ['Keep follow-ups on time', 40, 90]]], ['telecaller@formezy.test', [['Make 40 qualified calls a day', 100, 55]]]] as [$email, $goals]) {
                foreach ($goals as [$title, $weight, $progress]) {
                    $goal = $as('manager@formezy.test', fn () => $performance->saveGoal($employee($email), $cycle, ['title' => $title, 'weight' => (string) $weight], $manager));
                    $as($email, fn () => $performance->setProgress($goal, $progress, $by($email)));
                }
            }

            $reviewOf = fn (string $email) => PerformanceReview::query()->where('employee_id', $employee($email)->id)->where('review_cycle_id', $cycle->id)->firstOrFail();
            $as('bde@formezy.test', fn () => $performance->selfReview($reviewOf('bde@formezy.test'), 4, 'I opened nine new accounts and kept every follow-up date.', $by('bde@formezy.test')));
            $as('manager@formezy.test', fn () => $performance->managerReview($reviewOf('bde@formezy.test'), 4, 'Strong first half; the pipeline is healthy and follow-ups are on time.', $manager));
            $as('investigator@formezy.test', fn () => $performance->selfReview($reviewOf('investigator@formezy.test'), 3, 'Closed my incident reviews on time; the root-cause notes could be sharper.', $by('investigator@formezy.test')));

            // ---- training
            $program = $this->at($today->subDays(25), fn () => $as('hr@formezy.test', function () use ($training, $hr, $today, $employee) {
                $program = $training->saveProgram(['code' => 'TR-001', 'title' => 'Product knowledge: fasteners and steel', 'trainer' => 'Dr. S. Mehta', 'mode' => 'in_person', 'venue' => 'Head office training room',
                    'starts_on' => $today->subDays(20)->toDateString(), 'ends_on' => $today->subDays(19)->toDateString(), 'capacity' => '12'], $hr);

                foreach (['bde@formezy.test', 'bde2@formezy.test', 'telecaller@formezy.test', 'purchase@formezy.test'] as $email) {
                    $training->enrol($program, $employee($email), $hr);
                }

                return $program;
            }));
            $this->at($today->subDays(19), fn () => $as('hr@formezy.test', function () use ($training, $hr, $program, $employee) {
                foreach (['bde@formezy.test' => ['completed', '86'], 'bde2@formezy.test' => ['completed', '74.5'], 'telecaller@formezy.test' => ['completed', '91'], 'purchase@formezy.test' => ['absent', null]] as $email => [$state, $score]) {
                    $row = TrainingEnrolment::query()->where('training_program_id', $program->id)->where('employee_id', $employee($email)->id)->firstOrFail();
                    $training->record($row, $state, $score, $hr);
                }

                $training->completeProgram($program->fresh(), $hr);
            }));

            $as('hr@formezy.test', function () use ($training, $hr, $today, $employee) {
                $upcoming = $training->saveProgram(['code' => 'TR-002', 'title' => 'Negotiation skills for sales teams', 'trainer' => 'Anita Desai', 'mode' => 'online', 'venue' => 'Video call link is sent on enrolment',
                    'starts_on' => $today->addDays(10)->toDateString(), 'ends_on' => $today->addDays(11)->toDateString(), 'capacity' => '10'], $hr);

                foreach (['bde@formezy.test', 'bde2@formezy.test', 'manager@formezy.test'] as $email) {
                    $training->enrol($upcoming, $employee($email), $hr);
                }
            });
        });
    }

    /**
     * The people of the demo company: login e-mail (or a name, for someone with no login), department code, designation code, branch code, manager's login,
     * joining date, date of birth and employment type.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: string, 6: string, 7: string}>
     */
    private function hrPeople(): array
    {
        return [
            ['management@formezy.test', '', 'DIR', 'AHD', null, '2018-04-02', '1978-11-14', 'permanent'],
            ['companyadmin@formezy.test', '', 'MGR', 'AHD', 'management@formezy.test', '2019-07-01', '1983-05-20', 'permanent'],
            ['hr@formezy.test', 'HR', 'MGR', 'AHD', 'management@formezy.test', '2021-06-14', '1988-10-25', 'permanent'],
            ['approver@formezy.test', 'ACC', 'MGR', 'AHD', 'management@formezy.test', '2019-02-11', '1981-01-09', 'permanent'],
            ['finance@formezy.test', 'ACC', 'MGR', 'AHD', 'management@formezy.test', '2020-09-07', '1985-08-30', 'permanent'],
            ['accounts@formezy.test', 'ACC', 'EXE', 'AHD', 'approver@formezy.test', '2022-04-18', '1992-12-03', 'permanent'],
            ['operations@formezy.test', 'OPS', 'MGR', 'AHD', 'management@formezy.test', '2019-11-04', '1984-03-17', 'permanent'],
            ['purchase@formezy.test', 'PUR', 'EXE', 'AHD', 'operations@formezy.test', '2023-01-16', '1991-07-22', 'permanent'],
            ['inventory@formezy.test', 'INV', 'EXE', 'SRT', 'operations@formezy.test', '2022-08-01', '1990-09-12', 'permanent'],
            ['quality@formezy.test', 'OPS', 'MGR', 'AHD', 'management@formezy.test', '2020-02-03', '1986-06-05', 'permanent'],
            ['investigator@formezy.test', 'OPS', 'EXE', 'AHD', 'quality@formezy.test', '2024-05-06', '1993-04-27', 'permanent'],
            ['manager@formezy.test', 'SAL', 'MGR', 'AHD', 'management@formezy.test', '2019-05-13', '1982-02-14', 'permanent'],
            ['bde@formezy.test', 'SAL', 'BDE', 'AHD', 'manager@formezy.test', '2023-06-12', '1994-11-02', 'permanent'],
            ['bde2@formezy.test', 'SAL', 'BDE', 'MUM', 'manager@formezy.test', '2024-01-08', '1995-03-19', 'permanent'],
            ['telecaller@formezy.test', 'SAL', 'TLC', 'AHD', 'manager@formezy.test', '2026-08-18', '1998-06-30', 'probation'],
            ['Rohan Desai', 'SAL', 'BDE', 'SRT', 'manager@formezy.test', '2023-03-20', '1993-10-08', 'permanent'],
            ['Kavita Menon', 'ACC', 'EXE', 'AHD', 'approver@formezy.test', '2022-01-10', '1990-12-15', 'permanent'],
        ];
    }

    /**
     * What the executive dashboard needs to show something: the industries the sample items belong to (so revenue can be split by industry), those
     * industries switched on for the company, the demo-data badge, and today's KPI snapshots. Bolts and nuts are automotive, the steel sheet is metals,
     * granules are chemicals and cartons are food and beverage packaging — fictitious, like the rest of the sample data.
     */
    private function sampleAnalytics(Company $company): void
    {
        $admin = User::query()->where('email', 'companyadmin@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($company, $admin) {
            Auth::setUser($admin);
            $templates = app(IndustryTemplateService::class);
            $byCode = IndustryTemplate::query()->whereIn('code', ['automotive', 'metals_mining_cement', 'chemicals', 'food_beverage'])->get()->keyBy('code');

            foreach ($byCode as $template) {
                if (! $templates->isEnabled($template)) {
                    $templates->enable($template, $admin);
                }
            }

            $industryOf = ['MS Hex Bolt M10 x 50' => 'automotive', 'Zinc Plated Nut M10' => 'automotive', 'Mild Steel Sheet 2 mm' => 'metals_mining_cement', 'HDPE Granules' => 'chemicals', 'Corrugated Carton 5-ply' => 'food_beverage'];

            foreach ($industryOf as $name => $code) {
                DB::table('items')->where('company_id', $company->id)->where('name', $name)->whereNull('industry_template_id')->update(['industry_template_id' => $byCode[$code]->id]);
            }

            app(SettingsService::class)->set('system.demo_data', true, $company->id);
        });

        app(KpiSnapshotService::class)->run();
    }

    /**
     * The marketplace feeds, as far as they can be shown without a real account: Just Dial switched on (it pushes) with three
     * deliveries that came through the real intake — a complete lead, one without a mobile number (kept, incomplete) and a second
     * enquiry from a number that already has an open lead (added to it as a note) — and IndiaMART set up but switched off (it needs
     * an API key), with one delivery that failed for a reason a person can fix. No credentials, and fictitious people and numbers.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleMarketplace(Company $company, array $locations): void
    {
        if (IntegrationConnection::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $admin = User::query()->where('email', 'companyadmin@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($company, $admin, $locations) {
            $connect = function (string $provider, array $more) use ($company, $admin, $locations): IntegrationConnection {
                $connection = new IntegrationConnection;
                $connection->forceFill([
                    'company_id' => $company->id, 'provider' => $provider, 'public_key' => IntegrationConnection::newPublicKey(),
                    'default_location_id' => $locations['AHD']->id, 'actor_user_id' => $admin->id, 'created_by' => $admin->id, 'updated_by' => $admin->id,
                    ...$more,
                ])->save();

                return $connection;
            };

            $justDial = $connect('justdial', ['enabled' => true, 'mode' => 'webhook']);
            $indiaMart = $connect('indiamart', ['enabled' => false, 'mode' => 'pull']);

            $store = app(LeadApiLogStore::class);
            $intake = app(LeadIntake::class);
            $registry = app(ProviderRegistry::class);
            $today = now()->startOfDay();

            $deliver = function (IntegrationConnection $connection, array $record, CarbonInterface $moment) use ($store, $intake, $registry): void {
                $this->at($moment, function () use ($connection, $record, $store, $intake, $registry) {
                    [$log] = $store->record($connection, $registry->get($connection->provider), $record, $connection->provider === 'justdial' ? 'webhook' : 'pull');

                    try {
                        $intake->process($log);
                    } catch (IntakeProblem) {
                        // kept as a failed delivery, with its reason: that is the point of showing it
                    }
                });
            };

            $deliver($justDial, [
                'leadid' => 'JD-DEMO-1001', 'leadtype' => 'Enquiry', 'prefix' => 'Mr', 'name' => 'Dinesh Parmar', 'mobile' => '9000000301', 'email' => 'dinesh@demofab.test',
                'date' => $today->copy()->subDays(2)->format('Y-m-d'), 'time' => '15:20:00', 'category' => 'Hex bolts', 'area' => 'Vatva', 'brancharea' => 'Ahmedabad',
                'company' => 'Demo Fabricators', 'pincode' => '380001', 'dncmobile' => '0', 'dncphone' => '0',
            ], $today->copy()->subDays(2)->setTimeFromTimeString('15:21'));
            $deliver($justDial, [
                'leadid' => 'JD-DEMO-1002', 'leadtype' => 'Enquiry', 'name' => 'Kiran', 'mobile' => '', 'category' => 'Washers', 'area' => 'Odhav',
                'date' => $today->copy()->subDay()->format('Y-m-d'), 'time' => '11:05:00', 'dncmobile' => '0',
            ], $today->copy()->subDay()->setTimeFromTimeString('11:06'));
            $deliver($justDial, [
                'leadid' => 'JD-DEMO-1003', 'leadtype' => 'Enquiry', 'name' => 'Mehul Shah', 'mobile' => '9000000101', 'category' => 'Hex bolts', 'area' => 'Khanpur',
                'date' => $today->format('Y-m-d'), 'time' => '08:40:00', 'company' => 'Shreeji Hardware Mart', 'dncmobile' => '1',
            ], $today->copy()->setTimeFromTimeString('08:41'));

            // a delivery that cannot become a lead until someone switches the standard source back on
            LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'inactive']);
            $deliver($indiaMart, [
                'UNIQUE_QUERY_ID' => 'IM-DEMO-5001', 'QUERY_TYPE' => 'W', 'QUERY_TIME' => $today->copy()->subDay()->setTimeFromTimeString('17:30')->format('Y-m-d H:i:s'),
                'SENDER_NAME' => 'Anil Desai', 'SENDER_MOBILE' => '+91-9000000302', 'SENDER_COMPANY' => 'Desai Hardware', 'SENDER_CITY' => 'Surat',
                'QUERY_PRODUCT_NAME' => 'Anchor bolts', 'QUERY_MESSAGE' => 'Need 2000 anchor bolts, please send a price.',
            ], $today->copy()->subDay()->setTimeFromTimeString('17:31'));
            LeadSource::query()->where('code', 'INDIAMART')->update(['status' => 'active']);

            IntegrationConnection::query()->whereKey($justDial->getKey())->update(['last_received_at' => $today->copy()->setTimeFromTimeString('08:41')]);
        });
    }

    /**
     * A week of leads in every state, made through the real services at the moments they "happened" (the clock is moved
     * for each step, so the history, the stages and what is overdue are all real): leads entered by a salesperson, a
     * tele-caller and the manager; one taken from the pool and handed over; follow-ups done, planned, overdue and called
     * off; a lost lead; a second inquiry from a number that is already a lead; and a call on someone else's lead given to
     * the tele-caller. (Fictitious firms and numbers.)
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleLeads(Company $company, array $locations): void
    {
        if (Lead::withTrashed()->withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $manager = $user('manager@formezy.test');
        $admin = $user('companyadmin@formezy.test');
        $bhavin = $user('bde@formezy.test');
        $chirag = $user('bde2@formezy.test');
        $tanvi = $user('telecaller@formezy.test');

        $today = now()->startOfDay();
        $soon = now()->addHours(2)->format('Y-m-d\TH:i');
        $day = fn (int $offset, string $time) => $today->copy()->addDays($offset)->format('Y-m-d').'T'.$time;

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $admin, $bhavin, $chirag, $tanvi, $locations, $today, $day, $soon) {
            // each step of the story happens at its own moment: so many days ago, at such a time
            $when = fn (int $daysAgo, string $time): CarbonInterface => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

            $leads = app(LeadService::class);
            $followups = app(FollowupService::class);
            $transfers = app(LeadTransferService::class);

            $source = fn (string $code) => (string) LeadSource::query()->where('code', $code)->value('id');
            $outcome = fn (string $code) => (string) FollowupOutcome::query()->where('code', $code)->value('id');
            $enter = fn (User $by, string $branch, string $from, string $name, string $mobile, array $more = [], array $interests = []) => $leads->create([
                'lead' => ['location_id' => (string) $locations[$branch]->id, 'lead_source_id' => $source($from), 'name' => $name, 'mobile' => $mobile, ...$more],
                'interests' => $interests,
            ], $by);
            $plan = fn (Lead $lead, User $by, string $when, string $type = 'call', ?User $for = null, string $notes = '') => $followups
                ->schedule($lead, ['plan' => ['scheduled_at' => $when, 'type' => $type, 'assigned_to' => $for === null ? '' : (string) $for->id, 'notes' => $notes]], $by);

            $items = app(ItemLookup::class)->search('', 2);
            $interests = [
                ...$items->map(fn (Item $item) => ['id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => '500', 'unit_id' => ''])->all(),
                ['id' => '', 'item_id' => '', 'description' => 'Stainless fasteners, mixed sizes (not in the item master yet)', 'quantity' => '', 'unit_id' => ''],
            ];

            // ---- 6 days ago: a reference comes in; a call is planned, made the next day, and a visit is fixed ----
            $shreeji = $this->at($when(6, '10:15'), fn () => $enter($bhavin, 'AHD', 'REFERENCE', 'Shreeji Hardware Mart', '9000000101', [
                'contact_person' => 'Mehul Shah', 'email' => 'mehul@shreejihardware.test', 'city' => 'Ahmedabad', 'pin_code' => '380001',
                'requirement' => 'Hex bolts and nuts for their retail counter, monthly', 'expected_value' => '180000',
            ]));
            $call = $this->at($when(6, '10:20'), fn () => $plan($shreeji, $bhavin, $day(-5, '11:00'), 'call', null, 'Ask about monthly volumes'));
            $this->at($when(5, '11:20'), fn () => $followups->complete($call, ['done' => ['outcome_id' => $outcome('INTERESTED'), 'notes' => 'Buys about 40 boxes a month; wants to see samples']], $bhavin));
            $this->at($when(5, '11:25'), fn () => $plan($shreeji, $bhavin, $day(1, '11:00'), 'visit', null, 'Take the sample box and the price list'));

            // ---- 4 days ago: a website inquiry lands in the pool, and the manager gives it to Bhavin ----
            $mahavir = $this->at($when(4, '09:40'), fn () => $enter($manager, 'AHD', 'WEBSITE', 'Mahavir Steel Traders', '9000000102', [
                'contact_person' => 'Jignesh Mehta', 'city' => 'Ahmedabad', 'requirement' => 'Asked for a dealer price list through the website form', 'assigned_to' => '',
            ]));
            $this->at($when(4, '15:05'), fn () => $transfers->transfer([$mahavir->id], $bhavin->id, 'Ahmedabad territory', $manager));
            $this->at($when(4, '15:30'), fn () => $plan($mahavir->fresh(), $bhavin, $day(2, '10:30'), 'call'));

            // ---- 3 days ago: a field lead with items of interest; its call for yesterday was missed (overdue) ----
            $patel = $this->at($when(3, '12:10'), fn () => $enter($bhavin, 'AHD', 'FIELD', 'Patel Engineering Works', '9000000103', [
                'contact_person' => 'Hasmukh Patel', 'address_line1' => 'Plot 14, Phase 2, GIDC', 'city' => 'Ahmedabad', 'pin_code' => '380001',
                'requirement' => 'Fasteners for a fabrication order starting next month', 'expected_value' => '650000', 'remarks' => 'Met at the Naroda estate',
            ], $interests));
            $this->at($when(3, '12:20'), fn () => $plan($patel, $bhavin, $day(-1, '16:00'), 'call', null, 'Confirm the quantities'));
            $this->at($when(3, '12:30'), fn () => $leads->changeStage($patel, LeadStage::Qualified, $bhavin, 'Budget and start date confirmed on site'));

            // ---- 3 days ago: the tele-caller's own lead: nobody picked up, to be rung again shortly ----
            $krishna = $this->at($when(3, '14:00'), fn () => $enter($tanvi, 'AHD', 'TELECALLER', 'Krishna Tools', '9000000104', ['contact_person' => 'Bharat Joshi', 'city' => 'Ahmedabad']));
            $this->at($when(3, '14:05'), fn () => $followups->log($krishna, ['log' => ['type' => 'call', 'outcome_id' => $outcome('CALL_BACK_LATER'), 'notes' => 'In a meeting, asked to be rung again', 'next_at' => $soon, 'next_type' => 'call']], $tanvi));

            // ---- 2 days ago: Mumbai: a visit made, a meeting fixed ----
            $sai = $this->at($when(2, '11:00'), fn () => $enter($chirag, 'MUM', 'REFERENCE', 'Sai Polymers', '9000000105', ['contact_person' => 'Nitin Kulkarni', 'city' => 'Mumbai', 'requirement' => 'HDPE granules, 2 tonnes a month']));
            $this->at($when(2, '17:10'), fn () => $followups->log($sai, ['log' => ['type' => 'visit', 'outcome_id' => $outcome('MEETING_FIXED'), 'notes' => 'Met the purchase head', 'next_at' => $day(2, '15:00'), 'next_type' => 'meeting']], $chirag));

            // ---- 2 days ago: one that did not work out ----
            $om = $this->at($when(2, '13:00'), fn () => $enter($bhavin, 'AHD', 'REFERENCE', 'Om Industries', '9000000106', ['contact_person' => 'Ramesh Solanki', 'city' => 'Ahmedabad', 'expected_value' => '90000']));
            $this->at($when(2, '13:05'), fn () => $plan($om, $bhavin, $day(1, '12:00'), 'call'));
            $this->at($when(1, '10:45'), fn () => $leads->changeStage($om, LeadStage::Lost, $bhavin, 'Their present supplier matched our price', (int) LostReason::query()->where('code', 'COMPETITOR')->value('id')));

            // ---- yesterday: the manager enters a lead straight for Chirag, who qualifies it ----
            $ganesh = $this->at($when(1, '09:50'), fn () => $enter($manager, 'MUM', 'INDIAMART', 'Ganesh Fasteners', '9000000107', [
                'contact_person' => 'Prakash Naik', 'city' => 'Mumbai', 'requirement' => 'Bulk M12 bolts', 'expected_value' => '420000', 'assigned_to' => (string) $chirag->id,
            ]));
            $this->at($when(1, '16:20'), fn () => $followups->log($ganesh, ['log' => ['type' => 'call', 'outcome_id' => $outcome('INTERESTED'), 'notes' => 'Wants a quotation for 10,000 pieces']], $chirag));
            $this->at($when(1, '16:25'), fn () => $leads->changeStage($ganesh->fresh(), LeadStage::Qualified, $chirag, 'Ready for a quotation'));

            // ---- today: a second inquiry from a number that is already a lead, and a call given to the tele-caller ----
            $this->at($when(0, '09:10'), fn () => $enter($bhavin, 'AHD', 'REFERENCE', 'Krishna Tools (machine shop)', '9000000104', [
                'contact_person' => 'Bharat Joshi', 'city' => 'Ahmedabad', 'requirement' => 'Separate inquiry for their machine shop', 'acknowledge_duplicate' => true,
            ]));
            $this->at($when(0, '09:15'), fn () => $plan($shreeji->fresh(), $admin, $day(1, '15:00'), 'call', $tanvi, 'Confirm tomorrow’s visit time with Mehul'));
        });
    }

    /**
     * Inquiries from existing customers and deals in every state, made through the real services at the moments they "happened":
     * an inquiry from an approved customer that went through approval (and had a store visit), a rough one still a draft; the
     * qualified lead Patel Engineering Works converted to a deal that was negotiated and approved; Ganesh Fasteners (Mumbai) a
     * fresh draft deal; a deal that waits for the approver; and one that was lost on price. (Fictitious firms, items and prices.)
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleDeals(Company $company, array $locations): void
    {
        if (Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $manager = $user('manager@formezy.test');
        $approver = $user('approver@formezy.test');
        $bhavin = $user('bde@formezy.test');
        $chirag = $user('bde2@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $approver, $bhavin, $chirag, $locations) {
            $today = now()->startOfDay();
            $when = fn (int $daysAgo, string $time): CarbonInterface => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

            $leads = app(LeadService::class);
            $deals = app(DealService::class);
            $approvals = app(ApprovalService::class);
            $followups = app(FollowupService::class);

            $source = fn (string $code) => (string) LeadSource::query()->where('code', $code)->value('id');
            $outcome = fn (string $code) => (string) FollowupOutcome::query()->where('code', $code)->value('id');
            $item = fn (string $term) => app(ItemLookup::class)->search($term, 1)->firstOrFail();
            $bolt = $item('Hex Bolt');
            $sheet = $item('Steel Sheet');
            $granules = $item('HDPE');
            $line = fn (Item $item, string $quantity, string $rate = '') => ['id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => $quantity, 'unit_id' => '', 'expected_rate' => $rate];

            // a lead of the company's own, qualified by the person who works it
            $qualified = function (User $by, string $branch, string $name, string $mobile, array $interests, int $daysAgo) use ($leads, $source, $when, $locations) {
                $lead = $this->at($when($daysAgo, '10:00'), fn () => $leads->create([
                    'lead' => ['location_id' => (string) $locations[$branch]->id, 'lead_source_id' => $source('REFERENCE'), 'name' => $name, 'mobile' => $mobile, 'contact_person' => 'Purchase head'],
                    'interests' => $interests,
                ], $by));

                return $this->at($when($daysAgo, '10:30'), fn () => $leads->changeStage($lead, LeadStage::Qualified, $by, 'Requirement and budget confirmed'));
            };

            // the deal made from a lead, its lines priced in the order the lead lists them (a line only described in words gets $fallback),
            // and any more lines the deal has of its own
            $convert = function (Lead $lead, User $by, array $rates, int $closeInDays, int $daysAgo, ?Item $fallback = null, array $own = []) use ($deals, $when, $today) {
                $rows = [];

                foreach ($deals->linesFromLead($lead) as $i => $row) {
                    $rows[] = [
                        'id' => '', 'lead_interest_id' => (string) $row['lead_interest_id'], 'item_id' => (string) ($row['item_id'] ?? $fallback?->id), 'unit_id' => (string) $row['unit_id'],
                        'quantity' => (string) ($row['quantity'] ?? '40'), 'expected_rate' => $rates[$i],
                    ];
                }

                $rows = [...$rows, ...$own];

                return $this->at($when($daysAgo, '11:00'), fn () => $deals->convert($lead, [
                    'deal' => ['customer_ledger_id' => '', 'expected_close_date' => $today->copy()->addDays($closeInDays)->toDateString(), 'probability' => '', 'remarks' => ''],
                    'items' => $rows,
                ], $by));
            };

            // ---- an inquiry from an existing customer: sent for approval, approved, and followed up with a store visit ----
            $customer = Ledger::query()->where('name', 'Shree Ganesh Engineering Pvt Ltd')->firstOrFail();
            $inquiry = $this->at($when(5, '10:10'), fn () => $leads->create([
                'lead' => [
                    'location_id' => (string) $locations['AHD']->id, 'lead_source_id' => $source('REFERENCE'), 'customer_ledger_id' => (string) $customer->id,
                    'name' => '', 'mobile' => '', 'requirement' => 'Quarterly bolts and granules for the Naroda plant',
                ],
                'interests' => [$line($bolt, '2000', '8.50'), $line($granules, '500', '92')],
            ], $bhavin));
            $this->at($when(5, '10:30'), fn () => $approvals->submit($inquiry, $bhavin));
            $this->at($when(4, '09:15'), fn () => $approvals->approve($inquiry->fresh(), $approver, 'Rates match the last order'));
            $visit = $this->at($when(4, '09:30'), fn () => $followups->schedule($inquiry->fresh(), ['plan' => ['scheduled_at' => $today->copy()->subDays(3)->format('Y-m-d').'T11:00', 'type' => 'visit', 'assigned_to' => '', 'notes' => 'Visit the plant stores']], $bhavin));
            $this->at($when(3, '12:30'), fn () => $followups->complete($visit, ['done' => ['outcome_id' => $outcome('STORE_VISITED'), 'notes' => 'Stock is low; they want delivery in two weeks']], $bhavin));
            $this->at($when(3, '12:40'), fn () => $leads->changeStage($inquiry->fresh(), LeadStage::Qualified, $bhavin, 'Quantities and rates confirmed at the store'));

            // …and a rough one from a walk-in customer, still a draft with no rate yet
            $walkIn = Ledger::query()->where('name', 'Walk-in Retail')->firstOrFail();
            $this->at($when(1, '15:00'), fn () => $leads->create([
                'lead' => ['location_id' => (string) $locations['AHD']->id, 'lead_source_id' => $source('REFERENCE'), 'customer_ledger_id' => (string) $walkIn->id, 'name' => '', 'mobile' => ''],
                'interests' => [$line($bolt, '300')],
            ], $bhavin));

            // ---- Patel Engineering Works (qualified in the leads story) becomes a deal: negotiated, sent for approval and approved ----
            $patel = Lead::query()->where('name', 'Patel Engineering Works')->firstOrFail();
            $patelDeal = $convert($patel->fresh(), $bhavin, ['9.20', '61', '45'], 21, 2, $sheet);
            $this->at($when(2, '12:00'), fn () => $deals->changeStage($patelDeal, DealStage::Negotiation, $bhavin, 'Price discussion started'));
            $this->at($when(2, '12:30'), fn () => $approvals->submit($patelDeal->fresh(), $bhavin));
            $this->at($when(1, '09:40'), fn () => $approvals->approve($patelDeal->fresh(), $approver, 'Within the agreed discount'));
            $this->at($when(1, '09:50'), fn () => $deals->updateProgress($patelDeal->fresh(), ['deal' => [
                'expected_close_date' => $today->copy()->addDays(14)->toDateString(), 'probability' => '70', 'remarks' => 'PO expected after the plant review',
            ]], $bhavin));

            // ---- Ganesh Fasteners (qualified in Mumbai) becomes a draft deal ----
            $ganesh = Lead::query()->where('name', 'Ganesh Fasteners')->firstOrFail();
            $ganeshDeal = $convert($ganesh->fresh(), $chirag, [], 45, 0, null, [
                ['id' => '', 'lead_interest_id' => '', 'item_id' => (string) $bolt->id, 'unit_id' => '', 'quantity' => '10000', 'expected_rate' => '7.90'],
            ]);

            // ---- a deal that waits for the approver ----
            $vinayak = $qualified($bhavin, 'AHD', 'Vinayak Castings', '9000000108', [$line($sheet, '1200'), $line($granules, '300')], 1);
            $waiting = $convert($vinayak, $bhavin, ['58', '90'], 30, 1);
            $this->at($when(0, '09:00'), fn () => $approvals->submit($waiting->fresh(), $bhavin));

            // ---- a deal lost on price, and its lead with it ----
            $rainbow = $qualified($bhavin, 'AHD', 'Rainbow Tools', '9000000109', [$line($bolt, '800')], 5);
            $lost = $convert($rainbow, $bhavin, ['9.75'], 20, 4);
            $this->at($when(2, '16:00'), fn () => $deals->changeStage($lost, DealStage::Lost, $bhavin, 'A local dealer is 8% cheaper', (int) LostReason::query()->where('code', 'PRICE')->value('id')));

            // the manager looks in on Mumbai's deal and sets its probability
            $this->at($when(0, '09:30'), fn () => $deals->updateProgress($ganeshDeal->fresh(), ['deal' => [
                'expected_close_date' => $today->copy()->addDays(40)->toDateString(), 'probability' => '25', 'remarks' => 'Quotation to follow',
            ]], $manager));
        });
    }

    /**
     * Quotations, through the same services the screens use: one for Patel's approved deal, priced from the rate master with GST for
     * Gujarat, approved and sent (so the deal moves to Quotation and a follow-up is planned) and then revised — Rev 0 superseded, Rev 1
     * approved and ready to send — and one made straight from the approved inquiry of an existing customer, approved and
     * ready to send. Nothing is e-mailed: a quotation is recorded as handed over.
     */
    private function sampleQuotations(Company $company): void
    {
        if (Quotation::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $approver = $user('approver@formezy.test');
        $bhavin = $user('bde@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($approver, $bhavin) {
            $today = now()->startOfDay();
            $when = fn (int $daysAgo, string $time): CarbonInterface => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

            $quotations = app(QuotationService::class);
            $approvals = app(ApprovalService::class);
            $pricer = app(QuotationPricer::class);
            $gujarat = (string) State::query()->where('gst_code', '24')->value('id');

            $input = function (Deal|Lead $source, string $address) use ($quotations, $pricer, $gujarat) {
                $lead = $source instanceof Deal ? $source->lead()->firstOrFail() : $source;

                return [
                    'quotation' => [...$pricer->defaults($lead, $source instanceof Deal ? $source : null), 'place_of_supply_state_id' => $gujarat, 'bill_line1' => $address, 'bill_city' => 'Ahmedabad', 'bill_pin_code' => '380001', 'reprice' => ''],
                    'items' => array_map(fn (array $row) => [
                        'id' => '', 'deal_item_id' => (string) ($row['deal_item_id'] ?? ''), 'lead_interest_id' => (string) ($row['lead_interest_id'] ?? ''), 'item_id' => (string) $row['item_id'],
                        'unit_id' => (string) $row['unit_id'], 'quantity' => (string) ($row['quantity'] ?? '1'), 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
                    ], $quotations->startingLines($lead, $source instanceof Deal ? $source : null)),
                ];
            };

            // ---- Patel Engineering Works: a quotation from the approved deal, sent, then revised ----
            $deal = Deal::query()->where('party_name', 'Patel Engineering Works')->firstOrFail();
            $first = $this->at($when(1, '14:00'), fn () => $quotations->create($deal, $input($deal, 'Plot 14, Naroda GIDC'), $bhavin));
            $this->at($when(1, '14:20'), fn () => $approvals->submit($first, $bhavin));
            $this->at($when(1, '15:00'), fn () => $approvals->approve($first->fresh(), $approver, 'Prices as per the rate master'));
            $sent = $this->at($when(1, '15:10'), fn () => $quotations->send($first->fresh(), ['channel' => 'manual'], $bhavin));
            $rev = $this->at($when(0, '10:00'), fn () => $quotations->revise($sent->fresh(), $bhavin, 'The customer asked for a different quantity'));
            $this->at($when(0, '10:20'), fn () => $approvals->submit($rev, $bhavin));
            $this->at($when(0, '10:40'), fn () => $approvals->approve($rev->fresh(), $approver, 'Quantity change checked'));

            // ---- Shree Ganesh Engineering: a quotation straight from the approved inquiry, approved, not yet sent ----
            $inquiry = Lead::query()->where('lead_type', 'existing')->whereNotNull('customer_ledger_id')->where('stage', LeadStage::Qualified->value)->where('approval_status', 'approved')->firstOrFail();
            $direct = $this->at($when(0, '11:00'), fn () => $quotations->create($inquiry, $input($inquiry, 'Plot 7, Naroda GIDC'), $bhavin));
            $this->at($when(0, '11:15'), fn () => $approvals->submit($direct, $bhavin));
            $this->at($when(0, '11:45'), fn () => $approvals->approve($direct->fresh(), $approver, 'Same rates as the last order'));
        });
    }

    /**
     * Sales orders, through the same services the screens use: one made from an approved deal, approved — which makes its invoice and wins the
     * deal — and one made the same way that waits in the approver's inbox. Both are for Shree Ganesh Engineering, whose first approved order
     * also makes them a regular customer (so a direct order can be tried).
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleOrders(Company $company, array $locations): void
    {
        if (SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $approver = $user('approver@formezy.test');
        $bhavin = $user('bde@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($approver, $bhavin, $locations) {
            $today = now()->startOfDay();
            $when = fn (int $daysAgo, string $time): CarbonInterface => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

            $leads = app(LeadService::class);
            $deals = app(DealService::class);
            $orders = app(SalesOrderService::class);
            $pricer = app(OrderPricer::class);
            $approvals = app(ApprovalService::class);
            $customer = Ledger::query()->where('name', 'Shree Ganesh Engineering Pvt Ltd')->firstOrFail();
            $gujarat = (string) State::query()->where('gst_code', '24')->value('id');
            $item = fn (string $term) => app(ItemLookup::class)->search($term, 1)->firstOrFail();
            $bolt = $item('Hex Bolt');
            $sheet = $item('Steel Sheet');
            $interest = fn (Item $item, string $quantity) => ['id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => $quantity, 'unit_id' => '', 'expected_rate' => ''];

            // an approved deal for the customer: a lead qualified by the salesperson, converted with the customer linked, and approved
            $dealFor = function (string $name, string $mobile, array $interests, int $daysAgo) use ($leads, $deals, $approvals, $bhavin, $approver, $customer, $locations, $when, $today) {
                $lead = $this->at($when($daysAgo, '10:00'), fn () => $leads->create([
                    'lead' => ['location_id' => (string) $locations['AHD']->id, 'lead_source_id' => (string) LeadSource::query()->where('code', 'REFERENCE')->value('id'), 'name' => $name, 'mobile' => $mobile, 'contact_person' => 'Purchase head'],
                    'interests' => $interests,
                ], $bhavin));
                $lead = $this->at($when($daysAgo, '10:30'), fn () => $leads->changeStage($lead, LeadStage::Qualified, $bhavin, 'Requirement confirmed'));

                $deal = $this->at($when($daysAgo, '11:00'), fn () => $deals->convert($lead, [
                    'deal' => ['customer_ledger_id' => (string) $customer->id, 'expected_close_date' => $today->copy()->addDays(10)->toDateString(), 'probability' => '', 'remarks' => ''],
                    'items' => array_map(fn (array $row) => [
                        'id' => '', 'lead_interest_id' => (string) $row['lead_interest_id'], 'item_id' => (string) $row['item_id'], 'unit_id' => (string) $row['unit_id'],
                        'quantity' => (string) ($row['quantity'] ?? '1'), 'expected_rate' => '5',
                    ], $deals->linesFromLead($lead)),
                ], $bhavin));
                $this->at($when($daysAgo, '11:30'), fn () => $approvals->submit($deal, $bhavin));
                $this->at($when($daysAgo, '14:00'), fn () => $approvals->approve($deal->fresh(), $approver, 'Customer confirmed on the phone'));

                return $deal->fresh();
            };

            // the order for a deal, priced from the rate master (a deal needs no quotation)
            $orderFor = function (Deal $deal, int $daysAgo, string $po) use ($orders, $pricer, $customer, $gujarat, $bhavin, $when) {
                $input = [
                    'order' => [...$pricer->defaults($customer, null, $deal), 'place_of_supply_state_id' => $gujarat, 'bill_line1' => 'Plot 7, Naroda GIDC', 'bill_city' => 'Ahmedabad', 'bill_pin_code' => '382330',
                        'customer_po_number' => $po, 'customer_po_date' => $when($daysAgo, '09:00')->toDateString(), 'reprice' => ''],
                    'items' => array_map(fn (array $row) => [
                        'id' => '', 'quotation_item_id' => '', 'deal_item_id' => (string) $row['deal_item_id'], 'item_id' => (string) $row['item_id'], 'unit_id' => (string) $row['unit_id'],
                        'quantity' => (string) $row['quantity'], 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
                    ], $orders->startingLines(null, $deal)),
                    'customer_ledger_id' => (string) $customer->id,
                ];

                return $this->at($when($daysAgo, '15:00'), fn () => $orders->createFromDeal($deal, $input, $bhavin));
            };

            // ---- approved, and so invoiced; the deal is won ----
            $won = $dealFor('Naroda Auto Components', '9000000401', [$interest($bolt, '4000'), $interest($sheet, '350')], 3);
            $first = $orderFor($won, 2, 'GE/PO/2610/114');
            $this->at($when(2, '15:30'), fn () => $approvals->submit($first, $bhavin));
            $this->at($when(1, '10:15'), fn () => $approvals->approve($first->fresh(), $approver, 'Matches the customer order'));

            // ---- waiting for the approver ----
            $waiting = $dealFor('Sabarmati Fabricators', '9000000402', [$interest($sheet, '900')], 1);
            $second = $orderFor($waiting, 0, 'GE/PO/2610/131');
            $this->at($when(0, '12:00'), fn () => $approvals->submit($second, $bhavin));

            // ---- an approved deal nobody has ordered yet (the browser check makes its order) ----
            $dealFor('Kalupur Dies', '9000000403', [$interest($bolt, '1200')], 1);
        });
    }

    /**
     * Tasks and service levels, through the same services the screens use: two SLA policies (a first response to a new lead within four working
     * hours; a task escalated two hours after its deadline) and a holiday, then tasks for the salesperson and the manager — one about a lead,
     * one overdue, one repeating, one done — and one sweep so the clocks and any breach are on show.
     */
    private function sampleWorkspace(Company $company): void
    {
        if (Task::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $bhavin = $user('bde@formezy.test');
        $manager = $user('manager@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($bhavin, $manager) {
            $today = now()->startOfDay();
            $tasks = app(TaskService::class);

            $policy = new SlaPolicy;
            $policy->fill(['name' => 'New lead: first response within 4 working hours', 'kind' => 'first_response', 'target_minutes' => 240, 'business_hours' => true, 'escalate_to_manager' => true])->save();
            $policy->forceFill(['created_at' => $today->copy()->subDays(60)])->saveQuietly();

            $late = new SlaPolicy;
            $late->fill(['name' => 'Task: escalate 2 hours after the deadline', 'kind' => 'task', 'target_minutes' => 120, 'business_hours' => false, 'escalate_to_manager' => true])->save();
            $late->forceFill(['created_at' => $today->copy()->subDays(60)])->saveQuietly();

            (new Holiday)->fill(['holiday_date' => $today->copy()->addDays(25)->toDateString(), 'name' => 'Company holiday (example)'])->save();

            $patel = Lead::query()->where('name', 'Patel Engineering Works')->first();

            $tasks->create([
                'title' => 'Send the corrected price list to Patel Engineering Works', 'priority' => 'high', 'due_at' => $today->copy()->addDay()->setTime(11, 0)->toDateTimeString(),
                'remind_at' => $today->copy()->addDay()->setTime(9, 0)->toDateTimeString(), 'subject_type' => $patel === null ? '' : 'lead', 'subject_id' => $patel?->id,
            ], $bhavin);

            // an overdue one: made two days ago for yesterday
            $this->at($today->copy()->subDays(2)->setTime(10, 0), fn () => $tasks->create([
                'title' => 'Collect the customer’s purchase order for the Naroda quotation', 'priority' => 'urgent', 'due_at' => $today->copy()->subDay()->setTime(17, 0)->toDateTimeString(),
            ], $bhavin));

            // a done one, with its note
            $done = $this->at($today->copy()->subDays(3)->setTime(9, 30), fn () => $tasks->create(['title' => 'Update the sample register', 'priority' => 'low', 'due_at' => $today->copy()->subDays(2)->setTime(17, 0)->toDateTimeString()], $bhavin));
            $this->at($today->copy()->subDays(2)->setTime(15, 0), fn () => $tasks->complete($done, 'Register updated and shared with Operations.', $bhavin));

            // the manager gives one to the salesperson, and keeps a weekly one for themselves
            $tasks->create(['title' => 'Review the pending quotations with the team', 'priority' => 'normal', 'due_at' => $today->copy()->addDays(3)->setTime(16, 0)->toDateTimeString(), 'assigned_to' => $bhavin->id], $manager);
            $tasks->create([
                'title' => 'Weekly pipeline review', 'priority' => 'normal', 'due_at' => $today->copy()->addDays(2)->setTime(10, 0)->toDateTimeString(), 'recurrence' => 'weekly',
                'recurrence_until' => $today->copy()->addMonths(3)->toDateString(),
            ], $manager);

            // one pass: clocks start for the leads and tasks, and the overdue task's clock is already past its time
            app(SlaSweep::class)->run();
        });
    }

    /**
     * Marketing, through the same services the screens use: two approved message templates (one marketing, one transactional), a few people's consent
     * (one has opted out), a running e-mail campaign over new leads, a draft campaign, a workflow that makes a call task for every new lead, and two more
     * service levels (a quotation answered, an approval decided). Nothing is sent: mail goes to the log in a demo.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleMarketing(Company $company, array $locations): void
    {
        if (MessageTemplate::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $manager = $user('manager@formezy.test');
        $admin = $user('companyadmin@formezy.test');
        $approver = $user('approver@formezy.test');
        $branch = $locations['AHD'];

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $admin, $approver, $branch) {
            $approvals = app(ApprovalService::class);
            $templates = app(TemplateService::class);
            $approved = function (array $input) use ($templates, $approvals, $manager, $approver) {
                $t = $templates->save(null, $input, $manager);
                $approvals->submit($t, $manager);
                $approvals->approve($t->fresh(), $approver);

                return $t->fresh();
            };

            $offer = $approved([
                'name' => 'Autumn offer', 'channel' => 'email', 'purpose' => 'marketing', 'subject' => 'An offer for {{first_name}}',
                'body' => "Dear {{name}},\n\nWe have a new offer on fasteners from {{company}}.\n\nStop these messages: {{unsubscribe_url}}",
            ]);
            $approved([
                'name' => 'Quotation follow-up', 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'About your quotation',
                'body' => "Dear {{name}},\n\nWe are following up on the quotation we sent you. Please tell us if we can help.",
            ]);

            $consent = app(ConsentService::class);
            $consent->record(Channel::Email, 'purchase@patel-engineering.example', 'opted_in', 'form', 'Ticked the box on the website form.', $manager);
            $consent->record(Channel::Email, 'info@shah-forgings.example', 'opted_in', 'manual', 'Agreed on a call.', $manager);
            $consent->record(Channel::Email, 'owner@kalupur-dies.example', 'opted_out', 'manual', 'Asked us to stop.', $manager);

            $campaigns = app(CampaignService::class);
            $running = $campaigns->create([
                'name' => 'Autumn fasteners offer', 'type' => 'email', 'objective' => 'Bring new inquiries back to a quotation.', 'start_date' => now()->toDateString(),
                'audience_kind' => 'leads', 'audience' => ['stages' => ['new', 'contacted']], 'location_id' => (string) $branch->id, 'owner_id' => (string) $manager->id,
                'steps' => [['delay_hours' => '0', 'message_template_id' => (string) $offer->id]],
            ], $manager);
            $approvals->submit($running, $manager);
            $approvals->approve($running->fresh(), $approver);
            $campaigns->launch($running->fresh(), $manager);

            $campaigns->create([
                'name' => 'Festival greetings (draft)', 'type' => 'email', 'start_date' => now()->addDays(20)->toDateString(), 'audience_kind' => 'leads',
                'audience' => ['stages' => ['qualified']], 'location_id' => (string) $branch->id, 'owner_id' => (string) $manager->id,
                'steps' => [['delay_hours' => '0', 'message_template_id' => (string) $offer->id]],
            ], $manager);

            $workflows = app(WorkflowService::class);
            $workflow = $workflows->create([
                'name' => 'Call every new lead within a day', 'description' => 'Makes a call task for the lead’s owner as soon as the lead is entered.', 'trigger' => 'lead_created',
                'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'Call {{name}} ({{reference}})', 'assignee' => 'owner', 'due_hours' => '24', 'priority' => 'high']],
            ], $manager);
            $workflows->activate($workflow, $admin);

            foreach ([
                ['name' => 'Quotation: answered within 3 days', 'kind' => 'quotation_response', 'target_minutes' => 4320, 'business_hours' => false],
                ['name' => 'Approval: decided within 1 working day', 'kind' => 'approval', 'target_minutes' => 480, 'business_hours' => true],
            ] as $row) {
                $policy = new SlaPolicy;
                $policy->fill([...$row, 'escalate_to_manager' => true])->save();
                $policy->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();
            }
        });
    }

    /**
     * Do something at a moment in the past, so that what the services record (dates, history, what is overdue by now)
     * is what it would have been.
     *
     * @template T
     *
     * @param  \Closure(): T  $step
     * @return T
     */
    private function at(CarbonInterface $moment, \Closure $step): mixed
    {
        return Carbon::withTestNow($moment, $step);
    }

    /**
     * Two sales teams under the Sales Manager, targets for this month and quarter, and a week of route plans, attendance
     * and refused attempts — all made through the real services, with dates relative to today so every state is always
     * on show: Bhavin has a published plan for today (so attendance can be tried), Chirag has none (so it is refused).
     * (Fictitious places and figures; the coordinates are ordinary public points in Ahmedabad and Mumbai.)
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleFieldSales(Company $company, array $locations): void
    {
        if (SalesTeam::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $manager = User::query()->where('email', 'manager@formezy.test')->firstOrFail();
        $bhavin = User::query()->where('email', 'bde@formezy.test')->firstOrFail();
        $chirag = User::query()->where('email', 'bde2@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($manager, $bhavin, $chirag, $locations) {
            $teams = app(SalesTeamService::class);
            $targets = app(TargetService::class);
            $plans = app(RoutePlanService::class);
            $attendance = app(AttendanceService::class);

            $today = now()->startOfDay();
            $day = fn (int $offset) => $today->copy()->addDays($offset)->toDateString();

            // ---- teams ----
            $gujarat = $teams->saveTeam(null, ['team' => ['code' => 'GUJ-FIELD', 'name' => 'Gujarat field team', 'manager_user_id' => (string) $manager->id, 'status' => 'active']], $manager);
            $maharashtra = $teams->saveTeam(null, ['team' => ['code' => 'MAH-FIELD', 'name' => 'Maharashtra field team', 'manager_user_id' => (string) $manager->id, 'status' => 'active']], $manager);
            $teams->addMember($gujarat, $bhavin->id, $today->copy()->subMonths(3)->startOfMonth()->toDateString(), $manager);
            $teams->addMember($maharashtra, $chirag->id, $today->copy()->subMonth()->startOfMonth()->toDateString(), $manager);

            // ---- targets ----
            $target = fn (array $fields) => $targets->save(null, ['target' => [
                'subject' => 'user', 'user_id' => '', 'sales_team_id' => '', 'period_type' => 'monthly', 'period_start' => $today->toDateString(),
                'target_value' => '', 'target_quantity' => '', 'item_group_id' => '', 'location_id' => '', 'allow_overlap' => false, 'status' => 'active', 'remarks' => '',
                ...$fields,
            ]], $manager);
            $steel = (string) ItemGroup::query()->where('code', 'RM-STL')->value('id');

            $target(['user_id' => (string) $bhavin->id, 'target_value' => '500000']);
            $target(['user_id' => (string) $bhavin->id, 'target_value' => '450000', 'period_start' => $today->copy()->subMonthNoOverflow()->toDateString()]);
            $target(['user_id' => (string) $bhavin->id, 'target_value' => '550000', 'period_start' => $today->copy()->addMonthNoOverflow()->toDateString()]);
            $target(['subject' => 'team', 'sales_team_id' => (string) $gujarat->id, 'period_type' => 'quarterly', 'target_value' => '2000000']);
            $target(['user_id' => (string) $chirag->id, 'target_quantity' => '4000', 'item_group_id' => $steel, 'location_id' => (string) $locations['MUM']->id, 'remarks' => 'Steel only, Mumbai branch']);

            // ---- route plans ----
            $stop = fn (string $label, ?float $lat = null, ?float $lng = null, array $more = []) => [
                'id' => '', 'stop_type' => 'area', 'label' => $label, 'block_id' => '', 'pin_code' => '', 'ledger_id' => '',
                'latitude' => $lat === null ? '' : (string) $lat, 'longitude' => $lng === null ? '' : (string) $lng, 'radius_m' => '', 'visit_from' => '', 'visit_to' => '', ...$more,
            ];
            $plan = function (User $person, string $branch, string $date, string $title, array $stops, bool $publish = true) use ($plans, $manager, $locations) {
                $made = $plans->save(null, [
                    'plan' => ['user_id' => (string) $person->id, 'location_id' => (string) $locations[$branch]->id, 'plan_date' => $date, 'title' => $title, 'geofence_radius_m' => '', 'remarks' => ''],
                    'stops' => $stops,
                ], $manager, $date);   // "today" is the plan's own day, so the days already gone can be seeded too

                return $publish ? $plans->publish($made, $manager, $date) : $made;
            };

            $ahmedabadRound = [
                $stop('Head office yard (start of the round)', 23.0225, 72.5714, ['visit_from' => '09:30', 'visit_to' => '10:00']),
                $stop('Naroda GIDC', 23.0700, 72.6600, ['visit_from' => '10:30', 'visit_to' => '12:30']),
                $stop('Odhav GIDC', 23.0260, 72.6660, ['radius_m' => '800', 'visit_from' => '14:00', 'visit_to' => '16:00']),
            ];

            $customer = app(LedgerLookup::class)->search(LedgerType::Customer, '', 1)->first();
            $block = Block::query()->where('name', 'Daskroi')->first();
            $todaysRound = [
                ...$ahmedabadRound,
                ...($block === null ? [] : [$stop('', null, null, ['stop_type' => 'block', 'block_id' => (string) $block->id])]),
                ...($customer === null ? [] : [$stop('', null, null, ['stop_type' => 'customer', 'ledger_id' => (string) $customer->id])]),
            ];

            foreach ([-3, -2, -1] as $offset) {
                $plan($bhavin, 'AHD', $day($offset), 'Naroda and Odhav industrial estates', $ahmedabadRound);
            }
            $plan($bhavin, 'AHD', $day(0), 'Naroda and Odhav industrial estates', $todaysRound);
            $plan($bhavin, 'AHD', $day(1), 'Vatva and Narol', [
                $stop('Vatva GIDC', 22.9676, 72.6495), $stop('Narol crossroads', 22.9760, 72.5880), $stop('Call on the transporters at Aslali (no fixed place)'),
            ], publish: false);
            $plan($chirag, 'MUM', $day(1), 'Andheri and Powai', [$stop('Mumbai branch (start of the round)', 19.0760, 72.8777), $stop('Andheri MIDC', 19.1136, 72.8697)]);

            // ---- attendance and refused attempts ----
            $at = fn (int $offset, string $time) => Carbon::parse($day($offset).' '.$time);
            $try = function (User $person, float $lat, float $lng, float $accuracy, Carbon $when) use ($attendance) {
                try {
                    $attendance->mark($person, $lat, $lng, $accuracy, 'mobile', 'Demo data', $when);
                } catch (AttendanceRefused) {
                    // refused attempts are part of the demo: they are in the violation log
                }
            };

            $try($bhavin, 23.0300, 72.5714, 14.0, $at(-3, '09:41'));    // 3 days ago: 830 m away, refused, and left that way (a manager can accept it once the override is switched on)
            $try($bhavin, 23.0231, 72.5720, 11.0, $at(-2, '09:31'));    // 2 days ago: at the yard, accepted
            $try($bhavin, 23.0309, 72.5802, 18.0, $at(-1, '09:35'));    // yesterday: too far, refused…
            $try($bhavin, 23.0228, 72.5711, 9.0, $at(-1, '09:48'));     // …then from the yard, accepted
            $try($bhavin, 23.0226, 72.5713, 260.0, $at(-1, '18:02'));   // (a later tap the same day is simply "already marked")
            $try($chirag, 19.0762, 72.8779, 12.0, $at(-1, '09:20'));    // Chirag had no plan yesterday: refused, logged
        });
    }

    /**
     * Items in every stage of the three-department workflow, taken through the real service and workflow: Purchase
     * starts them, Inventory completes them, Accounts approves. (Fictitious products.)
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleItems(Company $company, array $locations): void
    {
        if (Item::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $purchase = User::query()->where('email', 'purchase@formezy.test')->firstOrFail();
        $inventory = User::query()->where('email', 'inventory@formezy.test')->firstOrFail();
        $accounts = User::query()->where('email', 'accounts@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($purchase, $inventory, $accounts, $locations) {
            $items = app(ItemService::class);
            $workflow = app(ItemWorkflow::class);

            $group = fn (string $code) => (string) ItemGroup::query()->where('code', $code)->value('id');
            $unit = fn (string $code) => (string) Unit::query()->where('code', $code)->value('id');
            $stock = fn (string $branch, string $min, string $reorder, string $max) => [
                'id' => '', 'location_id' => (string) $locations[$branch]->id, 'minimum_stock' => $min, 'reorder_level' => $reorder, 'maximum_stock' => $max,
            ];

            // [name, group, base unit, HSN, GST, tolerance, [[unit, factor]…], code, [aliases], [stock rows], how far to take it]
            $catalogue = [
                ['MS Hex Bolt M10 x 50', 'RM-STL', 'NOS', '7318', '18', '5', [['BOX', '100']], 'ITM-00001', ['hex bolt 10mm', 'M10 bolt'], [$stock('AHD', '500', '1500', '5000'), $stock('MUM', '200', '600', '2000')], 'approved'],
                ['Mild Steel Sheet 2 mm', 'RM-STL', 'KG', '7208', '18', '2.5', [['TON', '1000']], 'ITM-00002', ['MS sheet', 'steel plate 2mm'], [$stock('AHD', '1000', '3000', '10000')], 'approved'],
                ['HDPE Granules', 'RM-PLY', 'KG', '3901', '18', '3', [['BAG', '25']], 'ITM-00003', ['HDPE', 'polyethylene granules'], [$stock('SRT', '250', '750', '2500')], 'approved'],
                ['Corrugated Carton 5-ply', 'PKG', 'NOS', '4819', '12', '0', [['BAG', '50']], 'ITM-00004', ['5 ply box'], [$stock('AHD', '100', '300', '1000')], 'submitted'],
                ['Cutting Oil 20 L', 'CON', 'LTR', '2710', '18', '0', [], null, [], [], 'inventory'],
                ['Brass Washer 6 mm', 'RM', 'NOS', '', '', '0', [], null, [], [], 'draft'],
                ['Zinc Plated Nut M10', 'RM-STL', 'NOS', '7318', '18', '5', [['BOX', '200']], 'ITM-00007', [], [], 'returned'],
            ];

            // Fictitious brands and costs (per base unit), so the demo invoices carry a cost and the profit figures have something to show.
            $brands = [];
            foreach ([['FASTX', 'FastX Fasteners'], ['STEELCO', 'SteelCo'], ['POLYPLUS', 'PolyPlus']] as [$brandCode, $brandName]) {
                $brands[$brandCode] = Brand::query()->create(['code' => $brandCode, 'name' => $brandName])->id;
            }
            $brandOf = ['MS Hex Bolt M10 x 50' => 'FASTX', 'Zinc Plated Nut M10' => 'FASTX', 'Mild Steel Sheet 2 mm' => 'STEELCO', 'HDPE Granules' => 'POLYPLUS'];
            $costOf = ['MS Hex Bolt M10 x 50' => '3.40', 'Mild Steel Sheet 2 mm' => '58.00', 'HDPE Granules' => '92.00', 'Corrugated Carton 5-ply' => '14.00'];

            foreach ($catalogue as [$name, $groupCode, $baseUnit, $hsn, $gst, $tolerance, $uoms, $code, $aliases, $stockRows, $upTo]) {
                $item = $items->savePurchaseStage(null, [
                    'item' => [
                        'name' => $name, 'description' => '', 'item_group_id' => $group($groupCode), 'base_unit_id' => $unit($baseUnit),
                        'po_tolerance_percent' => $tolerance, 'hsn_code' => $hsn, 'gst_rate' => $gst,
                        'brand_id' => isset($brandOf[$name]) ? (string) $brands[$brandOf[$name]] : '', 'standard_cost' => $costOf[$name] ?? '',
                    ],
                    'uoms' => array_map(fn ($u) => ['id' => '', 'unit_id' => $unit($u[0]), 'factor' => $u[1]], $uoms),
                ], $purchase);

                if ($upTo === 'draft') {
                    continue;
                }

                $workflow->sendToInventory($item, $purchase);

                if ($upTo === 'inventory') {
                    continue;
                }

                $items->saveInventoryStage($item->fresh(), [
                    'inventory' => ['code' => $code],
                    'aliases' => array_map(fn ($a) => ['id' => '', 'alias' => $a], $aliases),
                    'locations' => $stockRows,
                ], $inventory);
                $workflow->submit($item->fresh(), $inventory);

                if ($upTo === 'approved') {
                    $workflow->approve($item->fresh(), $accounts, 'Checked HSN and GST rate against the supplier invoice');
                } elseif ($upTo === 'returned') {
                    $workflow->reject($item->fresh(), $accounts, 'This is a duplicate of the Zinc Plated Hex Nut we already stock. Please confirm before we add it.', ItemStage::PurchaseDraft);
                }
            }
        });
    }

    /**
     * Purchasing and stock, made through the real services and approvals so the stock is posted the way it would be: an approved order to the
     * steel supplier, received twelve days ago (two boxes rejected), some bolts scrapped and some moved to Mumbai, and a draft order waiting.
     * Prices are fictitious. Runs before the sales samples, so their invoices take the moving-average cost of this stock.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleProcurement(Company $company, array $locations): void
    {
        if (PurchaseOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $purchase = $user('purchase@formezy.test');
        $inventory = $user('inventory@formezy.test');
        $accounts = $user('accounts@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($purchase, $inventory, $accounts, $locations) {
            $today = now()->startOfDay();
            $approvals = app(ApprovalService::class);
            $supplier = Ledger::query()->where('name', 'Ahmedabad Steel Traders')->firstOrFail();
            $bolt = app(ItemLookup::class)->search('Hex Bolt', 1)->firstOrFail();
            $sheet = app(ItemLookup::class)->search('Steel Sheet', 1)->firstOrFail();
            $unit = fn (string $code) => (string) Unit::query()->where('code', $code)->value('id');
            $line = fn (Item $item, string $unitCode, string $qty, string $rate) => ['item_id' => (string) $item->id, 'unit_id' => $unit($unitCode), 'quantity' => $qty, 'rate' => $rate];

            $po = $this->at($today->copy()->subDays(20)->setTime(11, 0), function () use ($purchase, $accounts, $approvals, $supplier, $line, $bolt, $sheet, $locations) {
                $po = app(PurchaseOrderService::class)->create([
                    'location_id' => (string) $locations['AHD']->id, 'supplier_ledger_id' => (string) $supplier->id, 'terms' => 'Delivery to the Ahmedabad stores. Payment 45 days.',
                    'items' => [$line($bolt, 'BOX', '50', '330'), $line($sheet, 'TON', '2', '57000')],
                ], $purchase);
                $approvals->submit($po, $purchase);
                $approvals->approve($po->fresh(), $accounts);

                return $po->fresh();
            });

            $this->at($today->copy()->subDays(12)->setTime(15, 30), function () use ($po, $inventory, $accounts, $approvals) {
                $lines = $po->items()->get();
                $grn = app(GrnService::class)->create($po, ['supplier_invoice_no' => 'AST/26-27/0418', 'lines' => [
                    $lines[0]->id => ['accepted' => '48', 'rejected' => '2', 'reject_reason' => 'Threads damaged in two boxes'],
                    $lines[1]->id => ['accepted' => '2'],
                ]], $inventory);
                $approvals->submit($grn, $inventory);
                $approvals->approve($grn->fresh(), $accounts);   // the stock is posted when it is approved
            });

            $this->at($today->copy()->subDays(5)->setTime(10, 0), fn () => app(StockService::class)
                ->scrap($bolt->fresh(), $locations['AHD']->id, '100', null, 'Rusted after the roof leak in bay 3', $inventory));
            $this->at($today->copy()->subDays(3)->setTime(12, 0), fn () => app(StockService::class)
                ->transfer($bolt->fresh(), $locations['AHD']->id, $locations['MUM']->id, '500', null, 'Mumbai branch ran short', $inventory));

            app(PurchaseOrderService::class)->create([
                'location_id' => (string) $locations['MUM']->id, 'supplier_ledger_id' => (string) $supplier->id, 'items' => [$line($bolt, 'BOX', '20', '335')],
            ], $purchase);   // a draft, still to be sent for approval
        });
    }

    /**
     * Loss management, made through the real services and workflow so the history reads as it would: Automotive switched on; the bolts scrapped in
     * the procurement sample reported as a loss and its figure validated; a press-line stoppage (a safety risk, escalated) taken all the way to
     * closed — investigated, root cause by five whys, a preventive action done and verified with its saving — and a warranty exposure still in
     * draft. Fictitious figures.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleLoss(Company $company, array $locations): void
    {
        if (LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $admin = $user('companyadmin@formezy.test');
        $inventory = $user('inventory@formezy.test');
        $quality = $user('quality@formezy.test');
        $investigator = $user('investigator@formezy.test');

        app(TenantContext::class)->withCompany($company->id, function () use ($admin, $inventory, $quality, $investigator, $locations) {
            $today = now()->startOfDay();
            $auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
            $category = fn (string $code) => (string) LossCategory::query()->where('industry_template_id', $auto->id)->where('code', $code)->value('id');
            $incidents = app(LossIncidentService::class);
            $calc = app(LossCalculationService::class);
            $workflow = app(LossWorkflowService::class);
            $capa = app(CapaService::class);
            $as = function (User $who, \Closure $step) {
                Auth::setUser($who);

                return $step();
            };

            $as($admin, fn () => app(IndustryTemplateService::class)->enable($auto, $admin));

            // 1. the scrapped bolts, reported from the stock ledger, its figure validated by someone else
            $scrap = StockMovement::query()->where('movement_type', StockMovement::SCRAP)->first();
            if ($scrap !== null) {
                $found = app(LossSources::class)->describe('stock_movement', $scrap->id);
                $one = $this->at($today->copy()->subDays(5)->setTime(11, 0), fn () => $as($inventory, fn () => $incidents->create([
                    'industry_template_id' => (string) $auto->id, 'loss_category_id' => $category('component_defect'), 'location_id' => (string) $locations['AHD']->id,
                    'occurred_at' => $today->copy()->subDays(5)->setTime(9, 30)->format('Y-m-d H:i'), 'severity' => 'medium', 'title' => 'Rusted bolts scrapped after the roof leak',
                    'description' => 'Water came through the roof over bay 3; 100 bolts rusted and were scrapped.', 'item_id' => (string) $scrap->item_id, 'quantity' => '100',
                    'custom_fields' => ['line' => 'Stores bay 3'], 'source_type' => 'stock_movement', 'source_id' => (string) $scrap->id, 'impact' => $found['impact'] ?? null,
                ], $inventory)));
                $this->at($today->copy()->subDays(4)->setTime(10, 0), function () use ($as, $quality, $calc, $workflow, $inventory, $one) {
                    $as($inventory, fn () => $workflow->transition($one, 'submitted', null, $inventory));
                    $as($quality, fn () => $calc->validateLine($one->impactLines()->firstOrFail(), $quality));
                    $as($quality, fn () => $workflow->transition($one->fresh(), 'triage', null, $quality));
                });
            }

            // 2. a press-line stoppage: a safety risk, escalated at once, taken all the way to closed
            $two = $this->at($today->copy()->subDays(14)->setTime(23, 10), fn () => $as($inventory, fn () => $incidents->create([
                'industry_template_id' => (string) $auto->id, 'loss_category_id' => $category('line_stoppage'), 'location_id' => (string) $locations['AHD']->id,
                'occurred_at' => $today->copy()->subDays(14)->setTime(22, 40)->format('Y-m-d H:i'), 'severity' => 'high', 'is_safety' => true,
                'title' => 'Press line 3 stopped: hydraulic hose burst', 'description' => 'A hydraulic hose on press 3 burst during the night shift. Nobody was hurt; the line stopped for 2.5 hours.',
                'process' => 'Pressing', 'asset_ref' => 'PRESS-03', 'custom_fields' => ['line' => 'Press line 3', 'shift' => 'C', 'downtime_hours' => '2.5'],
                'impact' => ['impact_type' => 'actual', 'formula_key' => 'downtime', 'inputs' => ['hours' => '2.5', 'cost_per_hour' => '4000', 'lost_output' => '300', 'margin_per_unit' => '12.5']],
            ], $inventory)));

            $this->at($today->copy()->subDays(14)->setTime(23, 30), fn () => $as($quality, fn () => $incidents->contain($two, 'Line isolated, hose area cordoned off, oil spill cleaned.', $quality)));
            $this->at($today->copy()->subDays(13)->setTime(9, 0), function () use ($as, $inventory, $quality, $investigator, $incidents, $workflow, $two) {
                $as($inventory, fn () => $workflow->transition($two, 'submitted', null, $inventory));
                $as($quality, fn () => $workflow->transition($two->fresh(), 'triage', null, $quality));
                $as($quality, fn () => $incidents->assign($two->fresh(), $investigator->id, $quality->id, $quality));
                $as($investigator, fn () => $workflow->transition($two->fresh(), 'investigation', null, $investigator));
            });
            $action = $this->at($today->copy()->subDays(11)->setTime(15, 0), function () use ($as, $investigator, $incidents, $workflow, $capa, $two) {
                $rca = $as($investigator, fn () => $incidents->recordRootCause($two->fresh(), [
                    'method' => 'five_whys', 'whys' => ['The press stopped', 'The hydraulic hose burst', 'The hose was cracked', 'It was two years past its replacement date', 'Hoses are not on the maintenance schedule'],
                    'root_cause' => 'Hydraulic hoses are not on the preventive maintenance schedule', 'findings' => 'All presses checked: two more hoses past their date.',
                ], $investigator));
                $as($investigator, fn () => $workflow->transition($two->fresh(), 'action_required', null, $investigator));

                return $as($investigator, fn () => $capa->create($two->fresh(), [
                    'action_type' => 'preventive', 'title' => 'Put every press hose on a two-year replacement schedule', 'owner_id' => (string) $investigator->id,
                    'due_date' => now()->addDays(7)->toDateString(), 'expected_benefit_amount' => '40000', 'baseline_value' => '13750', 'root_cause_analysis_id' => (string) $rca->id,
                ], $investigator));
            });
            $this->at($today->copy()->subDays(6)->setTime(12, 0), function () use ($as, $investigator, $quality, $workflow, $capa, $calc, $two, $action) {
                $as($investigator, fn () => $capa->start($action, $investigator));
                $as($investigator, fn () => $capa->complete($action->fresh(), 'All 14 press hoses tagged with dates and added to the CMMS schedule; two replaced.', $investigator));
                $as($investigator, fn () => $workflow->transition($two->fresh(), 'verification', null, $investigator));
                $as($quality, fn () => $calc->validateLine($two->impactLines()->firstOrFail(), $quality));
            });
            $this->at($today->copy()->subDays(1)->setTime(16, 0), function () use ($as, $quality, $workflow, $capa, $two, $action) {
                $as($quality, fn () => $capa->verify($action->fresh(), '13750', 'No hose failure since; the two replaced hoses were cracked.', $quality));
                $as($quality, fn () => $workflow->transition($two->fresh(), 'closed', null, $quality));
            });

            // 3. a warranty exposure, still a draft
            $this->at($today->copy()->subDays(2)->setTime(14, 0), fn () => $as($quality, fn () => $incidents->create([
                'industry_template_id' => (string) $auto->id, 'loss_category_id' => $category('warranty_claim'), 'location_id' => (string) $locations['AHD']->id,
                'occurred_at' => $today->copy()->subDays(2)->setTime(12, 0)->format('Y-m-d H:i'), 'severity' => 'medium', 'title' => 'Dealer reports loose brackets on 3 vehicles',
                'description' => 'A dealer in Surat reports loose mounting brackets; claims expected.', 'custom_fields' => ['line' => 'Assembly 2'],
                'impact' => ['impact_type' => 'exposure', 'formula_key' => 'warranty', 'inputs' => ['claims' => '3', 'average_claim_cost' => '15000']],
            ], $quality)));

            Auth::forgetUser();
        });
    }

    /**
     * Cost centres and this month's budgets, made through the real service and approval: the press shop's line-stoppage budget is set below the
     * losses already reported (so budget against actual shows one over), the paint shop's is comfortable. The demo incidents are charged to them.
     * Fictitious figures. Runs after the loss sample.
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleFinance(Company $company, array $locations): void
    {
        if (CostCenter::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $accounts = User::query()->where('email', 'accounts@formezy.test')->firstOrFail();
        $controller = User::query()->where('email', 'finance@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($accounts, $controller, $locations) {
            Auth::setUser($accounts);
            $plant = CostCenter::query()->create(['code' => 'AHD-PLANT', 'name' => 'Ahmedabad plant', 'location_id' => $locations['AHD']->id]);
            $press = CostCenter::query()->create(['code' => 'AHD-PRESS', 'name' => 'Press shop', 'parent_id' => $plant->id, 'location_id' => $locations['AHD']->id]);
            $store = CostCenter::query()->create(['code' => 'AHD-STORES', 'name' => 'Stores', 'parent_id' => $plant->id, 'location_id' => $locations['AHD']->id]);

            $stoppage = LossCategory::query()->where('code', 'line_stoppage')->whereHas('template', fn ($q) => $q->where('code', 'automotive'))->first();
            $defect = LossCategory::query()->where('code', 'component_defect')->whereHas('template', fn ($q) => $q->where('code', 'automotive'))->first();
            $month = now()->format('Y-m');
            $budgets = app(BudgetService::class);
            $approvals = app(ApprovalService::class);

            foreach ([[$press, $stoppage, '8000'], [$press, null, '30000'], [$store, $defect, '5000']] as [$centre, $category, $amount]) {
                Auth::setUser($accounts);
                $budget = $budgets->save(['cost_center_id' => (string) $centre->id, 'loss_category_id' => $category === null ? '' : (string) $category->id, 'month' => $month, 'amount' => $amount], $accounts);
                $approvals->submit($budget, $accounts);
                Auth::setUser($controller);
                $approvals->approve($budget->fresh(), $controller);
            }

            // the demo losses are charged to a cost centre: the stoppage to the press shop, the rusted bolts to the stores
            LossIncident::query()->where('title', 'like', 'Press line 3 stopped%')->update(['cost_center_id' => $press->id]);
            LossIncident::query()->where('title', 'like', 'Rusted bolts%')->update(['cost_center_id' => $store->id]);

            Auth::forgetUser();
        });
    }

    /**
     * Price lists and rates in every state — expired, in force, upcoming, awaiting approval, rejected, draft, ended
     * early — made through the real maintenance service and approval engine: Accounts enters them, the Approver
     * signs them off. Dates are relative to today so the demo always shows each state. (Fictitious prices.)
     *
     * @param  array<string, Location>  $locations
     */
    private function sampleRates(Company $company, array $locations): void
    {
        if (Rate::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $accounts = User::query()->where('email', 'accounts@formezy.test')->firstOrFail();
        $approver = User::query()->where('email', 'approver@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($company, $accounts, $approver, $locations) {
            $maintenance = app(RateMaintenance::class);
            $approvals = app(ApprovalService::class);

            foreach ([['DEALER', 'Dealer price list'], ['PROJECT', 'Project price list']] as [$code, $name]) {
                (new PriceList(['code' => $code, 'name' => $name]))->forceFill(['company_id' => $company->id])->save();
            }

            $item = fn (string $name) => (string) Item::query()->where('name', $name)->value('id');
            $unit = fn (string $code) => (string) Unit::query()->where('code', $code)->value('id');
            $list = fn (?string $code) => $code === null ? '' : (string) PriceList::query()->where('code', $code)->value('id');
            $month = now()->startOfMonth();
            $day = fn (CarbonInterface $d) => $d->toDateString();

            $rates = $this->sampleRateRows($month);

            foreach ($rates as [$name, $branch, $unitCode, $listCode, $price, $from, $to, $upTo, $comment]) {
                $rate = $maintenance->save(null, ['rate' => [
                    'item_id' => $item($name), 'location_id' => (string) $locations[$branch]->id, 'unit_id' => $unit($unitCode),
                    'price_list_id' => $list($listCode), 'rate' => $price, 'effective_from' => $from, 'effective_to' => $to ?? '', 'remarks' => '',
                ]], $accounts);

                if ($upTo === 'draft') {
                    continue;
                }

                $approvals->submit($rate, $accounts);

                if ($upTo === 'submitted') {
                    continue;
                }

                if ($upTo === 'rejected') {
                    $approvals->reject($rate->fresh(), $approver, (string) $comment);

                    continue;
                }

                $approvals->approve($rate->fresh(), $approver, 'Checked against the current price circular');

                if ($upTo === 'ended') {
                    $maintenance->endDate($rate->fresh(), $day(now()->addMonth()->endOfMonth()), (string) $comment, $accounts);
                }
            }
        });
    }

    /**
     * What to create, one row each: item, branch, unit, price list code, rate, from, to, how far to take it
     * (draft | submitted | rejected | approved | ended), and a comment for the rejection or the early end.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string, 5: string, 6: string|null, 7: string, 8: string|null}>
     */
    private function sampleRateRows(CarbonInterface $month): array
    {
        $day = fn (CarbonInterface $d) => $d->toDateString();

        return [
            ['MS Hex Bolt M10 x 50', 'AHD', 'NOS', null, '4.50', $day($month->copy()->subMonths(6)), $day($month->copy()->subDay()), 'approved', null],
            ['MS Hex Bolt M10 x 50', 'AHD', 'NOS', null, '5.25', $day($month), null, 'approved', null],
            ['MS Hex Bolt M10 x 50', 'AHD', 'NOS', 'DEALER', '4.80', $day($month), null, 'approved', null],
            ['MS Hex Bolt M10 x 50', 'AHD', 'BOX', null, '500.00', $day($month), null, 'approved', null],
            ['MS Hex Bolt M10 x 50', 'MUM', 'NOS', null, '5.40', $day($month), null, 'approved', null],
            ['MS Hex Bolt M10 x 50', 'MUM', 'BOX', null, '520.00', $day($month), null, 'rejected', 'Mumbai is priced the same as Ahmedabad for boxed bolts; please confirm the freight difference first.'],
            ['Mild Steel Sheet 2 mm', 'AHD', 'KG', null, '68.50', $day($month), $day($month->copy()->addMonths(2)->subDay()), 'approved', null],
            ['Mild Steel Sheet 2 mm', 'AHD', 'KG', null, '71.0000', $day($month->copy()->addMonths(2)), null, 'approved', null],
            ['Mild Steel Sheet 2 mm', 'AHD', 'TON', null, '68000.00', $day($month), null, 'submitted', null],
            ['HDPE Granules', 'AHD', 'KG', null, '96.00', $day($month), null, 'approved', null],
            ['HDPE Granules', 'SRT', 'KG', null, '95.00', $day($month), null, 'ended', 'Supplier contract ends; new price list expected'],
            ['HDPE Granules', 'SRT', 'BAG', null, '2300.00', $day($month->copy()->addDays(7)), null, 'draft', null],
        ];
    }

    /**
     * One ledger of each type in every approval state, created through the same service and workflow the app uses
     * (fictitious parties; GSTINs carry valid check characters; the bank account number is a made-up one).
     */
    private function sampleLedgers(Company $company): void
    {
        if (Ledger::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;   // already seeded
        }

        $accounts = User::query()->where('email', 'accounts@formezy.test')->firstOrFail();
        $purchase = User::query()->where('email', 'purchase@formezy.test')->firstOrFail();
        $approver = User::query()->where('email', 'approver@formezy.test')->firstOrFail();

        app(TenantContext::class)->withCompany($company->id, function () use ($accounts, $purchase, $approver) {
            $service = app(LedgerService::class);
            $approvals = app(ApprovalService::class);

            $gujarat = State::query()->where('gst_code', '24')->firstOrFail();
            $ahmedabad = District::query()->where('state_id', $gujarat->id)->where('name', 'Ahmedabad')->firstOrFail();   // loaded by sampleGeography()
            $groupId = fn (string $code) => (string) AccountGroup::query()->where('code', $code)->value('id');
            $gstin = function (string $pan) {
                $first14 = '24'.$pan.'1Z';

                return $first14.Gstin::checkCharacter($first14);
            };

            $address = fn (string $line, string $label = 'Head office') => [[
                'id' => '', 'label' => $label, 'is_billing' => true, 'is_shipping' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
                'line1' => $line, 'line2' => '', 'city' => 'Ahmedabad', 'state_id' => (string) $gujarat->id, 'district_id' => (string) $ahmedabad->id,
                'pin_code' => '380001', 'gstin' => '', 'phone' => '',
            ]];
            $contact = fn (string $name, string $mobile) => [[
                'id' => '', 'name' => $name, 'designation' => 'Accounts', 'phone' => '', 'mobile' => $mobile, 'email' => '', 'is_primary' => true,
            ]];

            $make = function (LedgerType $type, User $by, array $ledger, array $extra = []) use ($service, $groupId) {
                $input = [
                    'ledger' => [
                        'code' => '', 'legal_name' => '', 'customer_kind' => '', 'gstin' => '', 'pan' => '', 'email' => '', 'phone' => '', 'credit_days' => '',
                        'credit_limit' => '', 'lead_time_days' => '', 'payment_preferences' => [], 'status' => 'active', 'remarks' => '',
                        ...$ledger,
                        'account_group_id' => $groupId($ledger['group']),
                    ],
                    'addresses' => [], 'contacts' => [], 'bank' => null,
                    ...$extra,
                ];
                unset($input['ledger']['group']);

                return $service->save($type, $input, null, $by);
            };

            $approve = function (Ledger $ledger, User $submitter, User $decider) use ($approvals) {
                $approvals->submit($ledger, $submitter);
                $approvals->approve($ledger->fresh(), $decider, 'Verified against the GST certificate');
            };

            // Customers
            $shree = $make(LedgerType::Customer, $accounts, [
                'group' => '1160', 'name' => 'Shree Ganesh Engineering Pvt Ltd', 'customer_kind' => 'b2b', 'gstin' => $gstin('AAACS1234F'), 'pan' => 'AAACS1234F',
                'credit_days' => '30', 'credit_limit' => '500000', 'payment_preferences' => ['NEFT', 'UPI'],
            ], ['addresses' => $address('12 Ashram Road'), 'contacts' => $contact('Meera Shah', '9876543210')]);
            $approve($shree, $accounts, $approver);

            $walkIn = $make(LedgerType::Customer, $accounts, ['group' => '1160', 'name' => 'Walk-in Retail', 'customer_kind' => 'b2c', 'payment_preferences' => ['CASH', 'UPI']],
                ['addresses' => $address('Counter sale'), 'contacts' => $contact('Front desk', '9812345678')]);
            $approve($walkIn, $accounts, $approver);

            $om = $make(LedgerType::Customer, $accounts, ['group' => '1160', 'name' => 'Om Fabricators', 'customer_kind' => 'b2b', 'gstin' => $gstin('AAACO4321K'), 'credit_days' => '45', 'credit_limit' => '200000'],
                ['addresses' => $address('Plot 7, Vatva GIDC'), 'contacts' => $contact('Rakesh Patel', '9898012345')]);
            $approvals->submit($om, $accounts);   // waiting for the approver

            $rejected = $make(LedgerType::Customer, $accounts, ['group' => '1160', 'name' => 'Sunrise Traders', 'customer_kind' => 'b2c'],
                ['addresses' => $address('Station Road'), 'contacts' => $contact('Anil', '9825011111')]);
            $approvals->submit($rejected, $accounts);
            $approvals->reject($rejected->fresh(), $approver, 'Please add a landline and the owner\'s PAN before this can be approved.');

            // Suppliers: onboarded by Purchase, approved by Accounts
            $steel = $make(LedgerType::Supplier, $purchase, [
                'group' => '2230', 'name' => 'Ahmedabad Steel Traders', 'gstin' => $gstin('AAACA7788P'), 'pan' => 'AAACA7788P',
                'credit_days' => '45', 'lead_time_days' => '7', 'payment_preferences' => ['NEFT', 'CHEQUE'],
            ], ['addresses' => $address('Naroda GIDC'), 'contacts' => $contact('Kiran Desai', '9822012345')]);
            $approve($steel, $purchase, $accounts);

            $make(LedgerType::Supplier, $purchase, ['group' => '2230', 'name' => 'Polymer Supplies Pvt Ltd', 'lead_time_days' => '14', 'credit_days' => '30'],
                ['addresses' => $address('Odhav Industrial Estate'), 'contacts' => $contact('Sanjay', '9879012345')]);   // still a draft

            // Bank, cash, expense, general (the bank account number is made up)
            $bank = $make(LedgerType::Bank, $accounts, ['group' => '1110', 'name' => 'HDFC Bank – Current A/c'], ['bank' => [
                'bank_name' => 'HDFC Bank', 'branch' => 'Navrangpura', 'account_holder' => 'FormEzy Demo Industries Pvt Ltd', 'account_type' => 'current',
                'account_number' => '50100000000001', 'ifsc' => 'HDFC0000001',
            ]]);
            $approve($bank, $accounts, $approver);

            foreach ([
                [LedgerType::Cash, '1120', 'Cash in Hand'],
                [LedgerType::Expense, '4200', 'Freight Outward'],
                [LedgerType::General, '4200', 'Round Off'],
            ] as [$type, $group, $name]) {
                $approve($make($type, $accounts, ['group' => $group, 'name' => $name]), $accounts, $approver);
            }
        });
    }

    /** Departments, designations and item groups (idempotent: matched by code within the company). */
    private function sampleMasters(Company $company): void
    {
        $make = function (string $class, array $attributes, array $forced = []) use ($company) {
            $existing = $class::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $attributes['code'])->first();

            if ($existing) {
                return $existing;
            }

            $model = new $class($attributes);
            $model->forceFill(['company_id' => $company->id, ...$forced])->save();

            return $model;
        };

        foreach ([['PUR', 'Purchase'], ['INV', 'Inventory'], ['ACC', 'Accounts'], ['OPS', 'Operations'], ['SAL', 'Sales'], ['MKT', 'Marketing'], ['HR', 'Human Resources']] as [$code, $name]) {
            $make(Department::class, ['code' => $code, 'name' => $name]);
        }

        foreach ([['DIR', 'Director'], ['MGR', 'Manager'], ['EXE', 'Executive'], ['BDE', 'Business Development Executive'], ['TLC', 'Tele-caller']] as [$code, $name]) {
            $make(Designation::class, ['code' => $code, 'name' => $name]);
        }

        $rawMaterial = $make(ItemGroup::class, ['code' => 'RM', 'name' => 'Raw Material']);
        $make(ItemGroup::class, ['code' => 'RM-STL', 'name' => 'Steel', 'parent_id' => $rawMaterial->id]);
        $make(ItemGroup::class, ['code' => 'RM-PLY', 'name' => 'Polymers', 'parent_id' => $rawMaterial->id]);
        $make(ItemGroup::class, ['code' => 'FG', 'name' => 'Finished Goods']);
        $make(ItemGroup::class, ['code' => 'CON', 'name' => 'Consumables']);
        $make(ItemGroup::class, ['code' => 'PKG', 'name' => 'Packing Material']);
    }

    /**
     * A handful of districts, blocks and PIN codes, loaded through the same importer Tent Studio's file goes through,
     * so the demo exercises it. Sample data only: the authoritative list comes from Tent Studio.
     */
    private function sampleGeography(Company $company, bool $gujarat): void
    {
        $rows = $gujarat
            ? [
                ['Gujarat', 'Ahmedabad', 'Daskroi', '380001', 'Khanpur'],
                ['Gujarat', 'Ahmedabad', 'Daskroi', '380015', 'Ambawadi'],
                ['Gujarat', 'Ahmedabad', 'Sanand', '382110', 'Sanand'],
                ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],
                ['Gujarat', 'Surat', 'Olpad', '394540', ''],
                ['Maharashtra', 'Pune', 'Haveli', '411001', 'Pune City'],
            ]
            : [
                ['Maharashtra', 'Pune', 'Haveli', '411001', 'Pune City'],
                ['Maharashtra', 'Pune', 'Mulshi', '412108', ''],
            ];

        $session = (new GeographyImporter)->begin($company->id);
        $keyed = [];
        foreach ($rows as $index => [$state, $district, $block, $pin, $locality]) {
            $keyed[$index + 2] = ['state' => $state, 'district' => $district, 'block' => $block, 'pin_code' => $pin, 'locality' => $locality];
        }

        $session->prepareChunk($keyed);

        activity()->withoutLogs(function () use ($session, $keyed) {
            foreach ($keyed as $number => $cells) {
                $session->process($number, $cells, true, null);
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    private function company(string $code, string $name, array $attributes): Company
    {
        return Company::withoutGlobalScopes()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'legal_name' => $name, 'status' => 'active', 'base_currency' => 'INR', 'financial_year_start_month' => 4, ...$attributes],
        );
    }

    private function location(Company $company, string $code, string $name, string $city, string $state, string $stateCode, string $pin, float $lat, float $lng): Location
    {
        $existing = Location::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->first();

        if ($existing) {
            return $existing;
        }

        // company_id is not mass-assignable (a tenant hazard), so it is set explicitly.
        $location = new Location([
            'code' => $code, 'name' => $name, 'city' => $city, 'state' => $state, 'state_code' => $stateCode,
            'pin_code' => $pin, 'latitude' => $lat, 'longitude' => $lng, 'status' => 'active',
        ]);
        $location->forceFill(['company_id' => $company->id])->save();

        return $location;
    }
}
