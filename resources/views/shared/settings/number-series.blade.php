<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Number series</h1>
        <p class="mt-1 text-sm text-slate-500">Document numbers look like <span class="font-mono">QT/AHD/26-27/00001</span> — prefix, branch code, financial year (April–March) and a sequence that restarts every year. Counters are created automatically the first time a document is numbered.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Document</th><th>Branch</th><th>Year</th><th>Prefix</th><th>Padding</th><th>Last used</th><th>Next number</th><th></th></tr></thead>
                <tbody>
                    @forelse ($series as $row)
                        <tr wire:key="series-{{ $row->id }}">
                            @if ($editingId === $row->id)
                                <td>{{ $row->document_type->label() }}</td>
                                <td>{{ $row->location?->code }}</td>
                                <td>{{ $row->financial_year }}</td>
                                <td>
                                    <input type="text" wire:model="prefix" class="input w-24 uppercase" maxlength="10" aria-label="Prefix">
                                    @error('prefix') <p class="error">{{ $message }}</p> @enderror
                                </td>
                                <td>
                                    <input type="number" wire:model="padding" min="3" max="8" class="input w-20" aria-label="Padding">
                                    @error('padding') <p class="error">{{ $message }}</p> @enderror
                                </td>
                                <td class="font-mono">{{ $row->last_number }}</td>
                                <td>
                                    <input type="number" wire:model="nextNumber" min="{{ $row->last_number + 1 }}" class="input w-28" aria-label="Next number">
                                    @error('nextNumber') <p class="error max-w-xs">{{ $message }}</p> @enderror
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="cancel" class="btn-ghost btn-sm">Cancel</button>
                                    <button type="button" wire:click="save" class="btn-primary btn-sm">Save</button>
                                </td>
                            @else
                                <td class="font-medium text-slate-900">{{ $row->document_type->label() }}</td>
                                <td>{{ $row->location?->code }} <span class="text-slate-400">· {{ $row->location?->name }}</span></td>
                                <td>{{ $row->financial_year }}</td>
                                <td class="font-mono">{{ $row->prefix }}</td>
                                <td>{{ $row->padding }}</td>
                                <td class="font-mono">{{ $row->last_number }}</td>
                                <td class="font-mono text-slate-500">{{ $row->format($row->last_number + 1, $row->location?->code ?? '') }}</td>
                                <td class="text-right">@if ($canEdit)<button type="button" wire:click="edit({{ $row->id }})" class="btn-ghost btn-sm">Edit</button>@endif</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No counters yet — they appear when the first numbered document is created.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($canEdit)
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Add a counter</h2></div>
            <form wire:submit="add" class="card-body grid items-end gap-4 sm:grid-cols-5">
                <div>
                    <label class="label" for="new-type">Document</label>
                    <select id="new-type" wire:model="newType" class="input">
                        <option value="">Select…</option>
                        @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                    </select>
                    @error('newType') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="new-location">Branch</label>
                    <select id="new-location" wire:model="newLocation" class="input">
                        <option value="">Select…</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->code }} · {{ $location->name }}</option>@endforeach
                    </select>
                    @error('newLocation') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="new-fy">Financial year</label>
                    <input id="new-fy" type="text" wire:model="newFinancialYear" class="input font-mono" placeholder="26-27">
                    @error('newFinancialYear') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="new-start">Start at</label>
                    <input id="new-start" type="number" min="1" wire:model="newStart" class="input">
                    @error('newStart') <p class="error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary">Add counter</button>
            </form>
        </div>
    @endif
</div>
