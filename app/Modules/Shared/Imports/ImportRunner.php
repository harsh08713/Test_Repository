<?php

namespace App\Modules\Shared\Imports;

use App\Models\User;
use App\Modules\Shared\Enums\ImportStatus;
use App\Modules\Shared\Jobs\ProcessImportBatch;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives one uploaded file through upload → check → (confirm) → apply, whatever the file contains.
 *
 * - Every transition is one atomic conditional UPDATE, so a double click or a duplicate queue delivery can never
 *   start the same phase twice.
 * - The check is a dry run of the very same row logic the apply uses; nothing is written until someone confirms.
 * - Rows that fail are skipped and listed (with the original cells) in `import_batch_errors`; rows that pass are
 *   applied in chunks, each in one transaction, and each row inside a savepoint. Because importers are idempotent,
 *   a failed run is safely retried.
 * - Bulk imports write one summary audit entry instead of one per created row.
 */
final class ImportRunner
{
    public function __construct(private readonly ImporterRegistry $importers) {}

    // ---- upload -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $options  the importer's own choices from the upload screen (already validated there),
     *                                         kept with the batch and handed to the importer for the check and the import
     */
    public function store(UploadedFile $file, Importer $importer, User $user, array $options = []): ImportBatch
    {
        $companyId = app(TenantContext::class)->activeCompanyId();
        abort_if($companyId === null, 403);

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw new ImportFileException('Upload an .xlsx or .csv file.');
        }

        // Look inside before keeping it: a renamed image or a zip bomb is refused at the door, not mid-import.
        (new SpreadsheetReader((string) $file->getRealPath(), $extension))->assertSafe((int) config('formezy.imports.max_unpacked_mb'));

        $disk = config('formezy.imports.disk');
        $stored = $file->storeAs("imports/{$companyId}", Str::lower((string) Str::ulid()).'.'.$extension, $disk);

        if ($stored === false) {
            throw new ImportFileException('The file could not be saved. Please try again.');
        }

        $batch = new ImportBatch([
            'importer' => $importer->key(),
            'original_filename' => $this->cleanFilename($file->getClientOriginalName()),
            'stored_path' => $stored,
        ]);
        $batch->forceFill([
            'company_id' => $companyId, 'created_by' => $user->getKey(), 'status' => ImportStatus::Uploaded,
            'summary' => $options === [] ? null : ['options' => $options],
        ])->save();

