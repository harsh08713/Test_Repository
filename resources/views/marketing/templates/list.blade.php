@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Message templates</h1>
            <p class="mt-1 text-sm text-slate-500">The words of a message, with variables like <code class="rounded bg-slate-100 px-1">&#123;&#123;first_name&#125;&#125;</code>. A template has to be approved before anything is sent with it, and what the approver read is exactly what goes out.</p>
        </div>
        @if ($canCreate)<a href="{{ route('marketing.templates.create') }}" wire:navigate class="btn-primary">New template</a>@endif
    </div>

    <x-status />
    @error('list')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div><label for="t-q" class="block text-xs text-slate-500">Search</label><input id="t-q" type="search" wire:model.live.debounce.300ms="search" class="input w-52" placeholder="Name…"></div>
                <div><label for="t-ch" class="block text-xs text-slate-500">Channel</label>
                    <select id="t-ch" wire:model.live="channel" class="input w-40"><option value="">All</option>@foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="t-ap" class="block text-xs text-slate-500">Approval</label>
                    <select id="t-ap" wire:model.live="approval" class="input w-44"><option value="">Any</option><option value="draft">Draft</option><option value="pending">Waiting</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Template</th><th>Channel</th><th>Purpose</th><th>Approval</th><th>In use</th><th>Changed</th><th></th></tr></thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr wire:key="tpl-{{ $template->id }}">
                            <td><a href="{{ route('marketing.templates.edit', $template) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $template->name }}</a><div class="max-w-md truncate text-xs text-slate-500">{{ $template->subject ?? \Illuminate\Support\Str::limit($template->body, 80) }}</div></td>
                            <td>{{ $template->channel->label() }}</td>
                            <td class="text-slate-600">{{ $template->purpose === 'marketing' ? 'Marketing' : 'About something they asked for' }}</td>
                            <td><span class="badge {{ $template->approval_status->badge() }}">{{ $template->approval_status->label() }}</span></td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $template->status === 'active', 'bg-slate-100 text-slate-600' => $template->status !== 'active'])>{{ $template->status === 'active' ? 'On' : 'Off' }}</span></td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ Format::date($template->updated_at) }}</td>
                            <td class="text-right">@can('switch', $template)<button type="button" wire:click="toggle({{ $template->id }})" class="btn-secondary">{{ $template->status === 'active' ? 'Switch off' : 'Switch on' }}</button>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No template yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($templates->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $templates->links() }}</div>@endif
    </div>
</div>
