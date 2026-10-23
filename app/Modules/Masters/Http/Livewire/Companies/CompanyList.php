<?php

namespace App\Modules\Masters\Http\Livewire\Companies;

use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** The companies the signed-in user can access. */
#[Title('Companies')]
class CompanyList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Company::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $company = Company::query()->findOrFail($id);
        Gate::authorize('update', $company);

        $company->update(['status' => $company->status === 'active' ? 'inactive' : 'active']);
    }

    public function delete(int $id): void
    {
        $company = Company::query()->findOrFail($id);
        Gate::authorize('delete', $company);

        // A company with branches or numbered documents is part of the financial record: deactivate, never delete.
        if (Location::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->exists()
            || DB::table('number_series')->where('company_id', $company->id)->exists()) {
            $this->addError('list', 'This company has branches or numbered documents, so it cannot be deleted. Deactivate it instead.');

            return;
        }

        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
        }

        $company->delete();
        session()->flash('status', 'Company deleted.');
    }

    public function render(): View
    {
        $companies = Company::query()
            ->select('companies.*')
            // Counted without the active-company scope, which would show 0 for every company but the current one.
            ->addSelect(['locations_count' => Location::withoutGlobalScopes(Tenancy::SCOPES)->selectRaw('count(*)')->whereColumn('company_id', 'companies.id')])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhere('gstin', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('name')
            ->paginate(15);

        return view('masters.companies.list', ['companies' => $companies, 'canCreate' => Gate::allows('create', Company::class)]);
    }
}
