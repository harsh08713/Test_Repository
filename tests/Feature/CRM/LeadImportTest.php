<?php

use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Http\Livewire\Leads\LeadImport;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Shared\Enums\ImportStatus;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Jobs\ProcessImportBatch;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Models\ImportBatchError;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

const LEAD_HEADINGS = ['Lead Date', 'Name', 'Contact Person', 'Mobile', 'Email', 'Address', 'City', 'State', 'District', 'PIN Code', 'Source', 'Requirement', 'Expected Value', 'Assigned To', 'Branch', 'Next Follow-up', 'Remarks'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Storage::fake('local');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->localManager = makeUser(['Sales Manager'], [$this->acme], [$this->ahd]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd], ['email' => 'bhavin@acme.test']);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd], ['email' => 'chirag@acme.test']);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->globexManager = makeUser(['Sales Manager'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    // a row of the template, by column name
    $this->row = function (array $cells): array {
        $keys = ['lead_date', 'name', 'contact_person', 'mobile', 'email', 'address', 'city', 'state', 'district', 'pin_code', 'source', 'requirement', 'expected_value', 'assigned_to', 'branch', 'next_followup', 'remarks'];

        return array_map(fn (string $key) => $cells[$key] ?? '', $keys);
    };

    // upload through the wizard as $user with the page's default choices (the test queue is synchronous, so it is checked at once)
    $this->upload = function ($user, array $rows, array $choices = [], ?array $headings = null, string $extension = 'xlsx') {
        $component = Livewire::actingAs($user)->test(LeadImport::class);

        foreach (['defaultBranch' => (string) $this->ahd->id, ...$choices] as $property => $value) {
            $component->set($property, $value);
        }

        return $component->set('file', spreadsheetUpload($headings ?? LEAD_HEADINGS, $rows, $extension, 'leads'))->call('uploadAndCheck');
    };

    $this->batch = fn (): ImportBatch => ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail();
    $this->rejected = fn () => ImportBatchError::query()->where('import_batch_id', ($this->batch)()->id)->orderBy('row_number')->get()->keyBy('row_number');
    $this->leads = fn (?int $companyId = null) => Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId ?? $this->acme->id)->orderBy('id')->get();
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Template and access
// ======================================================================================================

it('registers the lead importer with a template that explains itself', function () {
    $importer = app(ImporterRegistry::class)->get('leads');

    expect($importer->permission())->toBe('lead.import')
        ->and(array_map(fn ($c) => $c->heading, $importer->columns()))->toBe(LEAD_HEADINGS)
        ->and(array_values(array_map(fn ($c) => $c->heading, array_filter($importer->columns(), fn ($c) => $c->required))))->toBe(['Name', 'Mobile']);

    $response = $this->actingAs($this->manager)->get(route('imports.template', 'leads'))->assertOk();
    $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

    expect($book->getSheetByName('Data')->toArray())->toBe([LEAD_HEADINGS])
        ->and(collect($book->getSheetByName('Instructions')->toArray())->flatten()->implode(' '))
        ->toContain('Shree Ganesh Traders')->toContain('already on an open lead')->toContain('Nothing is saved until you confirm');
});

it('opens the import page only for people who may import leads', function () {
    $this->get(route('crm.leads.import'))->assertRedirect(route('login'));

    $this->actingAs($this->manager)->get(route('crm.leads.import'))->assertOk()->assertSee('Leads from a spreadsheet')->assertSee('For rows that do not say')->assertSee('Download template');
    $this->actingAs($this->admin)->get(route('crm.leads.import'))->assertOk();

    // a salesperson and a tele-caller enter leads by hand; importing is not theirs
    $this->actingAs($this->bde)->get(route('crm.leads.import'))->assertForbidden();
    $this->actingAs($this->telecaller)->get(route('crm.leads.import'))->assertForbidden();
    $this->actingAs($this->bde)->get(route('imports.template', 'leads'))->assertForbidden();
});

// ======================================================================================================
// The check (a dry run) and the import
// ======================================================================================================

it('checks a clean file, says exactly what would happen and saves nothing', function () {
    $component = ($this->upload)($this->manager, [
        ($this->row)(['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210', 'contact_person' => 'Meera Shah', 'city' => 'Ahmedabad']),
        ($this->row)(['name' => 'Rajesh Patel', 'mobile' => '+91 98250 12345', 'assigned_to' => 'bhavin@acme.test', 'next_followup' => '15-10-2026 11:00']),
    ]);

    $batch = ($this->batch)();

    expect($batch)->status->toBe(ImportStatus::Checked)->total_rows->toBe(2)->valid_rows->toBe(2)->created_count->toBe(2)->error_count->toBe(0)
        ->and($batch->summary['counters'])->toMatchArray(['already_leads' => 0, 'followups_planned' => 1])
        ->and($batch->summary['preview'][0]['detail'])->toBe("New lead: Shree Ganesh Traders (9876543210) for {$this->manager->name}, AHD Branch")
        ->and($batch->summary['preview'][1]['detail'])->toBe("New lead: Rajesh Patel (9825012345) for {$this->bde->name}, AHD Branch, follow-up on 15 Oct 2026 11:00")
        ->and(($this->leads)())->toHaveCount(0)
        ->and(Followup::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $component->assertSee('Import 2 valid row(s)')->assertSee('Columns in your file');
});

it('imports the leads once confirmed: numbered, marked as imported, owned and followed up as the file says', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['lead_date' => '10-10-2026', 'name' => 'Shree Ganesh Traders', 'mobile' => '9876543210', 'contact_person' => 'Meera Shah', 'email' => 'MEERA@example.test',
            'address' => '12 Ashram Road', 'pin_code' => '380001', 'requirement' => 'M10 hex bolts', 'expected_value' => '₹ 2,50,000', 'remarks' => 'Met at the trade fair']),
        ($this->row)(['name' => 'Rajesh Patel', 'mobile' => '+91 98250 12345', 'source' => 'Website', 'assigned_to' => 'Bhavin@Acme.test', 'next_followup' => '15-10-2026 11:00']),
    ])->call('confirmImport')->assertSee('Import finished');

    $batch = ($this->batch)();
    [$first, $second] = ($this->leads)()->all();

    expect($batch)->status->toBe(ImportStatus::Completed)->created_count->toBe(2)->error_count->toBe(0)
        ->and($first->lead_number)->toStartWith('LD/')->toContain('AHD')
        ->and($first->entry_channel)->toBe(LeadChannel::Import)
        ->and($first->import_batch_id)->toBe($batch->id)
        ->and($first->lead_date->toDateString())->toBe('2026-10-10')
        ->and($first->lead_source_id)->toBe(leadSourceId($this->acme, 'EXCEL'))        // the page's default source
        ->and($first->mobile)->toBe('9876543210')->and($first->email)->toBe('meera@example.test')
        ->and($first->address_line1)->toBe('12 Ashram Road')
        ->and($first->district_id)->toBe($this->geo['ahmedabad']->id)->and($first->block_id)->toBe($this->geo['daskroi']->id)   // from the PIN code
        ->and($first->expected_value)->toBe('250000.00')
        ->and($first->assigned_to)->toBe($this->manager->id)                           // the uploader, by default
        ->and($first->created_by)->toBe($this->manager->id)
        ->and($first->stage)->toBe(LeadStage::New)
        ->and($second->lead_source_id)->toBe(leadSourceId($this->acme, 'WEBSITE'))
        ->and($second->assigned_to)->toBe($this->bde->id)->and($second->sales_team_id)->toBe($this->team->id)
        ->and($batch->summary['counters'])->toMatchArray(['followups_planned' => 1, 'geography_filled' => 1]);

    $followup = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_id', $second->id)->firstOrFail();

    expect($followup->status)->toBe(FollowupStatus::Pending)
        ->and($followup->scheduled_at->toDateTimeString())->toBe('2026-10-15 11:00:00')
        ->and($followup->assigned_to)->toBe($this->bde->id)
        ->and($second->next_followup_at->toDateTimeString())->toBe('2026-10-15 11:00:00');
});

