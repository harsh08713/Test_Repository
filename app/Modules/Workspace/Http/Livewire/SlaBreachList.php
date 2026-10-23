<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Models\Approval;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\SlaClockService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The log of every time a clock ran out, and the clocks still running (which a manager can pause while the customer is the one being waited
 * for) — for the people the signed-in person manages, their own for a salesperson, everyone's for `sales.scope_all`. Breaches are evidence:
 * they are listed as they were recorded and never edited. Opening it needs the right to see SLA policies, or to work leads, follow-ups or tasks
 * (then it shows only that person's own).
 */
#[Title('SLA breaches')]
class SlaBreachList extends Component
{
    use WithPagination;

    /** log | running */
    #[Url(as: 'tab')]
    public string $tab = 'log';

    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'person')]
    public string $person = '';

    /** The clock whose pause box is open (0 = none). */
    public int $pausing = 0;

    public string $reason = '';

    public function mount(): void
    {
        $user = $this->actor();

        abort_unless($user->can('sla_policy.view') || $user->can('task.view') || $user->can('lead.view') || $user->can('followup.view'), 403);

        if ($this->from === '') {
            $this->from = now()->subDays(30)->toDateString();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'kind', 'from', 'to', 'person'], true)) {
            $this->resetPage();
        }
    }

    public function openPause(int $id): void
    {
        $this->clockOf($id);
        abort_unless($this->actor()->can('sla.pause'), 403);

        $this->pausing = $id;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closePause(): void
    {
        $this->pausing = 0;
        $this->resetErrorBag();
    }

    public function pauseClock(SlaClockService $service): void
    {
        $clock = $this->clockOf($this->pausing);
        abort_unless($this->actor()->can('sla.pause'), 403);
        $this->resetErrorBag();

        try {
            $service->pause($clock, $this->reason, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('clock', $e->getMessage());
            $this->pausing = 0;

            return;
        }

        $this->pausing = 0;
        session()->flash('status', 'The clock is paused. It cannot run out until it is resumed.');
    }

    public function resumeClock(int $id, SlaClockService $service): void
    {
        $clock = $this->clockOf($id);
        abort_unless($this->actor()->can('sla.pause'), 403);

        try {
            $service->resume($clock, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('clock', $e->getMessage());

            return;
        }

        session()->flash('status', 'The clock is running again, with its deadline moved on by the time it was paused.');
    }

    public function render(SalesScope $scope): View
    {
        $user = $this->actor();
        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');
        $visible = $scope->visibleUserIds($user);
        $people = ReportInput::people($user);
        $personOk = ctype_digit($this->person) && array_key_exists((int) $this->person, $people);

        $breaches = fn (): Builder => SlaBreach::query()
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('owner_id', $visible))
            ->when(SlaKind::tryFrom($this->kind) !== null, fn (Builder $q) => $q->where('kind', $this->kind))
            ->when($valid($this->from), fn (Builder $q) => $q->where('detected_at', '>=', $this->from.' 00:00:00'))
            ->when($valid($this->to), fn (Builder $q) => $q->where('detected_at', '<=', $this->to.' 23:59:59'))
            ->when($personOk, fn (Builder $q) => $q->where('owner_id', (int) $this->person));

        $running = fn (): Builder => SlaClock::query()->where('state', 'running')
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('owner_id', $visible))
            ->when(SlaKind::tryFrom($this->kind) !== null, fn (Builder $q) => $q->where('kind', $this->kind))
            ->when($personOk, fn (Builder $q) => $q->where('owner_id', (int) $this->person));

        $page = $this->tab === 'running'
            ? $running()->with(['owner:id,name', 'policy:id,name'])->orderByRaw('breached_at is null')->orderBy('due_at')->orderBy('id')->paginate(20)
            : $breaches()->with(['owner:id,name', 'escalatedTo:id,name'])->orderByDesc('detected_at')->orderByDesc('id')->paginate(20);

        return view('workspace.sla.breaches', [
            'tab' => $this->tab === 'running' ? 'running' : 'log',
            'rows' => $page,
            'labels' => $this->labels($page->getCollection()->all()),
            'total' => (clone $breaches())->count(),
            'late' => (clone $breaches())->where('answered_late', true)->count(),
            'runningCount' => (clone $running())->count(),
            'runningLate' => (clone $running())->whereNotNull('breached_at')->count(),
            'paused' => (clone $running())->whereNotNull('paused_at')->count(),
            'kinds' => SlaKind::options(),
            'people' => $people,
            'canPause' => $user->can('sla.pause'),
        ]);
    }

    /**
     * What each row was about, in a few queries.
     *
     * @param  list<SlaBreach|SlaClock>  $rows
     * @return array<int, array{label: string, url: string}> row id => label and link
     */
    private function labels(array $rows): array
    {
        $ids = fn (string $type) => collect($rows)->where('subject_type', $type)->pluck('subject_id')->unique()->all();

        $leads = Lead::query()->whereIn('id', $ids('lead'))->get()->keyBy('id');
        $followups = Followup::query()->whereIn('id', $ids('followup'))->get()->keyBy('id');
        $followLeads = Lead::query()->whereIn('id', $followups->pluck('subject_id')->all())->get()->keyBy('id');
        $tasks = Task::query()->whereIn('id', $ids('task'))->get()->keyBy('id');
        $quotations = Quotation::query()->whereIn('id', $ids('quotation'))->get()->keyBy('id');
        $approvals = Approval::query()->whereIn('id', $ids('approval'))->with('approvable')->get()->keyBy('id');

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->subject_id;
            $found = match ($row->subject_type) {
                'lead' => isset($leads[$id]) ? ['label' => $leads[$id]->title(), 'url' => route('crm.leads.show', $id)] : null,
                'followup' => isset($followups[$id], $followLeads[$followups[$id]->subject_id])
                    ? ['label' => $followups[$id]->type->label().' with '.$followLeads[$followups[$id]->subject_id]->title(), 'url' => route('crm.leads.show', $followups[$id]->subject_id)] : null,
                'task' => isset($tasks[$id]) ? ['label' => $tasks[$id]->title, 'url' => route('workspace.tasks.show', $id)] : null,
                'quotation' => isset($quotations[$id]) ? ['label' => $quotations[$id]->quotation_number.' · '.$quotations[$id]->party_name, 'url' => route('sales.quotations.show', $id)] : null,
                'approval' => isset($approvals[$id]) && $approvals[$id]->approvable instanceof ApprovableDocument
                    ? ['label' => $approvals[$id]->approvable->approvalTitle(), 'url' => (string) $approvals[$id]->approvable->approvalUrl()] : null,
                default => null,
            };

            $out[$row->getKey()] = $found ?? ['label' => 'A record you can no longer open', 'url' => ''];
        }

        return $out;
    }

    /** A running clock of someone this person may see — anything else is not found. */
    private function clockOf(int $id): SlaClock
    {
        $visible = app(SalesScope::class)->visibleUserIds($this->actor());

        return SlaClock::query()->where('state', 'running')->when($visible !== null, fn (Builder $q) => $q->whereIn('owner_id', $visible))->findOrFail($id);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
