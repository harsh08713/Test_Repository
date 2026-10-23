<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Welcome back, {{ auth()->user()->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Here is where you stand today. What you see follows your role, your team and your branches.</p>
    </div>

    @if ($this->schedulerQuiet)
        <p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">The scheduler has not run for a while, so reminders, SLA checks and campaigns are not being started. <a href="{{ route('admin.health') }}" wire:navigate class="font-medium underline">See System health</a>.</p>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('approvals.index') }}" wire:navigate class="card card-body block transition hover:border-brand-500">
            <p class="text-sm font-medium text-slate-500">Waiting for your approval</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->pendingApprovals }}</p>
        </a>
        <div class="card card-body">
            <p class="text-sm font-medium text-slate-500">Branches you can access</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->locations->count() }}</p>
        </div>
        <div class="card card-body">
            <p class="text-sm font-medium text-slate-500">Unread notifications</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ auth()->user()->unreadNotifications()->count() }}</p>
        </div>
    </div>

    @canany(['lead.view', 'deal.view', 'sales_order.view', 'followup.view', 'quotation.view'])
        <livewire:reports.sales-dashboard />
    @endcanany

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Your branches</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>City</th></tr></thead>
                <tbody>
                    @forelse ($this->locations as $location)
                        <tr><td class="font-mono text-xs">{{ $location->code }}</td><td>{{ $location->name }}</td><td>{{ $location->city ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-slate-500">No branches are assigned to you yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
