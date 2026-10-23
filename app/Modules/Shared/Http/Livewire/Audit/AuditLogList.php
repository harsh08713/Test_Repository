<?php

namespace App\Modules\Shared\Http\Livewire\Audit;

use App\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Read-only audit trail for the active company: who did what, with old â†’ new values. */
#[Title('Audit log')]
class AuditLogList extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $log = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        Gate::authorize('audit_log.view');
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function render(): View
    {
        $entries = ActivityLog::query()
            ->with('causer')
            ->when($this->log !== '', fn ($q) => $q->where('log_name', $this->log))
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->user !== '', fn ($q) => $q->where('causer_id', $this->user)->where('causer_type', (new User)->getMorphClass()))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id')
            ->paginate(25);

        return view('shared.audit.index', [
            'entries' => $entries,
            'logNames' => ActivityLog::query()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
            'events' => ActivityLog::query()->distinct()->orderBy('event')->pluck('event')->filter()->values(),
            'users' => User::query()->managedBy(auth()->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
