<?php

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Masters\Actions\SeedCompanyDefaults;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Masters\Services\RateMaintenance;
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
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\TestDocument;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

// Real multi-process concurrency tests commit to the database, so they must not run inside a wrapping transaction.
pest()->extend(TestCase::class)->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
| Build fixtures *before* calling actingAs(): once a user is authenticated every query is
| tenant-scoped, exactly as in production. Roles and permissions are seeded once per run.
*/

function makeCompany(string $code = 'ACME', array $attributes = []): Company
{
    $company = new Company(['code' => $code, 'name' => $attributes['name'] ?? "{$code} Industries", 'status' => 'active', ...$attributes]);
    $company->save();

    return $company;
}

function makeLocation(Company $company, string $code = 'AHD', array $attributes = []): Location
{
    $location = new Location(['code' => $code, 'name' => "{$code} Branch", 'status' => 'active', ...$attributes]);
    $location->forceFill(['company_id' => $company->id])->save();

    return $location;
}

/**
 * @param  list<string>  $roles
 * @param  list<Location>|null  $locations  null = access to every branch of each company
 * @param  list<Company>  $companies
 */
function makeUser(array $roles = [], array $companies = [], ?array $locations = null, array $attributes = []): User
{
    $user = User::factory()->create([
        'default_company_id' => ($companies[0] ?? null)?->id,
        ...$attributes,
    ]);

    $user->syncRoles($roles);

    foreach ($companies as $company) {
        DB::table('company_user')->insert([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'all_locations' => $locations === null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach ($locations ?? [] as $location) {
        DB::table('location_user')->insert(['location_id' => $location->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user->fresh();
}

function makeDocument(Company $company, ?Location $location = null, array $attributes = []): TestDocument
{
    $document = new TestDocument(['code' => $attributes['code'] ?? 'D'.random_int(1000, 99999), 'name' => 'Sample', ...$attributes]);
    $document->forceFill(['company_id' => $company->id, 'location_id' => $location?->id])->save();

    return $document;
}

/*
|--------------------------------------------------------------------------
| Spreadsheet uploads
|--------------------------------------------------------------------------
*/

/**
 * A real .xlsx (sheet "Data") or .csv upload built from headings and rows. Numeric-looking strings become numbers in
 * the xlsx, exactly as Excel stores a typed PIN code, so the reader's number handling is exercised too.
 *
 * @param  list<string>  $headings
 * @param  list<list<string|int|null>>  $rows
 */
function spreadsheetUpload(array $headings, array $rows, string $extension = 'xlsx', string $name = 'geography'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fz');

    if ($extension === 'csv') {
        $handle = fopen($path, 'w');
        fputcsv($handle, $headings, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fclose($handle);
    } else {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->fromArray([$headings, ...$rows], null, 'A1', true);
        (new Xlsx($book))->save($path);
    }

    $upload = UploadedFile::fake()->createWithContent("{$name}.{$extension}", (string) file_get_contents($path));
    unlink($path);

    return $upload;
}

/*
|--------------------------------------------------------------------------
| Masters fixtures
|--------------------------------------------------------------------------
*/

/** Give a company its standard states, units and chart of accounts. */
function seedDefaults(Company $company): void
{
    app(SeedCompanyDefaults::class)->run($company);
}

/**
 * A small geography for a company (call seedDefaults() first for the states):
 *   Gujarat: Ahmedabad (blocks Daskroi, Sanand) and Surat (block Choryasi);  Maharashtra: Pune (block Haveli)
 * PIN 380001 -> Ahmedabad/Daskroi (unique); 382110 -> Ahmedabad, two blocks (ambiguous block); 395003 -> Surat/Choryasi;
 * 411001 -> Pune/Haveli; 999999-style pins are deliberately absent.
 *
 * @return array<string, Model>
 */
function seedGeography(Company $company): array
{
    $make = function (string $class, array $attributes, array $forced = []) use ($company) {
        $model = new $class($attributes);
        $model->forceFill(['company_id' => $company->id, ...$forced])->save();

        return $model;
    };

    $gj = State::withoutGlobalScopes()->where('company_id', $company->id)->where('gst_code', '24')->firstOrFail();
    $mh = State::withoutGlobalScopes()->where('company_id', $company->id)->where('gst_code', '27')->firstOrFail();

    $ahmedabad = $make(District::class, ['state_id' => $gj->id, 'name' => 'Ahmedabad']);
    $surat = $make(District::class, ['state_id' => $gj->id, 'name' => 'Surat']);
    $pune = $make(District::class, ['state_id' => $mh->id, 'name' => 'Pune']);

    $daskroi = $make(Block::class, ['district_id' => $ahmedabad->id, 'name' => 'Daskroi']);
    $sanand = $make(Block::class, ['district_id' => $ahmedabad->id, 'name' => 'Sanand']);
    $choryasi = $make(Block::class, ['district_id' => $surat->id, 'name' => 'Choryasi']);
    $haveli = $make(Block::class, ['district_id' => $pune->id, 'name' => 'Haveli']);

    $pin = fn (string $pin, $district, $block, string $area = '') => $make(PinCode::class, [
        'pin_code' => $pin, 'state_id' => $district->state_id, 'district_id' => $district->id, 'block_id' => $block?->id, 'area' => $area,
    ]);

    $pin('380001', $ahmedabad, $daskroi, 'Khanpur');
    $pin('382110', $ahmedabad, $daskroi, 'Bavla');
    $pin('382110', $ahmedabad, $sanand, 'Sanand Road');
    $pin('395003', $surat, $choryasi, 'Surat City');
    $pin('411001', $pune, $haveli, 'Pune City');

    return compact('gj', 'mh', 'ahmedabad', 'surat', 'pune', 'daskroi', 'sanand', 'choryasi', 'haveli');
}

/*
|--------------------------------------------------------------------------
| Ledger fixtures
|--------------------------------------------------------------------------
*/

/** Run a service call inside the company, the way a request would (a job, or a user working in it). */
function inCompany($company, callable $callback)
{
    return app(TenantContext::class)->withCompany($company->id, $callback);
}

/** A GSTIN with a correct check character, for the given GST state code and PAN. */
function gstinFor(string $stateCode, string $pan, string $entity = '1'): string
{
    $first14 = $stateCode.$pan.$entity.'Z';

    return $first14.Gstin::checkCharacter($first14);
}

/** The id of one of a company's standard account groups by code (1160 = Sundry Debtors, 2230 = Sundry Creditors...). */
function groupId(Company $company, string $code): int
{
    return (int) AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)
        ->where('company_id', $company->id)->where('code', $code)->value('id');
}

/** The id of a company's state by GST code (24 = Gujarat, 27 = Maharashtra). */
function stateId(Company $company, string $gstCode = '24'): int
{
    return (int) State::withoutGlobalScopes(Tenancy::SCOPES)
        ->where('company_id', $company->id)->where('gst_code', $gstCode)->value('id');
}

/**
 * Valid input for LedgerService (and the ledger form) for a ledger type. Pass overrides: `ledger` is merged into the
 * ledger fields; `addresses`, `contacts` and `bank` replace the defaults wholesale.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ledgerInput(Company $company, string $type = 'customer', array $overrides = []): array
{
    $groups = ['customer' => '1160', 'supplier' => '2230', 'bank' => '1110', 'cash' => '1120', 'expense' => '4200', 'general' => '1100'];
    $party = in_array($type, ['customer', 'supplier'], true);

    $ledger = [
        'code' => '', 'name' => 'Shree Ganesh '.ucfirst($type), 'legal_name' => '', 'account_group_id' => (string) groupId($company, $groups[$type]),
        'customer_kind' => $type === 'customer' ? 'b2c' : '', 'gstin' => '', 'pan' => '', 'email' => '', 'phone' => '',
        'credit_days' => '', 'credit_limit' => '', 'lead_time_days' => '', 'payment_preferences' => [], 'status' => 'active', 'remarks' => '',
    ];

    $address = [
        'id' => '', 'label' => 'Head office', 'is_billing' => true, 'is_shipping' => true, 'is_default_billing' => true, 'is_default_shipping' => true,
        'line1' => '12 Ashram Road', 'line2' => '', 'city' => 'Ahmedabad', 'state_id' => (string) stateId($company), 'district_id' => '',
        'pin_code' => '380009', 'gstin' => '', 'phone' => '',
    ];

    $contact = ['id' => '', 'name' => 'Meera Shah', 'designation' => 'Accounts', 'phone' => '', 'mobile' => '9876543210', 'email' => 'meera@example.test', 'is_primary' => true];

    $bank = ['bank_name' => 'HDFC Bank', 'branch' => 'Navrangpura', 'account_holder' => '', 'account_type' => 'current', 'account_number' => '50100123456789', 'ifsc' => 'HDFC0001234'];

    return [
        'ledger' => [...$ledger, ...($overrides['ledger'] ?? [])],
        'addresses' => $overrides['addresses'] ?? ($party ? [$address] : []),
        'contacts' => $overrides['contacts'] ?? ($party ? [$contact] : []),
        'bank' => array_key_exists('bank', $overrides) ? $overrides['bank'] : ($type === 'bank' ? $bank : null),
    ];
}

/**
 * Create a ledger through the real service, inside the company, as $by. With $approve it is also submitted by $by and
 * approved by $approver, so it is a usable ledger.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeLedger(Company $company, User $by, string $type = 'customer', array $overrides = [], bool $approve = false, ?User $approver = null): Ledger
{
    return app(TenantContext::class)->withCompany($company->id, function () use ($company, $by, $type, $overrides, $approve, $approver) {
        $ledger = app(LedgerService::class)
            ->save(LedgerType::from($type), ledgerInput($company, $type, $overrides), null, $by);

        if ($approve) {
            $approver ??= makeUser(['Approver'], [$company]);
            app(ApprovalService::class)->submit($ledger, $by);
            app(ApprovalService::class)->approve($ledger->fresh(), $approver);
        }

        return $ledger->fresh();
    });
}

/*
|--------------------------------------------------------------------------
| Item fixtures
|--------------------------------------------------------------------------
*/

/** The id of one of a company's item groups, creating it the first time. */
function itemGroupId(Company $company, string $code = 'RM', string $name = 'Raw Material'): int
{
    $group = ItemGroup::withoutGlobalScopes(Tenancy::SCOPES)
        ->where('company_id', $company->id)->where('code', $code)->first();

    if ($group === null) {
        $group = (new ItemGroup(['code' => $code, 'name' => $name]))->forceFill(['company_id' => $company->id]);
        $group->save();
    }

    return $group->id;
}

/** The id of one of a company's standard units by code (NOS, BOX, KG, GM, MTR...). */
function unitId(Company $company, string $code): int
{
    return (int) Unit::withoutGlobalScopes(Tenancy::SCOPES)
        ->where('company_id', $company->id)->where('code', $code)->value('id');
}

/**
 * Valid stage-1 (Purchase) input. `item` is merged into the item fields; `uoms` replaces the default "1 Box = 100 Nos".
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function itemInput(Company $company, array $overrides = []): array
{
    return [
        'item' => [
            'name' => 'MS Hex Bolt M10', 'description' => '', 'item_group_id' => (string) itemGroupId($company), 'base_unit_id' => (string) unitId($company, 'NOS'),
            'po_tolerance_percent' => '5', 'hsn_code' => '7318', 'gst_rate' => '18',
            ...($overrides['item'] ?? []),
        ],
        'uoms' => $overrides['uoms'] ?? [['id' => '', 'unit_id' => (string) unitId($company, 'BOX'), 'factor' => '100']],
    ];
}

/**
 * Valid stage-2 (Inventory) input. The default code is derived from `$name` so several items do not clash.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inventoryInput(string $name = 'MS Hex Bolt M10', array $overrides = []): array
{
    return [
        'inventory' => ['code' => 'IT-'.strtoupper(substr(md5($name), 0, 6)), ...($overrides['inventory'] ?? [])],
        'aliases' => $overrides['aliases'] ?? [],
        'locations' => $overrides['locations'] ?? [],
    ];
}

/**
 * Take an item through the real workflow as far as `$upTo` says: 'draft' (Purchase), 'inventory', 'submitted'
 * (waiting for approval) or 'approved'. `overrides` accepts the keys of itemInput() and inventoryInput().
 *
 * @param  array<string, mixed>  $overrides
 */
function makeItem(Company $company, User $purchase, array $overrides = [], string $upTo = 'draft', ?User $inventory = null, ?User $approver = null): Item
{
    return app(TenantContext::class)->withCompany($company->id, function () use ($company, $purchase, $overrides, $upTo, $inventory, $approver) {
        $service = app(ItemService::class);
        $workflow = app(ItemWorkflow::class);

        $item = $service->savePurchaseStage(null, itemInput($company, $overrides), $purchase);

        if ($upTo === 'draft') {
            return $item;
        }

        $workflow->sendToInventory($item, $purchase);
        $inventory ??= makeUser(['Inventory User'], [$company]);
        $service->saveInventoryStage($item->fresh(), inventoryInput($item->name, $overrides), $inventory);

        if ($upTo === 'inventory') {
            return $item->fresh();
        }

        $approver ??= makeUser(['Accounts User'], [$company]);   // the role items are routed to by default
        $workflow->submit($item->fresh(), $inventory);

        if ($upTo === 'approved') {
            $workflow->approve($item->fresh(), $approver);
        }

        return $item->fresh();
    });
}

/*
|--------------------------------------------------------------------------
| Rate fixtures
|--------------------------------------------------------------------------
*/

/** A price list of the company. */
function makePriceList(Company $company, string $code = 'DEALER', string $name = 'Dealer price list', string $status = 'active'): PriceList
{
    $list = (new PriceList(['code' => $code, 'name' => $name, 'status' => $status]))->forceFill(['company_id' => $company->id]);
    $list->save();

    return $list;
}

/**
 * Valid input for RateMaintenance (and the rate form) for an approved item: its base unit, at $location, 125.50 from
 * 1 Apr 2026 with no end. `$overrides` is merged into the rate fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rateInput(Item $item, Location $location, array $overrides = []): array
{
    return ['rate' => [
        'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'unit_id' => (string) $item->base_unit_id,
        'price_list_id' => '', 'rate' => '125.50', 'effective_from' => '2026-04-01', 'effective_to' => '', 'remarks' => '',
        ...$overrides,
    ]];
}

/**
 * Create a rate through the real service, inside the company, as $by, and take it as far as `$upTo` says: 'draft',
 * 'submitted', 'approved' (decided by $approver, a fresh Approver by default) or 'rejected'.
 *
 * @param  array<string, mixed>  $rate  merged into the rate fields
 */
function makeRate(Company $company, User $by, Item $item, Location $location, array $rate = [], string $upTo = 'draft', ?User $approver = null): Rate
{
    return app(TenantContext::class)->withCompany($company->id, function () use ($company, $by, $item, $location, $rate, $upTo, $approver) {
        $made = app(RateMaintenance::class)->save(null, rateInput($item, $location, $rate), $by);

        if ($upTo === 'draft') {
            return $made;
        }

        app(ApprovalService::class)->submit($made, $by);

        if ($upTo !== 'submitted') {
            $approver ??= makeUser(['Approver'], [$company]);
            $upTo === 'approved'
                ? app(ApprovalService::class)->approve($made->fresh(), $approver)
                : app(ApprovalService::class)->reject($made->fresh(), $approver, 'Please recheck the price.');
        }

        return $made->fresh();
    });
}

/*
|--------------------------------------------------------------------------
| Field-sales fixtures (teams, route plans)
|--------------------------------------------------------------------------
*/

/** The middle of Ahmedabad: the position the field-sales tests plan around. 0.001° of latitude is about 111 m. */
const HQ_LAT = 23.0225;

const HQ_LNG = 72.5714;

/**
 * A team run by $manager (who is also the actor), with $members on it from 1 Jan 2026.
 *
 * @param  list<User>  $members
 */
function makeTeam(Company $company, User $manager, array $members = [], string $code = 'FIELD', string $name = 'Field team'): SalesTeam
{
    return inCompany($company, function () use ($manager, $members, $code, $name) {
        $teams = app(SalesTeamService::class);
        $team = $teams->saveTeam(null, ['team' => ['code' => $code, 'name' => $name, 'manager_user_id' => (string) $manager->id, 'status' => 'active', 'remarks' => '']], $manager);

        foreach ($members as $member) {
            $teams->addMember($team, $member->id, '2026-01-01', $manager);
        }

        return $team->fresh();
    });
}

/**
 * One stop of a route plan: an area at Ahmedabad's centre by default (radius left to the plan and company).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stopInput(array $overrides = []): array
{
    return [
        'id' => '', 'stop_type' => 'area', 'label' => 'Naroda GIDC', 'block_id' => '', 'pin_code' => '', 'ledger_id' => '',
        'latitude' => (string) HQ_LAT, 'longitude' => (string) HQ_LNG, 'radius_m' => '', 'visit_from' => '', 'visit_to' => '',
        ...$overrides,
    ];
}

/**
 * Plan a day for $person at $location. `$plan` is merged into the plan fields; `$stops` replaces the one default stop.
 *
 * @param  array<string, mixed>  $plan
 * @param  list<array<string, mixed>>|null  $stops
 * @return array<string, mixed>
 */
function planInput(User $person, Location $location, array $plan = [], ?array $stops = null): array
{
    return [
        'plan' => ['user_id' => (string) $person->id, 'location_id' => (string) $location->id, 'plan_date' => now()->toDateString(), 'title' => '', 'geofence_radius_m' => '', 'remarks' => '', ...$plan],
        'stops' => $stops ?? [stopInput()],
    ];
}

/**
 * Plan a day through the real service as $by, and take it to `$upTo`: 'draft', 'published' or 'cancelled'.
 *
 * @param  array<string, mixed>  $plan
 * @param  list<array<string, mixed>>|null  $stops
 */
function makePlan(Company $company, User $by, User $person, Location $location, array $plan = [], ?array $stops = null, string $upTo = 'published'): RoutePlan
{
    return inCompany($company, function () use ($by, $person, $location, $plan, $stops, $upTo) {
        $service = app(RoutePlanService::class);
        $made = $service->save(null, planInput($person, $location, $plan, $stops), $by);

        if ($upTo === 'draft') {
            return $made;
        }

        $service->publish($made, $by);

        if ($upTo === 'cancelled') {
            $service->cancel($made->fresh(), 'The visit was called off', $by);
        }

        return $made->fresh();
    });
}

/*
|--------------------------------------------------------------------------
| Lead fixtures (call seedDefaults() first: the sources, outcomes and lost reasons come with the company)
|--------------------------------------------------------------------------
*/

/** The id of one of a company's lead sources by code (REFERENCE, WEBSITE, TELECALLER, EXCEL, FIELD...). */
function leadSourceId(Company $company, string $code = 'REFERENCE'): int
{
    return (int) LeadSource::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->where('code', $code)->value('id');
}

/** The id of one of a company's follow-up outcomes by code (INTERESTED, NOT_AVAILABLE, CALL_BACK_LATER, MEETING_FIXED...). */
function outcomeId(Company $company, string $code = 'INTERESTED'): int
{
    return (int) FollowupOutcome::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->where('code', $code)->value('id');
}

/** The id of one of a company's lost reasons by code (PRICE, COMPETITOR, NO_RESPONSE...). */
function lostReasonId(Company $company, string $code = 'PRICE'): int
{
    return (int) LostReason::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->where('code', $code)->value('id');
}

/** A mobile number no other fixture has used in this test run. */
function nextMobile(): string
{
    static $serial = 0;

    return '98'.str_pad((string) ++$serial, 8, '0', STR_PAD_LEFT);
}

/**
 * Valid input for LeadService (and the lead form): Shree Ganesh Traders, a reference, entered today at $location.
 * `$lead` is merged into the lead fields; `$interests` are the items of interest.
 *
 * @param  array<string, mixed>  $lead
 * @param  list<array<string, mixed>>  $interests
 * @return array<string, mixed>
 */
function leadInput(Company $company, Location $location, array $lead = [], array $interests = []): array
{
    return [
        'lead' => [
            'location_id' => (string) $location->id, 'lead_date' => now()->toDateString(), 'lead_source_id' => (string) leadSourceId($company),
            'name' => 'Shree Ganesh Traders', 'contact_person' => 'Meera Shah', 'mobile' => '9876543210', 'phone' => '', 'email' => '',
            'address_line1' => '', 'address_line2' => '', 'city' => '', 'state_id' => '', 'district_id' => '', 'block_id' => '', 'pin_code' => '',
            'requirement' => '', 'expected_value' => '', 'assigned_to' => '', 'remarks' => '', 'acknowledge_duplicate' => false,
            ...$lead,
        ],
        'interests' => $interests,
    ];
}

/**
 * Enter a lead through the real service as $by, at $location. Each lead gets its own mobile number unless one is given,
 * so fixtures do not trip the duplicate warning by accident. Someone without `lead.assign` becomes the owner; pass
 * `assigned_to` to give it to somebody else (or leave it '' for the pool) when $by may hand leads out.
 *
 * @param  array<string, mixed>  $lead
 * @param  list<array<string, mixed>>  $interests
 */
function makeLead(Company $company, User $by, Location $location, array $lead = [], array $interests = []): Lead
{
    return inCompany($company, fn () => app(LeadService::class)
        ->create(leadInput($company, $location, ['mobile' => nextMobile(), ...$lead], $interests), $by));
}

/*
|--------------------------------------------------------------------------
| Integration fixtures (IndiaMART, Just Dial)
|--------------------------------------------------------------------------
*/

/**
 * A provider connection, switched on and ready: leads go to $branch, are entered as $actor, and (unless `assigned_to` says
 * otherwise) wait in the pool. IndiaMART defaults to pulling, Just Dial to receiving pushes.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeConnection(Company $company, User $actor, Location $branch, string $provider = 'indiamart', array $overrides = []): IntegrationConnection
{
    return inCompany($company, function () use ($company, $actor, $branch, $provider, $overrides) {
        $connection = new IntegrationConnection;
        $connection->forceFill([
            'company_id' => $company->id, 'provider' => $provider, 'enabled' => true, 'mode' => $provider === 'indiamart' ? 'pull' : 'webhook',
            'public_key' => IntegrationConnection::newPublicKey(), 'default_location_id' => $branch->id, 'actor_user_id' => $actor->id,
            ...$overrides,
        ])->save();

        return $connection->refresh();
    });
}

/** What IndiaMART's Lead Manager sends for one enquiry (the documented fields), with `$over` laid on top. @param  array<string, mixed>  $over */
function indiaMartRecord(array $over = []): array
{
    return [
        'UNIQUE_QUERY_ID' => '2026101200001', 'QUERY_TYPE' => 'W', 'QUERY_TIME' => '2026-10-12 08:45:10',
        'SENDER_NAME' => 'Meera Shah', 'SENDER_MOBILE' => '+91-9876543210', 'SENDER_EMAIL' => 'Meera@Example.test',
        'SENDER_COMPANY' => 'Shree Ganesh Traders', 'SENDER_ADDRESS' => '12 Ashram Road', 'SENDER_CITY' => 'Ahmedabad',
        'SENDER_STATE' => 'Gujarat', 'SENDER_PINCODE' => '380001', 'SENDER_COUNTRY_ISO' => 'IN',
        'SUBJECT' => 'Requirement for hex bolts', 'QUERY_PRODUCT_NAME' => 'Hex Bolt M10', 'QUERY_MESSAGE' => 'Need 5000 pcs.\\nPlease quote.',
        ...$over,
    ];
}

/** What a Just Dial push looks like (the commonly received layout), with `$over` laid on top. @param  array<string, mixed>  $over */
function justDialRecord(array $over = []): array
{
    return [
        'leadid' => 'JD-77001', 'leadtype' => 'Enquiry', 'prefix' => 'Mr', 'name' => 'Ravi Patel', 'mobile' => '9825012345', 'phone' => '',
        'email' => 'ravi@example.test', 'date' => '2026-10-12', 'time' => '09:15:30', 'category' => 'Fasteners', 'area' => 'Naroda',
        'brancharea' => 'Ahmedabad', 'dncmobile' => '0', 'dncphone' => '0', 'company' => 'Patel Hardware', 'pincode' => '380001', 'parentid' => 'JD-P-1',
        ...$over,
    ];
}

/** What the website form on the company's page posts (our own documented layout), with `$over` laid on top. @param  array<string, mixed>  $over */
function websiteRecord(array $over = []): array
{
    return [
        'name' => 'Meera Shah', 'company' => 'Shree Ganesh Traders', 'mobile' => '98765 43210', 'email' => 'Meera@Example.test', 'city' => 'Ahmedabad',
        'pincode' => '380001', 'product' => 'Hex Bolt M10', 'message' => 'Need 5000 pcs, please quote.', 'form' => 'Contact form',
        'page_url' => 'https://www.acme.test/contact?utm_source=google&token=SECRET-IN-LINK#top', 'submission_id' => 'sub-8d3f1a2b',
        ...$over,
    ];
}

/** The body of IndiaMART's Pull API answer. @param  list<array<string, mixed>>  $records */
function indiaMartAnswer(array $records, int $code = 200): array
{
    return ['CODE' => $code, 'STATUS' => $code === 200 ? 'SUCCESS' : 'FAILURE', 'MESSAGE' => '', 'TOTAL_RECORDS' => (string) count($records), 'RESPONSE' => $records];
}

/*
|--------------------------------------------------------------------------
| API fixtures (/api/v1)
|--------------------------------------------------------------------------
*/

/** A device token for $user, as the app would hold it: read and write unless said otherwise. */
function apiToken(User $user, array $scopes = ['read', 'write']): string
{
    return $user->createToken('Test phone', $scopes, now()->addDay())->plainTextToken;
}

/**
 * Send one API request the way the app would: a bearer token ($as is a user — a fresh device token is issued — or a token
 * string), JSON, and for a GET the data as a query string. Afterwards it forgets who was signed in, so fixtures built next are
 * not quietly scoped to the last caller.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function apiCall(string $method, string $uri, array $data = [], User|string|null $as = null, array $headers = []): TestResponse
{
    $reset = function () {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        app(TenantContext::class)->flush();
    };

    $reset();

    $test = test()->flushHeaders();

    if ($as !== null) {
        $test = $test->withToken($as instanceof User ? apiToken($as) : $as);
    }

    if (strtoupper($method) === 'GET' && $data !== []) {
        $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query($data);
        $data = [];
    }

    $response = $test->json($method, $uri, $data, $headers);

    $reset();

    return $response;
}

/** A fresh Idempotency-Key header for a create. */
function idem(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

/**
 * Plan a follow-up on a lead through the real service as $by: a call tomorrow at 11:00 for the lead's owner by default.
 *
 * @param  array<string, mixed>  $plan
 */
function makeFollowup(Company $company, User $by, Lead $lead, array $plan = []): Followup
{
    return inCompany($company, fn () => app(FollowupService::class)->schedule($lead, ['plan' => [
        'scheduled_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i'), 'type' => 'call', 'assigned_to' => '', 'notes' => '',
        ...$plan,
    ]], $by));
}

/*
|--------------------------------------------------------------------------
| Inquiry and deal fixtures (call seedDefaults() first)
|--------------------------------------------------------------------------
*/

/** An approved, active customer ledger of the company, ready to be picked (made by Accounts, approved by an Approver). */
function makeCustomer(Company $company, string $name = 'Shree Ganesh Industries', array $ledger = []): Ledger
{
    return makeLedger($company, makeUser(['Accounts User'], [$company]), 'customer', ['ledger' => ['name' => $name, ...$ledger]], true);
}

/** An approved, active item of the company — sold in Nos or in Boxes of 100 — ready to be picked. */
function makeUsableItem(Company $company, string $name = 'MS Hex Bolt M10', array $item = []): Item
{
    return makeItem($company, makeUser(['Purchase User'], [$company]), ['item' => ['name' => $name, ...$item]], 'approved');
}

/**
 * One line of an inquiry or a deal for $item, in the item's own unit unless `unit_id` says otherwise: 10 at ₹125.50 by default.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function lineRow(Item $item, string $quantity = '10', string $rate = '125.50', array $extra = []): array
{
    return ['id' => '', 'lead_interest_id' => '', 'item_id' => (string) $item->id, 'description' => '', 'quantity' => $quantity, 'unit_id' => (string) $item->base_unit_id, 'expected_rate' => $rate, ...$extra];
}

/**
 * An inquiry from an existing customer, entered through the real service as $by at $location: the customer's name and address
 * come from the ledger. Without lines it still has one (10 of a bolt) unless `$lines` is passed.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $lead
 */
function makeInquiry(Company $company, User $by, Location $location, Ledger $customer, array $lines, array $lead = []): Lead
{
    return inCompany($company, fn () => app(LeadService::class)
        ->create(leadInput($company, $location, ['customer_ledger_id' => (string) $customer->id, 'mobile' => '', 'name' => '', ...$lead], $lines), $by));
}

/** Take a lead to Qualified through the real service (New → Qualified is one allowed move). */
function qualify(Company $company, Lead $lead, User $by): Lead
{
    return inCompany($company, fn () => app(LeadService::class)->changeStage($lead, LeadStage::Qualified, $by));
}

/**
 * Valid input for DealService::convert() for a lead: its own lines come across (a rate of ₹125.50 is filled in where the lead
 * priced nothing, and a line the lead only described needs `$items` to name its item), closing in 30 days. `$deal` is merged
 * into the deal fields; `$items`, when given, replaces the lines.
 *
 * @param  array<string, mixed>  $deal
 * @param  list<array<string, mixed>>|null  $items
 * @return array<string, mixed>
 */
function dealInput(Lead $lead, array $deal = [], ?array $items = null): array
{
    $rows = $items ?? array_map(fn (array $row) => [
        'id' => '', 'lead_interest_id' => (string) $row['lead_interest_id'], 'item_id' => (string) $row['item_id'], 'unit_id' => (string) $row['unit_id'],
        'quantity' => (string) ($row['quantity'] ?? '1'), 'expected_rate' => (string) ($row['expected_rate'] ?? '125.50'),
    ], app(DealService::class)->linesFromLead($lead));

    return [
        'deal' => ['customer_ledger_id' => '', 'expected_close_date' => now()->addDays(30)->toDateString(), 'probability' => '', 'remarks' => '', ...$deal],
        'items' => $rows,
    ];
}

/**
 * Convert a lead to a deal through the real service as $by (who must work the lead). A lead that is not Qualified yet is
 * qualified first by its owner.
 *
 * @param  array<string, mixed>  $deal
 * @param  list<array<string, mixed>>|null  $items
 */
function makeDeal(Company $company, User $by, Lead $lead, array $deal = [], ?array $items = null): Deal
{
    if ($lead->stage !== LeadStage::Qualified) {
        $lead = qualify($company, $lead, $by);
    }

    return inCompany($company, fn () => app(DealService::class)->convert($lead, dealInput($lead, $deal, $items), $by));
}

/*
|--------------------------------------------------------------------------
| Quotation fixtures
|--------------------------------------------------------------------------
*/

/** Send a document for approval as $by and approve it as $approver (somebody else: maker–checker is on). */
function approveDocument(Company $company, Model $document, User $by, User $approver): Model
{
    return inCompany($company, function () use ($document, $by, $approver) {
        app(ApprovalService::class)->submit($document, $by);
        app(ApprovalService::class)->approve($document->fresh(), $approver);

        return $document->fresh();
    });
}

/** An approved standard rate (125.50 a unit by default, from 1 Apr 2026) for an item at a branch, made by a fresh Accounts user. */
function makeStandardRate(Company $company, Item $item, Location $location, string $rate = '125.50', array $more = []): Rate
{
    return makeRate($company, makeUser(['Accounts User'], [$company]), $item, $location, ['rate' => $rate, ...$more], 'approved');
}

/**
 * Valid input for QuotationService: the header a new quotation starts with (from the deal or inquiry), supplied to Gujarat unless
 * said otherwise, and one row per line of the source at the quantity asked.
 *
 * @param  array<string, mixed>  $q  merged into the header
 * @param  list<array<string, mixed>>|null  $items  replaces the rows
 * @return array<string, mixed>
 */
function quoteInput(Deal|Lead $source, array $q = [], ?array $items = null): array
{
    $lead = $source instanceof Deal ? $source->lead()->firstOrFail() : $source;
    $deal = $source instanceof Deal ? $source : null;
    $gujarat = (int) State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $lead->company_id)->where('gst_code', '24')->value('id');

    $rows = $items ?? array_map(fn (array $row) => [
        'id' => '', 'deal_item_id' => (string) ($row['deal_item_id'] ?? ''), 'lead_interest_id' => (string) ($row['lead_interest_id'] ?? ''),
        'item_id' => (string) $row['item_id'], 'unit_id' => (string) $row['unit_id'], 'quantity' => (string) ($row['quantity'] ?? '1'),
        'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
    ], app(QuotationService::class)->startingLines($lead, $deal));

    return [
        'quotation' => [
            ...app(QuotationPricer::class)->defaults($lead, $deal),
            'place_of_supply_state_id' => (string) $gujarat, 'bill_line1' => '12 Industrial Estate', 'bill_city' => 'Ahmedabad', 'bill_pin_code' => '380001',
            'email' => 'buyer@example.com', 'reprice' => '', ...$q,
        ],
        'items' => $rows,
    ];
}

/**
 * Make a quotation through the real service and take it as far as `$upTo` says: 'draft', 'approved' (decided by $approver, a fresh
 * Approver by default) or 'sent' (recorded as handed over).
 *
 * @param  array<string, mixed>  $q
 * @param  list<array<string, mixed>>|null  $items
 */
function makeQuotation(Company $company, User $by, Deal|Lead $source, array $q = [], ?array $items = null, string $upTo = 'draft', ?User $approver = null): Quotation
{
    $made = inCompany($company, fn () => app(QuotationService::class)->create($source, quoteInput($source, $q, $items), $by));

    if ($upTo === 'draft') {
        return $made;
    }

    $made = approveDocument($company, $made, $by, $approver ?? makeUser(['Approver'], [$company]));

    return $upTo === 'approved' ? $made : inCompany($company, fn () => app(QuotationService::class)->send($made, ['channel' => 'manual'], $by));
}

/*
|--------------------------------------------------------------------------
| Sales order fixtures
|--------------------------------------------------------------------------
*/

/**
 * Valid input for SalesOrderService: the header a new order starts with (from the quotation or deal, else the customer), supplied to Gujarat
 * unless said otherwise, and one row per line of the source at the quantity asked.
 *
 * @param  array<string, mixed>  $o  merged into the header
 * @param  list<array<string, mixed>>|null  $items  replaces the rows
 * @return array<string, mixed>
 */
function orderInput(Ledger $customer, Quotation|Deal|null $source = null, array $o = [], ?array $items = null): array
{
    $quotation = $source instanceof Quotation ? $source : null;
    $deal = $source instanceof Deal ? $source : $quotation?->deal;
    $gujarat = (int) State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $customer->company_id)->where('gst_code', '24')->value('id');

    $rows = $items ?? array_map(fn (array $row) => [
        'id' => '', 'quotation_item_id' => (string) ($row['quotation_item_id'] ?? ''), 'deal_item_id' => (string) ($row['deal_item_id'] ?? ''),
        'item_id' => (string) $row['item_id'], 'unit_id' => (string) $row['unit_id'], 'quantity' => (string) ($row['quantity'] ?? '1'),
        'discount_percent' => (string) ($row['discount_percent'] ?? '0'), 'rate_override' => (string) ($row['rate_override'] ?? ''), 'override_reason' => (string) ($row['override_reason'] ?? ''),
    ], app(SalesOrderService::class)->startingLines($quotation, $quotation === null ? $deal : null));

    return [
        'order' => [
            ...app(OrderPricer::class)->defaults($customer, $quotation, $quotation === null ? $deal : null),
            'place_of_supply_state_id' => (string) $gujarat, 'bill_line1' => '12 Industrial Estate', 'bill_city' => 'Ahmedabad', 'bill_pin_code' => '380001', 'reprice' => '', ...$o,
        ],
        'items' => $rows,
        'customer_ledger_id' => (string) $customer->id,
    ];
}

/**
 * Make a sales order through the real service from a quotation or a deal and take it as far as `$upTo` says: 'draft', 'pending' (sent for
 * approval) or 'approved' (decided by $approver, a fresh Approver by default — which also makes the invoice).
 *
 * @param  array<string, mixed>  $o
 * @param  list<array<string, mixed>>|null  $items
 */
function makeSalesOrder(Company $company, User $by, Quotation|Deal $source, Ledger $customer, array $o = [], ?array $items = null, string $upTo = 'draft', ?User $approver = null): SalesOrder
{
    $made = inCompany($company, function () use ($source, $customer, $o, $items, $by) {
        $service = app(SalesOrderService::class);
        $input = orderInput($customer, $source, $o, $items);

        return $source instanceof Quotation ? $service->createFromQuotation($source, $input, $by) : $service->createFromDeal($source, $input, $by);
    });

    if ($upTo === 'draft') {
        return $made;
    }

    if ($upTo === 'pending') {
        inCompany($company, fn () => app(ApprovalService::class)->submit($made, $by));

        return $made->fresh();
    }

    return approveDocument($company, $made, $by, $approver ?? makeUser(['Approver'], [$company]));
}

/*
|--------------------------------------------------------------------------
| Marketing fixtures (call seedDefaults() first)
|--------------------------------------------------------------------------
*/

/**
 * An email template, made directly and approved by a fresh Approver (pass `$approved = false` to leave it a draft).
 *
 * @param  array<string, mixed>  $over
 */
function makeTemplate(Company $company, User $by, array $over = [], bool $approved = true): MessageTemplate
{
    $template = inCompany($company, function () use ($company, $by, $over) {
        $t = new MessageTemplate;
        $t->fill([
            'name' => 'Autumn offer', 'channel' => 'email', 'purpose' => 'marketing', 'subject' => 'An offer for {{first_name}}',
            'body' => "Dear {{name}},\n\nWe have a new offer from {{company}}.\n\nStop these messages: {{unsubscribe_url}}", ...$over,
        ])->forceFill(['company_id' => $company->id, 'created_by' => $by->id, 'updated_by' => $by->id])->save();

        return $t;
    });

    return $approved ? approveDocument($company, $template, $by, makeUser(['Approver'], [$company])) : $template;
}

/**
 * A campaign made through the real service, taken as far as `$upTo` says: 'draft', 'approved' (sent for approval and approved) or 'running'
 * (launched). Steps default to one message with the given template.
 *
 * @param  array<string, mixed>  $o
 */
function makeCampaign(Company $company, User $by, Location $location, MessageTemplate $template, array $o = [], string $upTo = 'draft', ?User $approver = null): Campaign
{
    $campaign = inCompany($company, fn () => app(CampaignService::class)->create([
        'name' => 'Autumn campaign', 'type' => 'email', 'start_date' => now()->toDateString(), 'audience_kind' => 'leads', 'audience' => ['stages' => ['new']],
        'location_id' => (string) $location->id, 'owner_id' => (string) $by->id, 'steps' => [['delay_hours' => '0', 'message_template_id' => (string) $template->id]],
        ...$o,
    ], $by));

    if ($upTo === 'draft') {
        return $campaign;
    }

    $campaign = approveDocument($company, $campaign, $by, $approver ?? makeUser(['Approver'], [$company]));

    return $upTo === 'running' ? inCompany($company, fn () => app(CampaignService::class)->launch($campaign, $by)) : $campaign;
}