it('tells each owner once what the import gave them, and records the import as one audit entry', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'One', 'mobile' => '9876500001', 'assigned_to' => 'bhavin@acme.test']),
        ($this->row)(['name' => 'Two', 'mobile' => '9876500002', 'assigned_to' => 'bhavin@acme.test']),
        ($this->row)(['name' => 'Three', 'mobile' => '9876500003']),
    ])->call('confirmImport');

    Notification::assertSentToTimes($this->bde, LeadsAssignedNotification::class, 1);
    Notification::assertSentTo($this->bde, LeadsAssignedNotification::class, fn ($n) => $n->count === 2 && $n->by === $this->manager->name && str_contains($n->firstTitle, 'One'));
    Notification::assertNotSentTo($this->manager, LeadsAssignedNotification::class);        // the uploader knows

    $perLead = ActivityLog::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_type', 'lead')->count();
    $summary = ActivityLog::withoutGlobalScopes(Tenancy::SCOPES)->where('event', 'import_completed')->get();

    expect($perLead)->toBe(0)
        ->and($summary)->toHaveCount(1)
        ->and($summary[0]->causer_id)->toBe($this->manager->id)
        ->and($summary[0]->properties->all())->toMatchArray(['importer' => 'leads', 'file' => 'leads.xlsx', 'rows' => 3, 'created' => 3, 'rejected' => 0]);
});

