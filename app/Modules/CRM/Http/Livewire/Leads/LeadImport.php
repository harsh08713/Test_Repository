<?php

namespace App\Modules\CRM\Http\Livewire\Leads;

use App\Models\User;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Shared\Http\Livewire\ImportWizard;
use App\Modules\Shared\Models\Location;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;

/**
 * Import leads from a spreadsheet: template → upload → map the columns → review what the check found → confirm.
 *
 * On top of the shared wizard this page asks three things that apply to rows which do not say for themselves: the
 * branch, the source and the owner. They are checked here when the file is uploaded, and again for every row by the
 * import itself (which runs later, in the background, on the uploader's behalf) — so a choice that was valid when the
 * file was uploaded but is not any more is refused row by row rather than trusted.
 */
#[Title('Import leads')]
class LeadImport extends ImportWizard
{
    public string $defaultBranch = '';

    public string $defaultSource = '';

    public string $defaultOwner = '';

    protected function importerKey(): string
    {
        return 'leads';
    }

    public function mount(): void
    {
        parent::mount();

        $branches = $this->branches();
        $this->defaultBranch = count($branches) === 1 ? (string) array_key_first($branches) : '';
        $this->defaultSource = (string) (LeadSource::query()->where('status', 'active')->where('code', LeadSource::EXCEL)->value('id') ?? '');
        $this->defaultOwner = (string) $this->uploader()->getKey();
    }

    protected function tabsView(): ?string
    {
        return 'crm.leads.partials.import-back';
    }

    protected function optionsView(): ?string
    {
        return 'crm.leads.partials.import-options';
    }

    protected function optionsData(): array
    {
        return [
            'branches' => $this->branches(),
            'sources' => LeadSource::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'owners' => $this->owners(),
            'canAssign' => $this->uploader()->can('lead.assign'),
        ];
    }

    /**
     * @return array{location_id: int|null, lead_source_id: int|null, assigned_to: int|null}
     *
     * @throws ValidationException
     */
    protected function options(): array
    {
        $errors = [];
        $pick = fn (string $value): ?int => ctype_digit($value) ? (int) $value : null;

        $branch = $pick($this->defaultBranch);
        if ($this->defaultBranch !== '' && ($branch === null || ! array_key_exists($branch, $this->branches()))) {
            $errors['defaultBranch'] = 'Choose one of your active branches.';
        }

        $source = $pick($this->defaultSource);
        if ($this->defaultSource !== '' && ($source === null || ! LeadSource::query()->where('status', 'active')->whereKey($source)->exists())) {
            $errors['defaultSource'] = 'Choose one of the active lead sources.';
        }

        $owner = $pick($this->defaultOwner);
        if ($this->defaultOwner !== '' && ($owner === null || ! array_key_exists($owner, $this->owners()))) {
            $errors['defaultOwner'] = 'Choose yourself or someone on a team you manage.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['location_id' => $branch, 'lead_source_id' => $source, 'assigned_to' => $owner];
    }

    private function uploader(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<int, string> the uploader's active branches */
    private function branches(): array
    {
        return Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Who the leads may be given to: the uploader, and — for someone who may hand leads out — the people they manage.
     *
     * @return array<int, string>
     */
    private function owners(): array
    {
        $user = $this->uploader();
        $staff = app(SalesTeamService::class)->staff();
        $self = array_intersect_key($staff, [(int) $user->getKey() => true]);

        if (! $user->can('lead.assign')) {
            return $self;
        }

        $managed = app(SalesScope::class)->managedUserIds($user);

        return $managed === null ? $staff : array_intersect_key($staff, array_flip([...$managed, (int) $user->getKey()]));
    }
}
