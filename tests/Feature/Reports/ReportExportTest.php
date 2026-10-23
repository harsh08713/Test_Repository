<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Http\Livewire\ReportExportList;
use App\Modules\Reports\Http\Livewire\ReportRun;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;

/*
 * Exports: asked for with the right to export, made in the queue as the person who asked (so their reach narrows the file as it narrows the
 * screen), kept privately, handed over only to them, and gone after a week.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Storage::fake('local');

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works']);
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => '=HYPERLINK("http://evil.example","click")']);
    makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works']);

    $this->ask = function ($user, string $key, string $format = 'xlsx', array $filters = []) {
        return inCompany($this->acme, function () use ($user, $key, $format, $filters) {
            Auth::setUser($user);
            app(TenantContext::class)->flush();

            return app(ReportExporter::class)->request(app(ReportCatalog::class)->find($key), $filters, $format, $user);
        });
    };
    $this->sheet = function (ReportExport $export): array {
        return IOFactory::load(Storage::disk('local')->path($export->file_path))->getActiveSheet()->toArray(null, true, false, false);
    };
});

it('makes an Excel file of exactly the rows the person may see, with their own reach', function () {
    $export = ($this->ask)($this->manager, 'lead-register');
    $export->refresh();

    expect($export->status)->toBe('ready')->and($export->format)->toBe('xlsx')->and($export->row_count)->toBe(2)->and($export->expires_at->toDateString())->toBe('2026-10-19');

    $rows = ($this->sheet)($export);
    $names = collect($rows)->skip(1)->pluck(2)->all();

    expect($rows[0][0])->toBe('Lead')->and($rows[0][2])->toBe('Name')
        ->and($names)->toContain('Alpha Works')->not->toContain('Beta Works');   // the manager’s team, not the colleague outside it

    // an administrator’s file holds everyone’s, still not another company’s
    $all = ($this->ask)($this->admin, 'lead-register')->refresh();
    expect($all->row_count)->toBe(3);
});

it('writes a name that looks like a formula as plain text, never as a live formula', function () {
    $export = ($this->ask)($this->admin, 'lead-register')->refresh();
    $sheet = IOFactory::load(Storage::disk('local')->path($export->file_path))->getActiveSheet();
    $found = false;

    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            if (str_contains((string) $cell->getValue(), 'HYPERLINK')) {
                $found = true;
                expect($cell->getDataType())->toBe('s');
            }
        }
    }

    expect($found)->toBeTrue();
});

it('makes a PDF too, and keeps numbers as numbers in the spreadsheet', function () {
    $pdf = ($this->ask)($this->manager, 'lead-register', 'pdf')->refresh();

    expect($pdf->status)->toBe('ready')->and(Storage::disk('local')->get($pdf->file_path))->toStartWith('%PDF');

    inCompany($this->acme, fn () => Lead::query()->where('name', 'Alpha Works')->update(['expected_value' => '650000.00']));
    $xlsx = ($this->ask)($this->manager, 'lead-register')->refresh();
    $value = collect(($this->sheet)($xlsx))->firstWhere(2, 'Alpha Works')[9];

    expect($value)->toBeNumeric()->and((float) $value)->toBe(650000.0);
});

it('refuses an export to someone who may see the report but not export it, and to a bad request', function () {
    expect(fn () => ($this->ask)($this->bde, 'lead-register'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->ask)($this->manager, 'integration-failures'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->ask)($this->manager, 'lead-register', 'docx'))->toThrow(InvalidReportFilters::class)
        ->and(fn () => ($this->ask)($this->manager, 'lead-register', 'xlsx', ['person' => (string) $this->bde2->id]))->toThrow(InvalidReportFilters::class)
        ->and(ReportExport::query()->count())->toBe(0);

    Livewire::actingAs($this->bde)->test(ReportRun::class, ['key' => 'lead-register'])->call('requestExport', 'xlsx')->assertForbidden();
    expect(ReportExport::query()->count())->toBe(0);
});

it('asks for an export from the report page and lists it under my exports', function () {
    Livewire::actingAs($this->manager)->test(ReportRun::class, ['key' => 'lead-register'])->call('requestExport', 'xlsx')->assertRedirect(route('reports.exports'));

    $export = ReportExport::query()->sole();

    expect($export->status)->toBe('ready')->and($export->user_id)->toBe($this->manager->id)->and($export->company_id)->toBe($this->acme->id);

    Livewire::actingAs($this->manager)->test(ReportExportList::class)->assertSee('Lead register')->assertSee('Ready')->assertSee(route('reports.exports.download', $export), false);
    Livewire::actingAs($this->admin)->test(ReportExportList::class)->assertDontSee(route('reports.exports.download', $export), false);   // not theirs
    Livewire::actingAs($this->bde)->test(ReportExportList::class)->assertForbidden();
});

it('hands a file only to the person who asked, while they may still export, and not after it has expired', function () {
    $export = ($this->ask)($this->manager, 'lead-register')->refresh();
    $url = route('reports.exports.download', $export);

    $this->actingAs($this->manager)->get($url)->assertOk()->assertHeader('content-disposition');
    expect($export->fresh()->downloaded_at)->not->toBeNull();

    $this->actingAs($this->admin)->get($url)->assertNotFound();           // another person in the same company
    $this->actingAs($this->globexAdmin)->get($url)->assertNotFound();     // another company
    auth()->logout();
    $this->get($url)->assertRedirect();

    // the right is checked again at download time
    $this->manager->syncRoles(['Tele-caller']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->manager->fresh())->get($url)->assertForbidden();
    $this->manager->syncRoles(['Sales Manager']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // …and the file goes after its week
    Carbon::setTestNow('2026-10-20 09:00:00');
    expect(app(ReportExporter::class)->expire())->toBe(1);
    expect($export->fresh()->status)->toBe('expired')->and($export->fresh()->file_path)->toBeNull();
    $this->actingAs($this->manager->fresh())->get($url)->assertNotFound();
});

it('fails an export for someone who has lost the right by the time the worker runs, in words and without a file', function () {
    $export = inCompany($this->acme, function () {
        $export = new ReportExport;
        $export->forceFill(['company_id' => $this->acme->id, 'user_id' => $this->bde->id, 'report' => 'lead-register', 'format' => 'xlsx', 'status' => 'queued', 'filters' => []])->save();

        return $export;
    });

    inCompany($this->acme, fn () => app(ReportExporter::class)->generate($export));

    expect($export->fresh()->status)->toBe('failed')->and($export->fresh()->error)->toContain('no longer export')->and($export->fresh()->file_path)->toBeNull();

    // a second delivery of the same job does nothing
    inCompany($this->acme, fn () => app(ReportExporter::class)->generate($export->fresh()));
    expect($export->fresh()->status)->toBe('failed');
});