it('reads dates the way people and Excel write them', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'ISO', 'mobile' => '9876500001', 'lead_date' => '2026-10-09', 'next_followup' => '2026-10-20 16:45']),
        ($this->row)(['name' => 'Slashes', 'mobile' => '9876500002', 'lead_date' => '09/10/2026', 'next_followup' => '20/10/2026']),
        ($this->row)(['name' => 'Excel serial', 'mobile' => '9876500003', 'lead_date' => 46304, 'next_followup' => 46315.5]),     // 9 Oct 2026; 20 Oct 2026 12:00
        ($this->row)(['name' => 'Blank', 'mobile' => '9876500004']),
    ])->call('confirmImport');

    $leads = ($this->leads)()->keyBy('name');
    $next = fn (string $name) => $leads[$name]->next_followup_at?->toDateTimeString();

    expect($leads['ISO']->lead_date->toDateString())->toBe('2026-10-09')->and($next('ISO'))->toBe('2026-10-20 16:45:00')
        ->and($leads['Slashes']->lead_date->toDateString())->toBe('2026-10-09')->and($next('Slashes'))->toBe('2026-10-20 10:00:00')   // no time: 10:00
        ->and($leads['Excel serial']->lead_date->toDateString())->toBe('2026-10-09')->and($next('Excel serial'))->toBe('2026-10-20 12:00:00')
        ->and($leads['Blank']->lead_date->toDateString())->toBe('2026-10-12')->and($next('Blank'))->toBeNull();
});

// ======================================================================================================
// Row-level validation: bad rows are reported, good rows still go in
// ======================================================================================================