        return $batch;
    }

    /**
     * Say which column of the file feeds which field, then check again. For a file whose headings are not the
     * template's: the person maps them instead of renaming columns in the spreadsheet.
     *
     * @param  array<array-key, mixed>  $map  heading (as slugged by ImportColumn::slug) => column key, or '' to ignore it.
     *                                        It comes from the browser: anything that is not one of this importer's
     *                                        columns is dropped here, whatever the screen checked.
     */
    public function mapColumns(ImportBatch $batch, array $map): bool
    {
        if (! in_array($batch->status, [ImportStatus::Checked, ImportStatus::Failed, ImportStatus::Uploaded], true)) {
            return false;
        }

        $keys = array_map(fn (ImportColumn $c) => $c->key, $this->importers->get($batch->importer)->columns());
        $clean = [];

        foreach ($map as $heading => $key) {
            $heading = ImportColumn::slug((string) $heading);

            if ($heading !== '' && is_string($key) && ($key === '' || in_array($key, $keys, true))) {
                $clean[$heading] = $key;
            }
        }

        $batch->forceFill(['summary' => [...($batch->summary ?? []), 'column_map' => $clean]])->save();

        return $this->requestCheck($batch);
    }

    // ---- requests (UI side) ---------------------------------------------------------------------------------

    /** Start (or restart) the check. False when the batch is not in a state that allows it. */
    public function requestCheck(ImportBatch $batch): bool
    {
        if (! $this->claim($batch, [ImportStatus::Uploaded, ImportStatus::Checked, ImportStatus::Failed], ImportStatus::Checking)) {
            return false;
        }

        ProcessImportBatch::dispatch($batch->id, $batch->company_id, 'check');

        return true;
    }

    /** Confirm: queue the real import. Needs a checked file that would change something, or a failed apply to retry. */
    public function requestApply(ImportBatch $batch): bool
    {
        $retry = $batch->status === ImportStatus::Failed && $batch->summaryValue('failed_during') === 'apply';

        if ($batch->status === ImportStatus::Checked && $batch->created_count + $batch->updated_count === 0) {
            return false;   // nothing would change
        }

        if (! $retry && $batch->status !== ImportStatus::Checked) {
            return false;
        }

        // One import at a time per company and kind: two at once would only fight over the same rows.
        $running = ImportBatch::query()->where('importer', $batch->importer)->whereKeyNot($batch->id)
            ->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Processing->value])->exists();

        if ($running || ! $this->claim($batch, [ImportStatus::Checked, ImportStatus::Failed], ImportStatus::Queued)) {
            return false;
        }

        ProcessImportBatch::dispatch($batch->id, $batch->company_id, 'apply');

        return true;
    }

    public function cancel(ImportBatch $batch): bool
    {
        return $this->claim($batch, [ImportStatus::Uploaded, ImportStatus::Checked, ImportStatus::Failed], ImportStatus::Cancelled);
    }

    /** For a batch whose worker died: let the person move on instead of waiting forever. */
    public function markStuckAsFailed(ImportBatch $batch): bool
    {
        if ($batch->updated_at === null || $batch->updated_at->gt(now()->subMinutes(30))) {
            return false;
        }

        $during = $batch->status === ImportStatus::Checking ? 'check' : 'apply';
        $changed = $this->claim($batch, [ImportStatus::Checking, ImportStatus::Queued, ImportStatus::Processing], ImportStatus::Failed);

        if ($changed) {
            $this->recordFailure($batch->refresh(), $during, 'The import did not finish (the background worker stopped). Nothing was lost; you can retry it.');
        }

        return $changed;
    }

    // ---- job side -----------------------------------------------------------------------------------------

    public function process(ImportBatch $batch, string $phase): void
    {
        $apply = $phase === 'apply';

        $started = $apply
            ? $this->claim($batch, [ImportStatus::Queued], ImportStatus::Processing)
            : $batch->status === ImportStatus::Checking;

        if (! $started) {
            return;   // a duplicate delivery, or the batch was cancelled meanwhile
        }

        $batch->refresh();

        try {
            $this->run($batch, $apply);
        } catch (ImportFileException $e) {
            $this->recordFailure($batch, $phase, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->recordFailure($batch, $phase, 'The import stopped unexpectedly. Nothing from the failed step was lost or half-saved; you can retry it.');
        }
    }

    public function recordFailure(ImportBatch $batch, string $phase, string $reason): void
    {
        $batch->forceFill([
            'status' => ImportStatus::Failed,
            'failure_reason' => Str::limit($reason, 500),
            'finished_at' => now(),
            'summary' => [...($batch->summary ?? []), 'failed_during' => $phase],
        ])->save();
    }

    // ---- the pass over the file ---------------------------------------------------------------------------

    private function run(ImportBatch $batch, bool $apply): void
    {
        $importer = $this->importers->get($batch->importer);
        $phase = $apply ? 'apply' : 'check';
        $disk = Storage::disk(config('formezy.imports.disk'));

        if (! $disk->exists($batch->stored_path)) {
            throw new ImportFileException('The uploaded file is no longer available. Please upload it again.');
        }

        $reader = new SpreadsheetReader($disk->path($batch->stored_path), pathinfo($batch->stored_path, PATHINFO_EXTENSION));
        $reader->assertSafe((int) config('formezy.imports.max_unpacked_mb'));

        // Remember what the file calls its columns before anything can go wrong with them, so the person can map
        // them to our fields even when a required heading was not recognised.
        $headings = $reader->headings();
        $batch->forceFill(['summary' => [...($batch->summary ?? []), 'headings' => $this->describeHeadings($headings)]])->save();

        [$map, $ignored, $mapping] = $this->mapHeadings($importer, $headings, (array) $batch->summaryValue('column_map', []));

        // The sheet's declared size can include thousands of formatted-but-empty rows, so it is only a progress estimate.
        $maxRows = (int) config('formezy.imports.max_rows');
        $upperBound = min($maxRows, max(0, $reader->lastRow() - 1));

        $batch->errors()->delete();
        $batch->forceFill([
            'total_rows' => $upperBound, 'valid_rows' => 0, 'processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0,
            'unchanged_count' => 0, 'error_count' => 0, 'failure_reason' => null, 'started_at' => now(), 'finished_at' => null,
            'summary' => [
                ...Arr::except($batch->summary ?? [], ['failed_during', 'counters']),
                'ignored_columns' => $ignored,
                'mapping' => $mapping,
            ],
        ])->save();

        $user = $batch->created_by !== null ? User::query()->find($batch->created_by) : null;
        $session = $importer->begin($batch->company_id, $batch->created_by, [...(array) $batch->summaryValue('options', []), '_batch_id' => $batch->id]);
        $previewLimit = (int) config('formezy.imports.preview_rows');
        $totals = ['rows' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        $preview = [];

        $chunkRows = max(1, (int) config('formezy.imports.chunk_rows'));
        $commitEvery = $importer instanceof CommitsInBatches ? max(1, min($importer->commitEvery(), $chunkRows)) : $chunkRows;

        $pass = function () use ($reader, $map, $session, $batch, $apply, $phase, $user, $maxRows, $previewLimit, $chunkRows, $commitEvery, &$totals, &$preview) {
            $blankChunks = 0;

            foreach ($reader->chunks($chunkRows) as $raw) {
                $rows = [];
                foreach ($raw as $number => $cells) {
                    $assoc = $this->toAssoc($cells, $map);
                    if ($assoc !== null) {
                        $rows[$number] = $assoc;
                    }
                }

                if ($rows === []) {
                    // Excel sheets often carry a long tail of formatted but empty rows: stop at the end of the data.
                    if (++$blankChunks >= 5) {
                        break;
                    }

                    continue;
                }

                $blankChunks = 0;

                if ($maxRows < $totals['rows'] + count($rows)) {
                    throw new ImportFileException('The file has more than '.number_format($maxRows).' rows. Split it into smaller files.');
                }

                $session->prepareChunk($rows);

                // The rows of a chunk are committed together — or, for an importer that says so, a few at a time, so
                // that the locks its rows take are not held while thousands more are processed.
                foreach (array_chunk($rows, $apply ? $commitEvery : count($rows), true) as $together) {
                    $errors = [];

                    $handle = function () use ($together, $session, $apply, $phase, $user, $previewLimit, &$totals, &$preview, &$errors) {
                        foreach ($together as $number => $cells) {
                            $outcome = $this->processRow($session, $number, $cells, $apply, $user?->getKey());
                            $totals['rows']++;
                            $totals[$outcome->isError() ? 'errors' : $outcome->status->value]++;

                            if ($outcome->isError()) {
                                $errors[] = [
                                    'row_number' => $number, 'phase' => $phase, 'messages' => json_encode($outcome->messages),
                                    'raw' => json_encode(array_map(fn (string $v) => Str::limit($v, 300, '…'), $cells)),
                                ];
                            }

                            if (count($preview) < $previewLimit) {
                                $preview[] = ['row' => $number, 'status' => $outcome->status->value, 'detail' => $outcome->isError() ? implode(' ', $outcome->messages) : $outcome->detail, 'cells' => $cells];
                            }
                        }
                    };

                    $apply ? DB::transaction($handle) : $handle();

                    if ($errors !== []) {
                        foreach (array_chunk(array_map(fn (array $e) => ['import_batch_id' => $batch->id, ...$e], $errors), 500) as $insert) {
                            DB::table('import_batch_errors')->insert($insert);
                        }
                    }

                    $batch->forceFill([
                        'processed_rows' => $totals['rows'], 'valid_rows' => $totals['rows'] - $totals['errors'], 'created_count' => $totals['created'],
                        'updated_count' => $totals['updated'], 'unchanged_count' => $totals['unchanged'], 'error_count' => $totals['errors'],
                    ])->save();
                }
            }
        };

        // A bulk import is one audited event, not one entry per created row.
        $apply ? activity()->withoutLogs($pass) : $pass();

        $batch->forceFill([
            'status' => $apply ? ImportStatus::Completed : ImportStatus::Checked,
            'total_rows' => $totals['rows'],
            'processed_rows' => $totals['rows'],
            'finished_at' => now(),
            'summary' => [
                ...($batch->summary ?? []),
                'counters' => $session->counters(),
                ...($apply ? [] : ['preview' => $preview]),
            ],
        ])->save();

        if ($apply) {
            activity('import')
                ->performedOn($batch)
                ->causedBy($user)
                ->event('import_completed')
                ->withProperties([
                    'importer' => $batch->importer, 'file' => $batch->original_filename, 'rows' => $totals['rows'],
                    'created' => $totals['created'], 'updated' => $totals['updated'], 'unchanged' => $totals['unchanged'],
                    'rejected' => $totals['errors'], ...$session->counters(),
                ])
                ->log('import_completed');

            // What follows the import (telling people what they were given) cannot undo it: the rows are in.
            if ($session instanceof FinishesImport) {
                try {
                    $session->finished();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** @param  array<string, string>  $cells */
    private function processRow(ImportSession $session, int $number, array $cells, bool $apply, ?int $userId): RowOutcome
    {
        if (! $apply) {
            return $session->process($number, $cells, false, null);
        }

        try {
            // A savepoint inside the chunk's transaction: one bad row undoes only itself.
            return DB::transaction(fn () => $session->process($number, $cells, true, $userId));
        } catch (UniqueConstraintViolationException) {
            return RowOutcome::error('This clashes with a record that already exists (names that differ only by case or accents count as the same).');
        }
    }

    /**
     * Decide which column of the file feeds which field: what the person chose for a heading wins; otherwise the
     * heading is matched against the importer's column names and their aliases.
     *
     * @param  list<mixed>  $headings
     * @param  array<array-key, mixed>  $chosen  heading slug => column key ('' = ignore), from mapColumns()
     * @return array{0: array<int, string>, 1: list<string>, 2: array<string, string>} column index => column key; the
     *                                                                                 headings that were ignored; and heading slug => column key ('' = ignored) for the screen
     */
    private function mapHeadings(Importer $importer, array $headings, array $chosen = []): array
    {
        $map = [];
        $ignored = [];
        $mapping = [];
        $columns = collect($importer->columns());

        foreach ($headings as $index => $heading) {
            $text = $this->normalise($heading);

            if ($text === '') {
                continue;
            }

            $slug = ImportColumn::slug($text);

            if ($slug !== '' && array_key_exists($slug, $chosen)) {
                $column = $chosen[$slug] === '' ? null : $columns->first(fn (ImportColumn $c) => $c->key === $chosen[$slug]);
            } else {
                $column = $columns->first(fn (ImportColumn $c) => $c->matches($text));
            }

            if ($column === null) {
                $ignored[] = Str::limit($text, 60);
                $mapping[$slug] = '';

                continue;
            }

            if (in_array($column->key, $map, true)) {
                throw new ImportFileException("The column \"{$column->heading}\" appears more than once. Each column must appear once.");
            }

            $map[$index] = $column->key;
            $mapping[$slug] = $column->key;
        }

        $missing = $columns->filter(fn (ImportColumn $c) => $c->required && ! in_array($c->key, $map, true))
            ->map(fn (ImportColumn $c) => $c->heading);

        if ($missing->isNotEmpty()) {
            throw new ImportFileException('The first row must contain the column headings. Missing: '.$missing->implode(', ').'. Download the template for the exact headings.');
        }

        return [$map, array_values(array_unique($ignored)), $mapping];
    }

    /**
     * The file's headings as the person wrote them, with the slug they are matched by — for the column-mapping screen.
     *
     * @param  list<mixed>  $headings
     * @return list<array{text: string, slug: string}>
     */
    private function describeHeadings(array $headings): array
    {
        $described = [];

        foreach ($headings as $heading) {
            $text = $this->normalise($heading);
            $slug = ImportColumn::slug($text);

            if ($text !== '' && $slug !== '' && count($described) < 100) {
                $described[] = ['text' => Str::limit($text, 60), 'slug' => $slug];
            }
        }

        return $described;
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<int, string>  $map
     * @return array<string, string>|null null for a row with nothing in any known column
     */
    private function toAssoc(array $cells, array $map): ?array
    {
        $assoc = [];
        foreach ($map as $index => $key) {
            $assoc[$key] = $this->normalise($cells[$index] ?? null);
        }

        return array_filter($assoc, fn (string $v) => $v !== '') === [] ? null : $assoc;
    }

    /** Cell value → tidy single-line text: no control characters, no stray or doubled spaces, numbers without ".0". */
    public function normalise(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if (is_object($value) && ! $value instanceof \Stringable) {
            return '';
        }

        $text = (string) $value;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\PC]/u', '', $name) ?? 'upload';

        return Str::limit($name !== '' ? $name : 'upload', 200, '');
    }

    /** @param  list<ImportStatus>  $from */
    private function claim(ImportBatch $batch, array $from, ImportStatus $to): bool
    {
        return ImportBatch::query()
            ->whereKey($batch->getKey())
            ->whereIn('status', array_map(fn (ImportStatus $s) => $s->value, $from))
            ->update(['status' => $to->value, 'updated_at' => now()]) === 1;
    }
}
