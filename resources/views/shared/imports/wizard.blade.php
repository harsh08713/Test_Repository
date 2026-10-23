@php
    use App\Modules\Shared\Enums\ImportStatus;

    $statusStyle = fn (ImportStatus $s) => match ($s) {
        ImportStatus::Completed => 'bg-emerald-100 text-emerald-800',
        ImportStatus::Failed => 'bg-rose-100 text-rose-800',
        ImportStatus::Checked => 'bg-sky-100 text-sky-800',
        ImportStatus::Checking, ImportStatus::Queued, ImportStatus::Processing => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $rowStyle = fn (string $s) => match ($s) {
        'created' => 'bg-emerald-100 text-emerald-800',
        'updated' => 'bg-sky-100 text-sky-800',
        'error' => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $counterLabels = $importer->counterLabels();
    $counters = $batch?->summaryValue('counters', []) ?? [];
    $preview = $batch?->summaryValue('preview', []) ?? [];
    $ignored = $batch?->summaryValue('ignored_columns', []) ?? [];
    $isCheck = $batch && in_array($batch->status, [ImportStatus::Uploaded, ImportStatus::Checking, ImportStatus::Checked], true);
    $failedDuringApply = $batch?->status === ImportStatus::Failed && $batch->summaryValue('failed_during') === 'apply';
@endphp

<div class="space-y-5" @if ($busy) wire:poll.2s @endif>
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $importer->label() }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $importer->description() }}</p>
    </div>

    @if ($tabsView) @include($tabsView) @endif

    @error('batch')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    @if ($batch === null)
        {{-- Step 1 + 2: template and upload --}}
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="card" aria-labelledby="imp-step1">
                <div class="card-header"><h2 id="imp-step1" class="text-sm font-semibold text-slate-900">1. Get the template</h2></div>
                <div class="card-body space-y-3 text-sm text-slate-600">
                    <p>The workbook has a <strong>Data</strong> sheet with the headings, and an <strong>Instructions</strong> sheet explaining each column with examples.</p>
                    <a href="{{ route('imports.template', $importer->key()) }}" class="btn-secondary"><x-icon name="upload" class="size-4 rotate-180" /> Download template (.xlsx)</a>
                    <details class="rounded-lg bg-slate-50 px-3 py-2">
                        <summary class="cursor-pointer text-sm font-medium text-slate-700">Columns and rules</summary>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($importer->columns() as $column)
                                <li><strong>{{ $column->heading }}</strong>@if ($column->required) <span class="text-rose-600">(required)</span>@endif: {{ $column->description }}</li>
                            @endforeach
                        </ul>
                        <ul class="mt-3 list-disc space-y-1 pl-5">
                            @foreach ($importer->rules() as $rule)<li>{{ $rule }}</li>@endforeach
                        </ul>
                    </details>
                </div>
            </section>

            <section class="card" aria-labelledby="imp-step2">
                <div class="card-header"><h2 id="imp-step2" class="text-sm font-semibold text-slate-900">2. Upload your file</h2></div>
                <form wire:submit="uploadAndCheck" class="card-body space-y-3" novalidate>
                    <div>
                        <label for="imp-file" class="label">Filled-in template (.xlsx) or CSV</label>
                        <input id="imp-file" type="file" wire:model="file" accept=".xlsx,.csv" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                        <p class="help">Up to {{ round(config('formezy.imports.max_file_kb') / 1024) }} MB and {{ number_format(config('formezy.imports.max_rows')) }} rows. For very large files, CSV is much faster.</p>
                        @error('file') <p class="error">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="file" class="help">Uploading…</div>
                    </div>
                    @if ($optionsView) @include($optionsView, $optionsData) @endif
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploadAndCheck,file">Upload and check</button>
                    <p class="help">Nothing is saved yet. The check only reports what would happen.</p>
                </form>
            </section>
        </div>
    @else
        {{-- The open batch --}}
        <section class="card" aria-labelledby="imp-batch">
            <div class="card-header flex-wrap">
                <div class="min-w-0">
                    <h2 id="imp-batch" class="truncate text-sm font-semibold text-slate-900">{{ $batch->original_filename }}</h2>
                    <p class="text-xs text-slate-500">Uploaded {{ $batch->created_at?->format(config('formezy.display.datetime')) }}@if ($batch->uploader) by {{ $batch->uploader->name }}@endif</p>
                </div>
                <span class="badge {{ $statusStyle($batch->status) }}">{{ $batch->status->label() }}</span>
            </div>

            <div class="card-body space-y-5">
                {{-- progress while a job is working --}}
                @if ($busy)
                    <div role="status" aria-live="polite">
                        <p class="text-sm text-slate-700">
                            @if ($batch->status === ImportStatus::Checking) Checking the file… @elseif ($batch->status === ImportStatus::Queued) Waiting for the import to start… @else Importing… @endif
                            <span class="text-slate-500">{{ number_format($batch->processed_rows) }} of about {{ number_format($batch->total_rows) }} rows</span>
                        </p>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="h-full rounded-full bg-brand-600 transition-all" style="width: {{ $batch->progress() }}%"></div>
                        </div>
                        @if ($stuck)
                            <p class="mt-3 text-sm text-amber-700">This has not moved for a while. If the queue worker has stopped, you can mark it failed and retry.</p>
                            <button type="button" wire:click="markStuck" class="btn-secondary btn-sm mt-2">Mark as failed</button>
                        @endif
                    </div>
                @endif

                {{-- failure --}}
                @if ($batch->status === ImportStatus::Failed)
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">
                        <p class="font-medium">{{ $failedDuringApply ? 'The import stopped.' : 'The file could not be checked.' }}</p>
                        <p class="mt-1">{{ $batch->failure_reason }}</p>
                        @if ($failedDuringApply)<p class="mt-1">Rows imported before the stop are kept. Retrying is safe: it picks up where it left off without duplicating anything.</p>@endif
                    </div>
                @endif

                {{-- counts --}}
                @if (in_array($batch->status, [ImportStatus::Checked, ImportStatus::Completed, ImportStatus::Failed], true) && $batch->processed_rows > 0)
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">{{ $batch->status === ImportStatus::Completed ? 'Result' : ($isCheck ? 'What the check found' : 'So far') }}</h3>
                        <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-5">
                            @foreach ([['Rows read', $batch->total_rows, 'text-slate-900'], ['New', $batch->created_count, 'text-emerald-700'], ['Updated', $batch->updated_count, 'text-sky-700'], ['No change', $batch->unchanged_count, 'text-slate-600'], ['Rejected', $batch->error_count, $batch->error_count ? 'text-rose-700' : 'text-slate-600']] as [$label, $value, $tone])
                                <div class="rounded-lg border border-slate-200 px-3 py-2">
                                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                                    <dd class="text-xl font-semibold {{ $tone }}">{{ number_format($value) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($counters !== [])
                            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                                @foreach ($counterLabels as $key => $label)
                                    @if (($counters[$key] ?? 0) > 0)<li>{{ $label }}: <strong class="text-slate-900">{{ number_format($counters[$key]) }}</strong></li>@endif
                                @endforeach
                            </ul>
                        @endif
                        @if ($ignored !== [])
                            <p class="mt-3 text-xs text-slate-500">Columns not used by this import and ignored: {{ implode(', ', $ignored) }}.</p>
                        @endif
                    </div>
                @endif

                {{-- confirm --}}
                @if ($batch->status === ImportStatus::Checked)
                    @if ($canConfirm)
                        <div class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                            Importing will add {{ number_format($batch->created_count) }} and update {{ number_format($batch->updated_count) }} record(s).
                            @if ($batch->error_count > 0)
                                The {{ number_format($batch->error_count) }} rejected row(s) will be <strong>skipped</strong>; download the error report to fix them and upload again afterwards.
                            @endif
                        </div>
                    @else
                        <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            @if ($batch->error_count > 0 && $batch->valid_rows === 0) Every row was rejected, so there is nothing to import. Fix the file and upload it again.
                            @else Everything in this file is already in the system, so there is nothing to import. @endif
                        </div>
                    @endif
                @endif

                {{-- completion --}}
                @if ($batch->status === ImportStatus::Completed)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900" role="status">
                        Import finished @if ($batch->finished_at) at {{ $batch->finished_at->format(config('formezy.display.datetime')) }}@endif.
                        @if ($batch->error_count > 0) {{ number_format($batch->error_count) }} row(s) were rejected and skipped. @endif
                    </div>
                @endif

                {{-- actions --}}
                <div class="flex flex-wrap gap-2">
                    @if ($canConfirm)
                        <button type="button" wire:click="confirmImport" wire:confirm="Import {{ number_format($batch->created_count + $batch->updated_count) }} record change(s) now?" class="btn-primary" wire:loading.attr="disabled">
                            {{ $failedDuringApply ? 'Retry import' : 'Import '.number_format($batch->valid_rows - $batch->unchanged_count).' valid row(s)' }}
                        </button>
                    @endif
                    @if ($batch->status === ImportStatus::Failed && ! $failedDuringApply)
                        <button type="button" wire:click="recheck" class="btn-primary">Check again</button>
                    @elseif ($batch->status === ImportStatus::Checked)
                        <button type="button" wire:click="recheck" class="btn-secondary">Check again</button>
                    @endif
                    @if ($batch->error_count > 0)
                        <a href="{{ route('imports.errors', $batch->id) }}" class="btn-secondary">Download error report (.xlsx)</a>
                    @endif
                    @if (in_array($batch->status, [ImportStatus::Completed, ImportStatus::Cancelled], true))
                        <button type="button" wire:click="newImport" class="btn-primary">Import another file</button>
                    @elseif (! $busy)
                        <button type="button" wire:click="discard" wire:confirm="Discard this upload?" class="btn-ghost text-rose-600">Discard</button>
                    @endif
                </div>
            </div>
        </section>

        {{-- column mapping: for a file whose headings are not the template's --}}
        @if ($canMap)
            <section class="card" aria-labelledby="imp-map">
                <div class="card-header">
                    <h2 id="imp-map" class="text-sm font-semibold text-slate-900">Columns in your file</h2>
                    <span class="text-xs text-slate-500">Change how a column is read, then check again</span>
                </div>
                <form wire:submit="applyMapping" class="card-body space-y-3" novalidate>
                    @error('mapping') <p class="error" role="alert">{{ $message }}</p> @enderror
                    <div class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach ($headings as $heading)
                            <div class="flex items-center gap-3" wire:key="map-{{ $heading['slug'] }}">
                                <label for="map-{{ $heading['slug'] }}" class="w-1/2 truncate text-sm text-slate-700" title="{{ $heading['text'] }}">{{ $heading['text'] }}</label>
                                <select id="map-{{ $heading['slug'] }}" wire:model="mapping.{{ $heading['slug'] }}" class="input w-1/2">
                                    <option value="">Ignore this column</option>
                                    @foreach ($importer->columns() as $column)
                                        <option value="{{ $column->key }}">{{ $column->heading }}{{ $column->required ? ' (required)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                    <button type="submit" class="btn-secondary" wire:loading.attr="disabled">Check again with these columns</button>
                </form>
            </section>
        @endif

        {{-- preview --}}
        @if ($preview !== [] && $isCheck)
            <section class="card" aria-labelledby="imp-preview">
                <div class="card-header"><h2 id="imp-preview" class="text-sm font-semibold text-slate-900">Preview: first {{ count($preview) }} rows</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Row</th><th>Result</th><th>What will happen</th></tr></thead>
                        <tbody>
                            @foreach ($preview as $line)
                                <tr wire:key="pv-{{ $line['row'] }}">
                                    <td class="tabular-nums">{{ $line['row'] }}</td>
                                    <td><span class="badge {{ $rowStyle($line['status']) }}">{{ \App\Modules\Shared\Imports\RowStatus::from($line['status'])->label() }}</span></td>
                                    <td>{{ $line['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- rejected rows --}}
        @if ($rejected->isNotEmpty())
            <section class="card" aria-labelledby="imp-errors">
                <div class="card-header">
                    <h2 id="imp-errors" class="text-sm font-semibold text-slate-900">Rejected rows @if ($batch->error_count > $rejected->count()) (first {{ $rejected->count() }} of {{ number_format($batch->error_count) }}) @endif</h2>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Row</th><th>Problem</th><th>Cells as uploaded</th></tr></thead>
                        <tbody>
                            @foreach ($rejected as $error)
                                <tr wire:key="er-{{ $error->id }}">
                                    <td class="tabular-nums">{{ $error->row_number }}</td>
                                    <td class="text-rose-700">{{ implode(' ', $error->messages) }}</td>
                                    <td class="text-xs text-slate-500">{{ collect($error->raw)->filter(fn ($v) => $v !== '')->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    {{-- history --}}
    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="imp-history">
            <div class="card-header"><h2 id="imp-history" class="text-sm font-semibold text-slate-900">Recent imports</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>File</th><th>Uploaded</th><th>By</th><th>Status</th><th class="text-right">New / updated / rejected</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($history as $past)
                            <tr wire:key="h-{{ $past->id }}">
                                <td class="max-w-xs truncate">{{ $past->original_filename }}</td>
                                <td class="whitespace-nowrap">{{ $past->created_at?->format(config('formezy.display.datetime')) }}</td>
                                <td>{{ $past->uploader?->name }}</td>
                                <td><span class="badge {{ $statusStyle($past->status) }}">{{ $past->status->label() }}</span></td>
                                <td class="text-right tabular-nums">{{ number_format($past->created_count) }} / {{ number_format($past->updated_count) }} / {{ number_format($past->error_count) }}</td>
                                <td class="text-right"><button type="button" wire:click="openBatch({{ $past->id }})" class="btn-ghost btn-sm">View</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