it('rejects each bad row with its own number and reason, and imports the rest', function () {
    $component = ($this->upload)($this->manager, [
        ($this->row)(['name' => 'Good One', 'mobile' => '9876500001']),                                            // 2
        ($this->row)(['name' => '', 'mobile' => '9876500002']),                                                    // 3 no name
        ($this->row)(['name' => 'No Mobile']),                                                                      // 4
        ($this->row)(['name' => 'Bad Mobile', 'mobile' => '12345']),                                                // 5
        ($this->row)(['name' => 'Bad Email', 'mobile' => '9876500006', 'email' => 'not-an-address']),               // 6
        ($this->row)(['name' => 'Future', 'mobile' => '9876500007', 'lead_date' => '01-01-2027']),                  // 7
        ($this->row)(['name' => 'Not A Date', 'mobile' => '9876500008', 'lead_date' => '31-02-2026']),              // 8
        ($this->row)(['name' => 'Past Follow-up', 'mobile' => '9876500009', 'next_followup' => '01-10-2026']),      // 9
        ($this->row)(['name' => 'Unknown Source', 'mobile' => '9876500010', 'source' => 'Carrier pigeon']),         // 10
        ($this->row)(['name' => 'Unknown State', 'mobile' => '9876500011', 'state' => 'Atlantis']),                 // 11
        ($this->row)(['name' => 'Wrong District', 'mobile' => '9876500012', 'state' => 'Maharashtra', 'district' => 'Surat']),   // 12
        ($this->row)(['name' => 'PIN Contradiction', 'mobile' => '9876500013', 'state' => 'Maharashtra', 'pin_code' => '395003']), // 13
        ($this->row)(['name' => 'Bad Value', 'mobile' => '9876500014', 'expected_value' => 'a lot']),               // 14
        ($this->row)(['name' => 'Good Two', 'mobile' => '9876500015', 'state' => 'gujarat', 'district' => 'SURAT']), // 15
    ]);

    $batch = ($this->batch)();
    $errors = ($this->rejected)();

    expect($batch)->total_rows->toBe(14)->valid_rows->toBe(2)->error_count->toBe(12)->created_count->toBe(2)
        ->and($errors->keys()->all())->toBe([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14])
        ->and($errors[3]->messages[0])->toContain('name')
        ->and($errors[4]->messages[0])->toContain('mobile number is needed')
        ->and($errors[5]->messages[0])->toContain('valid 10-digit mobile')
        ->and($errors[6]->messages[0])->toContain('e-mail')
        ->and($errors[7]->messages[0])->toBe('The lead date cannot be in the future.')
        ->and($errors[8]->messages[0])->toContain('31-02-2026')->toContain('not a date')
        ->and($errors[9]->messages[0])->toBe('Next Follow-up must be in the future.')
        ->and($errors[10]->messages[0])->toContain('Carrier pigeon')
        ->and($errors[11]->messages[0])->toContain('Atlantis')
        ->and($errors[12]->messages[0])->toContain('Surat')->toContain('Maharashtra')
        ->and($errors[13]->messages[0])->toContain('395003')->toContain('Gujarat')
        ->and($errors[14]->messages[0])->toContain('expected value')
        ->and($errors[5]->raw)->toMatchArray(['name' => 'Bad Mobile', 'mobile' => '12345']);    // the cells as uploaded

    // nothing is hidden: the screen lists them, and the good rows can still be imported
    $component->assertSee('Rejected rows')->assertSee('Carrier pigeon')->assertSee('Import 2 valid row(s)')->call('confirmImport');

    expect(($this->leads)()->pluck('name')->all())->toBe(['Good One', 'Good Two'])
        ->and(($this->batch)())->status->toBe(ImportStatus::Completed)->created_count->toBe(2)->error_count->toBe(12);

    $surat = ($this->leads)()->firstWhere('name', 'Good Two');
    expect($surat->district_id)->toBe($this->geo['surat']->id)->and($surat->state_id)->toBe($this->geo['gj']->id);
});

it('writes an error report with the rejected rows as they were uploaded', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'Good One', 'mobile' => '9876500001']),
        ($this->row)(['name' => 'Bad Mobile', 'mobile' => '12345', 'city' => 'Surat']),
    ]);

    $response = $this->actingAs($this->manager)->get(route('imports.errors', ($this->batch)()->id))->assertOk();
    $rows = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();

    expect($rows[0])->toBe(['Row', 'Problem', ...LEAD_HEADINGS])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe('3')
        ->and($rows[1][1])->toContain('mobile')
        ->and($rows[1][3])->toBe('Bad Mobile')->and($rows[1][5])->toBe('12345')->and($rows[1][8])->toBe('Surat');
});

// ======================================================================================================
// Duplicates
// ======================================================================================================

it('does not import a mobile number that is already an open lead, and says which lead it is', function () {
    $existing = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);

    $component = ($this->upload)($this->manager, [
        ($this->row)(['name' => 'Shree Ganesh Traders', 'mobile' => '98765 43210']),
        ($this->row)(['name' => 'Somebody New', 'mobile' => '9876500002']),
    ]);

    $batch = ($this->batch)();

    expect($batch)->created_count->toBe(1)->unchanged_count->toBe(1)->error_count->toBe(0)
        ->and($batch->summary['counters']['already_leads'])->toBe(1)
        ->and($batch->summary['preview'][0]['detail'])->toBe("Already a lead: {$existing->lead_number} (New)");

    $component->call('confirmImport');

    expect(($this->leads)())->toHaveCount(2)
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('mobile', '9876543210')->count())->toBe(1);
});

