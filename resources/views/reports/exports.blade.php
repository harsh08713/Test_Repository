<div class="space-y-5" @if ($busy) wire:poll.4s @endif>
    <div class="max-w-3xl">
        <p class="text-sm"><a href="{{ route('reports.index') }}" wire:navigate class="text-brand-700 hover:underline">Reports</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">My exports</h1>
        <p class="mt-1 text-sm text-slate-500">Exports are made in the background as you, with the same access you have on screen. A finished file stays here for {{ \App\Modules\Reports\Services\ReportExporter::KEEP_DAYS }} days and can be downloaded only by you.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Report</th><th>Format</th><th>Asked</th><th>Status</th><th class="text-right">Rows</th><th class="text-right">Size</th><th></th></tr></thead>
                <tbody>
                    @forelse ($exports as $export)
                        <tr wire:key="exp-{{ $export->id }}">
                            <td class="font-medium text-slate-900">{{ $titles[$export->report] ?? $export->report }}</td>
                            <td class="uppercase text-slate-600">{{ $export->format }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $export->created_at->format('j M Y H:i') }}</td>
                            <td>
                                <span @class([
                                    'badge',
                                    'bg-emerald-100 text-emerald-800' => $export->status === 'ready',
                                    'bg-amber-100 text-amber-800' => in_array($export->status, ['queued', 'running'], true),
                                    'bg-rose-100 text-rose-800' => $export->status === 'failed',
                                    'bg-slate-100 text-slate-600' => $export->status === 'expired',
                                ])>{{ ['queued' => 'Waiting', 'running' => 'Being made', 'ready' => 'Ready', 'failed' => 'Failed', 'expired' => 'Expired'][$export->status] ?? $export->status }}</span>
                                @if ($export->error)<div class="mt-1 text-xs text-rose-700">{{ $export->error }}</div>@endif
                            </td>
                            <td class="text-right tabular-nums">{{ $export->row_count === null ? '—' : number_format($export->row_count) }}</td>
                            <td class="text-right tabular-nums text-slate-600">{{ $export->file_size === null ? '—' : number_format($export->file_size / 1024, 0).' KB' }}</td>
                            <td class="text-right">
                                @if ($export->isReady())
                                    <a href="{{ route('reports.exports.download', $export) }}" class="btn-secondary">Download</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">You have not asked for an export yet. Open a report and choose Export to Excel or PDF.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($exports->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $exports->links() }}</div>
        @endif
    </div>
</div>
