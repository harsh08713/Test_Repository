<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Models\Holiday;
use App\Modules\Workspace\Models\SlaPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The service-level rules and the company's holidays. A policy says how long a kind of thing may wait; it can be switched off (kept, never
 * deleted: the clocks and breaches it produced point at it). Working hours and days are company settings (“SLA and tasks”).
 */
#[Title('SLA policies')]
class SlaSettings extends Component
{
    /** @var array<string, mixed> */
    public array $p = [];

    #[Locked]
    public int $editingId = 0;

    public bool $formOpen = false;

    /** @var array<string, string> */
    public array $h = ['holiday_date' => '', 'name' => ''];

    public function mount(): void
    {
        Gate::authorize('viewAny', SlaPolicy::class);
    }

    public function newPolicy(): void
    {
        Gate::authorize('create', SlaPolicy::class);

        $this->p = ['name' => '', 'kind' => 'first_response', 'lead_source_id' => '', 'sales_team_id' => '', 'priority' => '', 'target_minutes' => '60', 'business_hours' => true, 'escalate_to_manager' => true];
        $this->editingId = 0;
        $this->formOpen = true;
        $this->resetErrorBag();
    }

    public function editPolicy(int $id): void
    {
        $policy = SlaPolicy::query()->findOrFail($id);
        Gate::authorize('update', $policy);

        $this->p = [
            'name' => $policy->name, 'kind' => $policy->kind->value, 'lead_source_id' => (string) ($policy->lead_source_id ?? ''), 'sales_team_id' => (string) ($policy->sales_team_id ?? ''),
            'priority' => (string) ($policy->priority ?? ''), 'target_minutes' => (string) $policy->target_minutes, 'business_hours' => $policy->business_hours, 'escalate_to_manager' => $policy->escalate_to_manager,
        ];
        $this->editingId = $id;
        $this->formOpen = true;
        $this->resetErrorBag();
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetErrorBag();
    }

    public function savePolicy(): void
    {
        $policy = $this->editingId === 0 ? new SlaPolicy : SlaPolicy::query()->findOrFail($this->editingId);
        Gate::authorize($this->editingId === 0 ? 'create' : 'update', $this->editingId === 0 ? SlaPolicy::class : $policy);
        $this->resetErrorBag();

        $kind = (string) ($this->p['kind'] ?? '');
        $input = [
            'name' => trim((string) ($this->p['name'] ?? '')), 'kind' => $kind,
            'lead_source_id' => trim((string) ($this->p['lead_source_id'] ?? '')), 'sales_team_id' => trim((string) ($this->p['sales_team_id'] ?? '')), 'priority' => trim((string) ($this->p['priority'] ?? '')),
            'target_minutes' => trim((string) ($this->p['target_minutes'] ?? '')),
        ];

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(SlaKind::options()))],
            'lead_source_id' => ['nullable', 'integer'],
            'sales_team_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(array_keys(TaskPriority::options()))],
            'target_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
        ], ['name.required' => 'Give the policy a name.', 'target_minutes.required' => 'Say how many minutes it may wait.'])->validate();

        if ($data['lead_source_id'] !== '' && (! SlaKind::from($kind)->usesSource() || ! LeadSource::query()->whereKey((int) $data['lead_source_id'])->exists())) {
            throw ValidationException::withMessages(['lead_source_id' => 'Choose one of the company’s lead sources (leads and follow-ups only).']);
        }

        if ($data['sales_team_id'] !== '' && ! SalesTeam::query()->whereKey((int) $data['sales_team_id'])->exists()) {
            throw ValidationException::withMessages(['sales_team_id' => 'Choose one of the company’s teams.']);
        }

        if ($data['priority'] !== '' && $kind !== SlaKind::Task->value) {
            throw ValidationException::withMessages(['priority' => 'Priority applies to task policies only.']);
        }

        $policy->fill([
            'name' => $data['name'], 'kind' => $data['kind'], 'target_minutes' => (int) $data['target_minutes'], 'priority' => $data['priority'] === '' ? null : $data['priority'],
            'business_hours' => (bool) ($this->p['business_hours'] ?? false), 'escalate_to_manager' => (bool) ($this->p['escalate_to_manager'] ?? false),
        ]);
        $policy->forceFill([
            'lead_source_id' => $data['lead_source_id'] === '' ? null : (int) $data['lead_source_id'], 'sales_team_id' => $data['sales_team_id'] === '' ? null : (int) $data['sales_team_id'],
        ])->save();

        $this->formOpen = false;
        session()->flash('status', $this->editingId === 0 ? 'Policy added. It applies to things made from now on.' : 'Policy saved.');
        $this->editingId = 0;
    }

    public function togglePolicy(int $id): void
    {
        $policy = SlaPolicy::query()->findOrFail($id);
        Gate::authorize('update', $policy);

        $policy->forceFill(['status' => $policy->status === 'active' ? 'inactive' : 'active'])->save();
        session()->flash('status', $policy->status === 'active' ? 'Policy switched on.' : 'Policy switched off. Clocks already running keep their time.');
    }

    public function addHoliday(): void
    {
        Gate::authorize('create', Holiday::class);
        $this->resetErrorBag();

        $data = Validator::make(['holiday_date' => trim($this->h['holiday_date']), 'name' => trim($this->h['name'])], [
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
        ], ['holiday_date.required' => 'Choose the day.', 'name.required' => 'Say what the day is.'])->validate();

        if (Holiday::query()->whereDate('holiday_date', $data['holiday_date'])->exists()) {
            throw ValidationException::withMessages(['holiday_date' => 'That day is already a holiday.']);
        }

        $holiday = new Holiday;
        $holiday->fill($data)->save();

        $this->h = ['holiday_date' => '', 'name' => ''];
        session()->flash('status', 'Holiday added.');
    }

    public function removeHoliday(int $id): void
    {
        $holiday = Holiday::query()->findOrFail($id);
        Gate::authorize('delete', $holiday);

        $holiday->forceDelete();
        session()->flash('status', 'Holiday removed.');
    }

    public function render(): View
    {
        return view('workspace.sla.settings', [
            'policies' => SlaPolicy::query()->with(['source:id,name', 'team:id,name'])->orderBy('kind')->orderBy('name')->get(),
            'holidays' => Holiday::query()->where('holiday_date', '>=', now()->subMonths(1)->toDateString())->orderBy('holiday_date')->get(),
            'sources' => LeadSource::query()->orderBy('name')->pluck('name', 'id')->all(),
            'teams' => SalesTeam::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'kinds' => SlaKind::options(),
            'priorities' => TaskPriority::options(),
            'canCreate' => Gate::allows('create', SlaPolicy::class),
            'canEdit' => (bool) auth()->user()?->can('sla_policy.edit'),
        ]);
    }
}