it('rejects the same mobile number twice in one file, but lets a number whose lead is lost come back', function () {
    $lost = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876500009']);
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lost, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'First', 'mobile' => '9876500001']),          // 2
        ($this->row)(['name' => 'Again', 'mobile' => '+919876500001']),       // 3 the same number, written differently
        ($this->row)(['name' => 'Back Again', 'mobile' => '9876500009']),     // 4 the lost lead's number
    ])->call('confirmImport');

    $errors = ($this->rejected)();

    expect($errors->keys()->all())->toBe([3])
        ->and($errors[3]->messages[0])->toBe('The same mobile number is on row 2 of this file. Keep one of them.')
        ->and(($this->leads)()->pluck('name')->all())->toContain('First', 'Back Again')->not->toContain('Again');
});

it('is idempotent: the same file uploaded again adds nothing', function () {
    $rows = [
        ($this->row)(['name' => 'One', 'mobile' => '9876500001']),
        ($this->row)(['name' => 'Two', 'mobile' => '9876500002', 'next_followup' => '15-10-2026']),
    ];

    ($this->upload)($this->manager, $rows)->call('confirmImport');
    expect(($this->leads)())->toHaveCount(2);

    $second = ($this->upload)($this->manager, $rows)->assertSee('nothing to import');

    expect(($this->batch)())->status->toBe(ImportStatus::Checked)->created_count->toBe(0)->unchanged_count->toBe(2)
        ->and(($this->leads)())->toHaveCount(2)
        ->and(Followup::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    $second->call('confirmImport')->assertHasErrors(['batch']);
    expect(($this->leads)())->toHaveCount(2);
});

it('commits imported leads a few at a time, so a branch’s number series is never held for a whole file', function () {
    // Each lead takes its branch's number series; a whole chunk in one transaction would keep everyone else who is
    // entering a lead in that branch waiting until the chunk was done.
    expect(app(ImporterRegistry::class)->get('leads')->commitEvery())->toBe(50)->and(config('formezy.imports.chunk_rows'))->toBeGreaterThan(120);

    $rows = collect(range(1, 120))->map(fn (int $i) => ($this->row)(['name' => "Lead {$i}", 'mobile' => '90000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]))->all();
    $component = ($this->upload)($this->manager, $rows);

    // every time the import reports its progress, a group of rows has just been committed
    $progress = [];
    ImportBatch::saved(function (ImportBatch $batch) use (&$progress) {
        if ($batch->status === ImportStatus::Processing) {
            $progress[] = $batch->processed_rows;
        }
    });

    $component->call('confirmImport');

    // from the moment the import starts counting afresh (before that the batch still carries the check's total)
    $counted = array_slice($progress, (int) array_search(0, $progress, true));

    expect(array_values(array_unique($counted)))->toBe([0, 50, 100, 120])
        ->and(($this->batch)())->status->toBe(ImportStatus::Completed)->created_count->toBe(120)
        ->and(($this->leads)())->toHaveCount(120)
        ->and(($this->leads)()->pluck('lead_number')->unique())->toHaveCount(120);
});

it('does not import a row twice when the same import job is delivered twice', function () {
    ($this->upload)($this->manager, [($this->row)(['name' => 'One', 'mobile' => '9876500001'])])->call('confirmImport');
    $batch = ($this->batch)();

    ProcessImportBatch::dispatchSync($batch->id, $this->acme->id, 'apply');

    expect(($this->leads)())->toHaveCount(1)->and($batch->fresh()->status)->toBe(ImportStatus::Completed);
});

// ======================================================================================================
// Headings: alternatives are recognised, and anything else can be mapped
// ======================================================================================================

it('recognises the headings people actually use', function () {
    ($this->upload)($this->manager, [
        ['Shree Ganesh Traders', 'Meera Shah', '9876543210', 'meera@example.test', 'Ahmedabad', '380001', 'Bolts', '250000', 'call back in the evening', 'ignored'],
    ], [], ['Company Name', 'Contact', 'Mobile No.', 'E-mail', 'Town', 'Pincode', 'Product', 'Budget', 'Notes', 'Internal Ref'])->call('confirmImport');

    $lead = ($this->leads)()->first();

    expect(($this->batch)())->status->toBe(ImportStatus::Completed)->created_count->toBe(1)
        ->and(($this->batch)()->summary['ignored_columns'])->toBe(['Internal Ref'])
        ->and($lead->name)->toBe('Shree Ganesh Traders')->and($lead->contact_person)->toBe('Meera Shah')->and($lead->mobile)->toBe('9876543210')
        ->and($lead->city)->toBe('Ahmedabad')->and($lead->pin_code)->toBe('380001')->and($lead->requirement)->toBe('Bolts')
        ->and($lead->expected_value)->toBe('250000.00')->and($lead->remarks)->toBe('call back in the evening');
});

it('lets the person say which of their columns is which when the headings are not recognised', function () {
    $component = ($this->upload)($this->manager, [
        ['Shree Ganesh Traders', '9876543210', 'Ahmedabad', 'Hot'],
    ], [], ['Client', 'WhatsApp', 'Place', 'Temperature']);

    // the check cannot start: but the file's own headings are on the screen to be mapped
    expect(($this->batch)())->status->toBe(ImportStatus::Failed)->and(($this->batch)()->failure_reason)->toContain('Missing: Name, Mobile');
    $component->assertSee('Columns in your file')->assertSee('Client')->assertSee('WhatsApp')->assertSee('Temperature')
        ->assertSet('mapping.client', '')->assertSet('mapping.whatsapp', '');

    $component->set('mapping.client', 'name')->set('mapping.whatsapp', 'mobile')->set('mapping.place', 'city')->call('applyMapping')->assertHasNoErrors();

    $batch = ($this->batch)();
    expect($batch)->status->toBe(ImportStatus::Checked)->created_count->toBe(1)->error_count->toBe(0)
        ->and($batch->summary['ignored_columns'])->toBe(['Temperature'])
        ->and($batch->summary['mapping'])->toMatchArray(['client' => 'name', 'whatsapp' => 'mobile', 'place' => 'city', 'temperature' => '']);

    $component->call('confirmImport');
    $lead = ($this->leads)()->first();

    expect($lead->name)->toBe('Shree Ganesh Traders')->and($lead->mobile)->toBe('9876543210')->and($lead->city)->toBe('Ahmedabad');
});

it('lets a recognised column be re-read as something else, or ignored', function () {
    // "Phone" is taken for the mobile number; this file keeps the mobile in "Alt No" and a landline in "Phone"
    $component = ($this->upload)($this->manager, [
        ['Shree Ganesh Traders', '022-2658-0000', '9876543210', 'Do not import this'],
    ], [], ['Name', 'Phone', 'Alt No', 'Remarks']);

    expect(($this->batch)())->error_count->toBe(1);    // a landline is not a mobile number
    $component->assertSet('mapping.phone', 'mobile')->assertSet('mapping.remarks', 'remarks');

    $component->set('mapping.phone', '')->set('mapping.alt_no', 'mobile')->set('mapping.remarks', '')->call('applyMapping')->call('confirmImport');

    $lead = ($this->leads)()->first();
    expect($lead->mobile)->toBe('9876543210')->and($lead->remarks)->toBeNull();
});

it('refuses a mapping that makes no sense', function () {
    $component = ($this->upload)($this->manager, [['Shree Ganesh Traders', '9876543210', 'x']], [], ['Name', 'Mobile', 'Extra']);

    $component->set('mapping.extra', 'mobile')->call('applyMapping')->assertHasErrors(['mapping']);          // two columns, one field
    $component->set('mapping.extra', 'lead_number')->call('applyMapping')->assertHasErrors(['mapping']);     // not a field of this import

    expect(($this->batch)())->status->toBe(ImportStatus::Checked)->created_count->toBe(1);                   // the earlier check stands
});

it('reads a CSV file as well', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'Csv Traders', 'mobile' => '9876500001', 'pin_code' => '395003']),
    ], [], null, 'csv')->call('confirmImport');

    $lead = ($this->leads)()->first();
    expect($lead->name)->toBe('Csv Traders')->and($lead->district_id)->toBe($this->geo['surat']->id);
});

