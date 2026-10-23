<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Models\User;
use App\Modules\Shared\Enums\ImportStatus;
use App\Modules\Shared\Imports\Importer;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Imports\ImportFileException;
use App\Modules\Shared\Imports\ImportRunner;
use App\Modules\Shared\Models\ImportBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The same four steps for every import: download the template → upload → review what the check found →
 * confirm. Subclasses only name their importer.
 *
 * Everything the browser sends is distrusted: the batch id is locked, every action reloads the batch through the
 * tenant-scoped query and authorises it, and the status transitions are atomic in the ImportRunner.
 */
abstract class ImportWizard extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $batchId = null;

    public ?TemporaryUploadedFile $file = null;

    /**
     * Which column of the open file feeds which field: heading slug => column key, '' = ignore. Filled from what the
     * check worked out; the person can change it and check again — so by the time it is used it is what the browser
     * sent, and is checked as such.
     *
     * @var array<array-key, mixed>
     */
    public array $mapping = [];

    abstract protected function importerKey(): string;

    /** A Blade partial with the page's tabs, if it sits inside a tabbed section. */
    protected function tabsView(): ?string
    {
        return null;
    }

    /** A Blade partial with extra choices for the upload step (a default branch, a default owner…), if the importer has any. */
    protected function optionsView(): ?string
    {
        return null;
    }

    /**
     * The importer's own choices, validated, to keep with the upload. Throw a ValidationException to refuse the upload.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [];
    }

    /**
     * Extra data for the options partial.
     *
     * @return array<string, mixed>
     */
    protected function optionsData(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->authorizeImporter();

        // Pick up where this person left off, so a reload or a long import does not lose the screen.
        $this->batchId = ImportBatch::query()
            ->where('importer', $this->importerKey())
            ->where('created_by', auth()->id())
            ->whereIn('status', [ImportStatus::Uploaded, ImportStatus::Checking, ImportStatus::Checked, ImportStatus::Queued, ImportStatus::Processing, ImportStatus::Failed])
            ->latest('id')
            ->value('id');
    }

    /**
     * Keep the chosen file and check it straight away.
     *
     * Not called `upload`: in the browser that name belongs to Livewire's own file-upload helper (`$wire.upload`), which
     * would be run instead of this action — the button would do nothing, and no server-side test would notice.
     */
    public function uploadAndCheck(ImportRunner $runner): void
    {
        $this->authorizeImporter();

        $this->validate([
            'file' => ['required', 'file', 'max:'.(int) config('formezy.imports.max_file_kb'), 'extensions:xlsx,csv'],
        ], [
            'file.required' => 'Choose the filled-in template (.xlsx) or a .csv file first.',
            'file.max' => 'The file is too large (limit '.round((int) config('formezy.imports.max_file_kb') / 1024).' MB).',
            'file.extensions' => 'Upload an .xlsx or .csv file.',
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User && $this->file !== null, 403);

        $options = $this->options();   // validated by the page that owns them

        try {
            $batch = $runner->store($this->file, $this->importer(), $user, $options);
        } catch (ImportFileException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->file = null;
        $this->mapping = [];
        $this->batchId = $batch->id;
        $runner->requestCheck($batch);
    }

    /** Check the file again, reading its columns the way the person has just mapped them. */
    public function applyMapping(ImportRunner $runner): void
    {
        $keys = array_map(fn ($column) => $column->key, $this->importer()->columns());
        $chosen = array_filter($this->mapping, fn ($key) => $key !== '');

        foreach ($this->mapping as $key) {
            if (! is_string($key) || ($key !== '' && ! in_array($key, $keys, true))) {
                $this->addError('mapping', 'Choose one of the listed fields for each column.');

                return;
            }
        }

        if (count($chosen) !== count(array_unique($chosen))) {
            $this->addError('mapping', 'Two columns of the file are mapped to the same field. Each field can come from one column only.');

            return;
        }

        if (! $runner->mapColumns($this->currentBatch(), $this->mapping)) {
            $this->addError('batch', 'This file cannot be checked right now.');
        }
    }

    /** Run the check again, e.g. after fixing a setting the file depends on. */
    public function recheck(ImportRunner $runner): void
    {
        if (! $runner->requestCheck($this->currentBatch())) {
            $this->addError('batch', 'This file cannot be checked right now.');
        }
    }

    public function confirmImport(ImportRunner $runner): void
    {
        if (! $runner->requestApply($this->currentBatch())) {
            $this->addError('batch', 'This file cannot be imported right now: it may already be running, or have nothing to change.');
        }
    }

    /** Throw the upload away and start over. */
    public function discard(ImportRunner $runner): void
    {
        $runner->cancel($this->currentBatch());
        $this->batchId = null;
        $this->file = null;
    }

    public function markStuck(ImportRunner $runner): void
    {
        if (! $runner->markStuckAsFailed($this->currentBatch())) {
            $this->addError('batch', 'It is still running. Give it a few more minutes.');
        }
    }

    public function newImport(): void
    {
        $this->batchId = null;
        $this->file = null;
        $this->mapping = [];
        $this->resetErrorBag();
    }

    public function openBatch(int $id): void
    {
        $batch = ImportBatch::query()->where('importer', $this->importerKey())->findOrFail($id);
        Gate::authorize('view', $batch);

        $this->batchId = $batch->id;
        $this->mapping = [];
        $this->resetErrorBag();
    }

    #[Computed]
    public function batch(): ?ImportBatch
    {
        if ($this->batchId === null) {
            return null;
        }

        $batch = ImportBatch::query()->with('uploader')->where('importer', $this->importerKey())->find($this->batchId);

        if ($batch !== null) {
            Gate::authorize('view', $batch);
        }

        return $batch;
    }

    public function render(): View
    {
        $this->authorizeImporter();

        $batch = $this->batch();
        $busy = $batch?->status->isBusy() ?? false;
        $headings = $this->syncMapping($batch);

        return view('shared.imports.wizard', [
            'importer' => $this->importer(),
            'tabsView' => $this->tabsView(),
            'optionsView' => $this->optionsView(),
            'optionsData' => $this->optionsData(),
            'headings' => $headings,
            'canMap' => $batch !== null && $headings !== [] && (
                $batch->status === ImportStatus::Checked
                || ($batch->status === ImportStatus::Failed && $batch->summaryValue('failed_during') !== 'apply')
            ),
            'batch' => $batch,
            'busy' => $busy,
            'stuck' => $busy && $batch->updated_at !== null && $batch->updated_at->lt(now()->subMinutes(30)),
            'canConfirm' => $batch !== null && (
                ($batch->status === ImportStatus::Checked && $batch->created_count + $batch->updated_count > 0)
                || ($batch->status === ImportStatus::Failed && $batch->summaryValue('failed_during') === 'apply')
            ),
            'rejected' => $batch !== null && $batch->error_count > 0 ? $batch->errors()->limit(50)->get() : collect(),
            'history' => ImportBatch::query()->with('uploader')->where('importer', $this->importerKey())->latest('id')->limit(8)->get(),
        ]);
    }

    /**
     * The headings of the open file, and — the first time they are shown — how each was read: what the check settled
     * on, or for a file whose check could not finish, the best guess from the importer's column names and aliases.
     *
     * @return list<array{text: string, slug: string}>
     */
    private function syncMapping(?ImportBatch $batch): array
    {
        $headings = array_values(array_filter((array) ($batch?->summaryValue('headings', []) ?? []), fn ($h) => is_array($h) && isset($h['text'], $h['slug'])));

        if ($batch === null || $headings === []) {
            return [];
        }

        $known = (array) $batch->summaryValue('mapping', []);
        $chosen = (array) $batch->summaryValue('column_map', []);

        foreach ($headings as $heading) {
            $slug = (string) $heading['slug'];

            if (array_key_exists($slug, $this->mapping)) {
                continue;
            }

            $guess = collect($this->importer()->columns())->first(fn ($column) => $column->matches((string) $heading['text']));
            $this->mapping[$slug] = (string) ($known[$slug] ?? $chosen[$slug] ?? $guess->key ?? '');
        }

        return $headings;
    }

    protected function importer(): Importer
    {
        return app(ImporterRegistry::class)->get($this->importerKey());
    }

    private function authorizeImporter(): void
    {
        Gate::authorize($this->importer()->permission());
    }

    /** The open batch, reloaded and authorised for changing it. */
    private function currentBatch(): ImportBatch
    {
        $this->authorizeImporter();

        $batch = ImportBatch::query()->where('importer', $this->importerKey())->findOrFail($this->batchId);
        Gate::authorize('manage', $batch);

        return $batch;
    }
}
