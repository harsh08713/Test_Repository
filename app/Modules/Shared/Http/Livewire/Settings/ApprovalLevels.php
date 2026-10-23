<?php

namespace App\Modules\Shared\Http\Livewire\Settings;

use App\Models\User;
use App\Modules\Shared\Enums\ApprovalMode;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Configure the approval chain per document type. No levels = a single implicit level that anyone
 * holding `{form}.approve` can decide. Each level is decided by a role or a named user, either in order
 * (default) or all at once, optionally with a decision deadline.
 *
 * Changing the chain is a structural change, so it needs `setting.configure` (viewing needs `setting.view`).
 */
#[Title('Approval levels')]
class ApprovalLevels extends Component
{
    public string $documentType = 'ledger';

    public string $mode = 'sequential';

    /** @var list<array{name: string, approver_role: string, approver_user_id: string|int|null, due_hours: string|int|null}> */
    public array $levels = [];

    public function mount(): void
    {
        Gate::authorize('setting.view');
        $this->load();
    }

    public function updatedDocumentType(): void
    {
        $this->documentType = DocumentType::from($this->documentType)->value;
        $this->load();
    }

    private function load(): void
    {
        $type = DocumentType::from($this->documentType);

        $this->mode = (string) app(SettingsService::class)->get(ApprovalMode::settingKey($type));

        $this->levels = ApprovalLevel::query()
            ->where('document_type', $type->value)
            ->orderBy('level')
            ->get()
            ->map(fn (ApprovalLevel $l) => [
                'name' => (string) $l->name,
                'approver_role' => (string) $l->approver_role,
                'approver_user_id' => $l->approver_user_id ? (string) $l->approver_user_id : '',
                'due_hours' => $l->due_hours ? (string) $l->due_hours : '',
            ])->all();
    }

    public function addLevel(): void
    {
        Gate::authorize('setting.configure');

        $this->levels[] = ['name' => '', 'approver_role' => '', 'approver_user_id' => '', 'due_hours' => ''];
    }

    public function removeLevel(int $index): void
    {
        Gate::authorize('setting.configure');

        unset($this->levels[$index]);
        $this->levels = array_values($this->levels);
    }

    public function save(TenantContext $tenant, SettingsService $settings): void
    {
        Gate::authorize('setting.configure');

        $type = DocumentType::from($this->documentType);

        $this->validate([
            'mode' => ['required', Rule::in(array_map(fn (ApprovalMode $m) => $m->value, ApprovalMode::cases()))],
            'levels' => ['array', 'max:10'],
            'levels.*.name' => ['nullable', 'string', 'max:80'],
            'levels.*.approver_role' => ['nullable', 'string', 'exists:roles,name'],
            'levels.*.approver_user_id' => ['nullable', 'integer'],
            'levels.*.due_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $companyId = $tenant->activeCompanyId();
        $companyUsers = $this->companyUsers()->pluck('id');

        foreach ($this->levels as $i => $level) {
            $hasRole = filled($level['approver_role']);
            $hasUser = filled($level['approver_user_id']);

            if ($hasRole === $hasUser) {
                throw ValidationException::withMessages(["levels.$i.approver_role" => 'Choose either a role or a specific user for each level.']);
            }

            if ($hasUser && ! $companyUsers->contains((int) $level['approver_user_id'])) {
                throw ValidationException::withMessages(["levels.$i.approver_user_id" => 'That user does not belong to this company.']);
            }
        }

        $previousMode = (string) $settings->get(ApprovalMode::settingKey($type));

        DB::transaction(function () use ($type, $companyId, $settings) {
            // Removed one by one so each deletion is audited. In-flight approvals keep their own copy of the approver.
            ApprovalLevel::query()->where('document_type', $type->value)->get()->each->delete();

            foreach ($this->levels as $i => $level) {
                ApprovalLevel::create([
                    'company_id' => $companyId,
                    'document_type' => $type->value,
                    'level' => $i + 1,
                    'name' => filled($level['name']) ? $level['name'] : null,
                    'approver_role' => filled($level['approver_role']) ? $level['approver_role'] : null,
                    'approver_user_id' => filled($level['approver_user_id']) ? (int) $level['approver_user_id'] : null,
                    'due_hours' => filled($level['due_hours']) ? (int) $level['due_hours'] : null,
                ]);
            }

            $settings->set(ApprovalMode::settingKey($type), $this->mode);
        });

        if ($previousMode !== $this->mode) {
            activity('settings')->causedBy(Auth::user())->event('updated')
                ->withProperties([
                    'old' => [ApprovalMode::settingKey($type) => $previousMode],
                    'attributes' => [ApprovalMode::settingKey($type) => $this->mode],
                ])
                ->log('updated');
        }

        session()->flash('status', "Approval levels for {$type->label()} saved.");
    }

    /** @return Collection<int, User> */
    private function companyUsers(): Collection
    {
        $companyId = app(TenantContext::class)->activeCompanyId();

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('company_user')->where('company_id', $companyId)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('shared.settings.approval-levels', [
            'types' => DocumentType::cases(),
            'modes' => ApprovalMode::cases(),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'users' => $this->companyUsers(),
            'canEdit' => Gate::allows('setting.configure'),
        ]);
    }
}