// ======================================================================================================
// Branch, source and owner: from the row, else from the choices on the upload screen — within the uploader's reach
// ======================================================================================================

it('takes the branch, source and owner from the row when it names them, and otherwise from the upload screen', function () {
    ($this->upload)($this->admin, [
        ($this->row)(['name' => 'Defaults', 'mobile' => '9876500001']),
        ($this->row)(['name' => 'Named', 'mobile' => '9876500002', 'branch' => 'ahd', 'source' => 'JUSTDIAL', 'assigned_to' => 'chirag@acme.test']),
    ], ['defaultBranch' => (string) $this->mum->id, 'defaultSource' => (string) leadSourceId($this->acme, 'REFERENCE'), 'defaultOwner' => (string) $this->manager->id])->call('confirmImport');

    $leads = ($this->leads)()->keyBy('name');

    expect($leads['Defaults']->location_id)->toBe($this->mum->id)
        ->and($leads['Defaults']->lead_source_id)->toBe(leadSourceId($this->acme, 'REFERENCE'))
        ->and($leads['Defaults']->assigned_to)->toBe($this->manager->id)
        ->and($leads['Defaults']->lead_number)->toContain('MUM')
        ->and($leads['Named']->location_id)->toBe($this->ahd->id)
        ->and($leads['Named']->lead_source_id)->toBe(leadSourceId($this->acme, 'JUSTDIAL'))
        ->and($leads['Named']->assigned_to)->toBe($this->bde2->id);
});

