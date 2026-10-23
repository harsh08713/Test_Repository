<?php

namespace App\Modules\Shared\Http\Livewire\Settings;

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Support\FinancialYear;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Document-number counters (prefix / branch / financial year / sequence).
 * Counters are created automatically on first use; here an admin can adjust the prefix and
 * padding, and move a counter *forward* (e.g. when migrating from a legacy system) — never back.
 */
#[Title('Number series')]
class NumberSeriesIndex extends Component
{
    public ?int $editingId = null;

    public string $prefix = '';

    public int $padding = 5;

    public int $nextNumber = 1;

    // "Add a series" form
    public string $newType = '';

    public string|int $newLocation = '';

    public string $newFinancialYear = '';

    public int $newStart = 1;

    public function mount(): void
    {
        Gate::authorize('setting.view');

        $this->newFinancialYear = $this->currentFinancialYear();
    }

    private function currentFinancialYear(): string
    {
        $company = Company::query()->find(app(TenantContext::class)->activeCompanyId());

        return FinancialYear::label(now(), $company->financial_year_start_month ?? 4);
    }

    public function edit(int $id): void
    {
        $series = NumberSeries::query()->findOrFail($id);

        $this->editingId = $series->id;
        $this->prefix = $series->prefix;
        $this->padding = $series->padding;
        $this->nextNumber = $series->last_number + 1;
    }

    public function cancel(): void
    {
        $this->reset('editingId');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        Gate::authorize('setting.configure');

        $this->validate([
            'prefix' => ['required', 'alpha_num:ascii', 'max:10'],
            'padding' => ['required', 'integer', 'between:3,8'],
            'nextNumber' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () {
            $series = NumberSeries::query()->lockForUpdate()->findOrFail($this->editingId);

            if ($this->nextNumber - 1 < $series->last_number) {
                throw ValidationException::withMessages([
                    'nextNumber' => 'A counter can only move forward. The next number must be at least '.($series->last_number + 1).'.',
                ]);
            }

            $series->fill(['prefix' => strtoupper($this->prefix), 'padding' => $this->padding])->save();

            if ($this->nextNumber - 1 !== $series->last_number) {
                $old = $series->last_number;
                DB::table('number_series')->where('id', $series->id)->update(['last_number' => $this->nextNumber - 1, 'updated_at' => now()]);

                activity('number_series')->causedBy(Auth::user())->performedOn($series)->event('counter_moved')
                    ->withProperties(['old' => ['last_number' => $old], 'attributes' => ['last_number' => $this->nextNumber - 1]])
                    ->log('counter_moved');
            }
        });

        $this->reset('editingId');
        session()->flash('status', 'Number series updated.');
    }

    public function add(): void
    {
        Gate::authorize('setting.configure');

        $numbered = array_map(fn (DocumentType $t) => $t->value, DocumentType::numbered());

        $this->validate([
            'newType' => ['required', Rule::in($numbered)],
            'newLocation' => ['required', 'integer'],
            'newFinancialYear' => ['required', 'regex:/^\d{2}-\d{2}$/'],
            'newStart' => ['required', 'integer', 'min:1'],
        ]);

        $type = DocumentType::from($this->newType);
        $location = Location::query()->findOrFail((int) $this->newLocation); // scoped: only branches the user can see
        $tenant = app(TenantContext::class);

        $exists = NumberSeries::query()
            ->where('location_id', $location->id)->where('document_type', $type->value)
            ->where('financial_year', $this->newFinancialYear)->exists();

        if ($exists) {
            throw ValidationException::withMessages(['newType' => 'That series already exists — edit it from the list.']);
        }

        NumberSeries::create([
            'company_id' => $tenant->activeCompanyId(),
            'location_id' => $location->id,
            'document_type' => $type->value,
            'financial_year' => $this->newFinancialYear,
            'prefix' => $type->prefix(),
            'padding' => 5,
        ])->forceFill(['last_number' => $this->newStart - 1])->save();

        $this->reset('newType', 'newLocation', 'newStart');
        $this->newStart = 1;
        session()->flash('status', 'Number series added.');
    }

    public function render(): View
    {
        return view('shared.settings.number-series', [
            'series' => NumberSeries::query()->with('location:id,code,name')->orderBy('document_type')->orderByDesc('financial_year')->get(),
            'locations' => Location::query()->orderBy('name')->get(['id', 'code', 'name']),
            'types' => DocumentType::numbered(),
            'canEdit' => Gate::allows('setting.configure'),
        ]);
    }
}