it('needs a branch from somewhere', function () {
    ($this->upload)($this->manager, [
        ($this->row)(['name' => 'No Branch', 'mobile' => '9876500001']),
        ($this->row)(['name' => 'Has Branch', 'mobile' => '9876500002', 'branch' => 'MUM']),
    ], ['defaultBranch' => '']);

    $errors = ($this->rejected)();

    expect($errors->keys()->all())->toBe([2])
        ->and($errors[2]->messages[0])->toBe('No branch: fill in the Branch column, or choose a branch when uploading.')
        ->and(($this->batch)()->created_count)->toBe(1);
});

it('keeps the import inside the uploader’s own reach: their branches and their people', function () {
    // this manager works in Ahmedabad only and runs a team with one person on it
    $own = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd], ['email' => 'own@acme.test']);
    makeTeam($this->acme, $this->localManager, [$own], 'LOCAL', 'Local team');

    ($this->upload)($this->localManager, [
        ($this->row)(['name' => 'Fine', 'mobile' => '9876500001', 'assigned_to' => 'own@acme.test']),              // 2
        ($this->row)(['name' => 'Other Branch', 'mobile' => '9876500002', 'branch' => 'MUM']),                     // 3 not their branch
        ($this->row)(['name' => 'Not Theirs', 'mobile' => '9876500003', 'assigned_to' => 'bhavin@acme.test']),     // 4 another manager's person
        ($this->row)(['name' => 'Stranger', 'mobile' => '9876500004', 'assigned_to' => 'nobody@nowhere.test']),    // 5
        ($this->row)(['name' => 'No Such Branch', 'mobile' => '9876500005', 'branch' => 'NYC']),                   // 6 another company's branch code
    ])->call('confirmImport');

    $errors = ($this->rejected)();

    expect($errors->keys()->all())->toBe([3, 4, 5, 6])
        ->and($errors[3]->messages[0])->toContain('MUM')->toContain('not the code of one of your active branches')
        ->and($errors[4]->messages[0])->toBe('You can assign leads only to yourself or to people on the teams you manage.')
        ->and($errors[5]->messages[0])->toContain('nobody@nowhere.test')
        ->and($errors[6]->messages[0])->toContain('NYC')
        ->and(($this->leads)()->pluck('name')->all())->toBe(['Fine'])
        ->and(($this->leads)()->first()->assigned_to)->toBe($own->id);
});

it('checks the choices on the upload screen before the file is even stored', function () {
    $component = Livewire::actingAs($this->localManager)->test(LeadImport::class)->assertSet('defaultBranch', (string) $this->ahd->id)   // their only branch
        ->assertSet('defaultOwner', (string) $this->localManager->id)->assertSet('defaultSource', (string) leadSourceId($this->acme, 'EXCEL'));

    $file = fn () => spreadsheetUpload(LEAD_HEADINGS, [($this->row)(['name' => 'One', 'mobile' => '9876500001'])], 'xlsx', 'leads');

    $component->set('defaultBranch', (string) $this->mum->id)->set('file', $file())->call('uploadAndCheck')->assertHasErrors(['defaultBranch']);
    $component->set('defaultBranch', (string) $this->ahd->id)->set('defaultOwner', (string) $this->bde->id)->set('file', $file())->call('uploadAndCheck')->assertHasErrors(['defaultOwner']);
    $component->set('defaultOwner', (string) $this->localManager->id)->set('defaultSource', (string) leadSourceId($this->globex))->set('file', $file())->call('uploadAndCheck')->assertHasErrors(['defaultSource']);

    expect(ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('applies the uploader’s reach again when the import runs, whatever the stored choices say', function () {
    ($this->upload)($this->localManager, [($this->row)(['name' => 'One', 'mobile' => '9876500001'])]);
    $batch = ($this->batch)();

    // the stored choices are tampered with after the upload: another branch, somebody else's salesperson
    $batch->forceFill(['summary' => [...$batch->summary, 'options' => ['location_id' => $this->mum->id, 'lead_source_id' => null, 'assigned_to' => $this->bde->id]]])->save();

    Livewire::actingAs($this->localManager)->test(LeadImport::class)->call('recheck');

    $errors = ($this->rejected)();
    expect(($this->batch)())->created_count->toBe(0)->error_count->toBe(1)
        ->and(implode(' ', $errors[2]->messages))->toContain('No branch')->toContain('only to yourself or to people on the teams you manage');
});

it('rejects every row when the person who uploaded the file has been deactivated since', function () {
    ($this->upload)($this->manager, [($this->row)(['name' => 'One', 'mobile' => '9876500001'])]);
    $batch = ($this->batch)();
    $this->manager->forceFill(['is_active' => false])->save();

    $batch->forceFill(['status' => ImportStatus::Queued])->save();
    ProcessImportBatch::dispatchSync($batch->id, $this->acme->id, 'apply');

    expect(($this->leads)())->toHaveCount(0)
        ->and($batch->fresh())->error_count->toBe(1)
        ->and(($this->rejected)()[2]->messages[0])->toContain('no longer an active user');
});

// ======================================================================================================
// Tenancy
// ======================================================================================================

it('keeps each company’s imports and leads apart, even for the same file', function () {
    $rows = [($this->row)(['name' => 'Same File', 'mobile' => '9876500001'])];
    $globexBranch = Location::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->firstOrFail();

    ($this->upload)($this->manager, $rows)->call('confirmImport');
    ($this->upload)($this->globexManager, $rows, ['defaultBranch' => (string) $globexBranch->id])->call('confirmImport');

    $acme = ($this->leads)($this->acme->id);
    $globex = ($this->leads)($this->globex->id);

    // the same mobile number in two companies is two leads: one company's lead is not the other's duplicate
    expect($acme)->toHaveCount(1)->and($globex)->toHaveCount(1)
        ->and($globex[0]->location_id)->toBe($globexBranch->id)
        ->and($globex[0]->lead_source_id)->toBe(leadSourceId($this->globex, 'EXCEL'))
        ->and($globex[0]->assigned_to)->toBe($this->globexManager->id)
        ->and($acme[0]->lead_source_id)->toBe(leadSourceId($this->acme, 'EXCEL'));

    // and neither can see or open the other's upload
    $acmeBatch = ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->firstOrFail();
    Livewire::actingAs($this->globexManager)->test(LeadImport::class)->call('openBatch', $acmeBatch->id)->assertNotFound();
    $this->actingAs($this->globexManager)->get(route('imports.errors', $acmeBatch->id))->assertNotFound();
});
