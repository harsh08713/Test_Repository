<?php

namespace App\Modules\Masters\Http\Livewire\Companies;

use App\Modules\Masters\Actions\SeedCompanyDefaults;
use App\Modules\Masters\Support\StateCatalog;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Rules\Cin;
use App\Modules\Shared\Rules\Gstin;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Rules\Pan;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Create / edit a company: identity (GSTIN, PAN, CIN), address, financial year, base currency and logo.
 *
 * Creating a company also gives the creator access to it and seeds its reference data (states, units,
 * chart of accounts) — see SeedCompanyDefaults.
 */
#[Title('Company')]
class CompanyForm extends Component
{
    use WithFileUploads;

    public const CURRENCIES = ['INR' => 'Indian Rupee (INR)', 'USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)', 'GBP' => 'Pound Sterling (GBP)', 'AED' => 'UAE Dirham (AED)', 'SGD' => 'Singapore Dollar (SGD)'];

    #[Locked]
    public ?int $companyId = null;

    public string $code = '';

    public string $name = '';

    public string $legal_name = '';

    public string $gstin = '';

    public string $pan = '';

    public string $cin = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $state_gst_code = '';

    public string $pin_code = '';

    public string $phone = '';

    public string $email = '';

    public string|int $financial_year_start_month = 4;

    public string $base_currency = 'INR';

    public string $status = 'active';

    public string $remarks = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public bool $removeLogo = false;

    public ?string $currentLogoUrl = null;

    public function mount(?Company $company = null): void
    {
        if ($company?->exists) {
            Gate::authorize('update', $company);

            $this->companyId = $company->id;
            foreach (['code', 'name', 'legal_name', 'gstin', 'pan', 'cin', 'address_line1', 'address_line2', 'city', 'pin_code', 'phone', 'email', 'base_currency', 'status', 'remarks'] as $field) {
                $this->{$field} = (string) $company->{$field};
            }
            $this->state_gst_code = (string) $company->state_code;
            $this->financial_year_start_month = (int) $company->financial_year_start_month;
            $this->currentLogoUrl = $company->logo_path ? route('masters.companies.logo', $company) : null;
        } else {
            Gate::authorize('create', Company::class);
        }
    }

    /** Typing a GSTIN fills the PAN it contains and the state it belongs to, when those are still empty. */
    public function updatedGstin(): void
    {
        $this->gstin = Gstin::normalise($this->gstin);

        if (Gstin::hasValidFormat($this->gstin)) {
            if ($this->pan === '') {
                $this->pan = Gstin::pan($this->gstin);
            }
            if ($this->state_gst_code === '' && StateCatalog::findByGstCode(Gstin::stateCode($this->gstin)) !== null) {
                $this->state_gst_code = Gstin::stateCode($this->gstin);
            }
        }
    }

    public function save(TenantContext $tenant, SeedCompanyDefaults $defaults): void
    {
        $editing = $this->companyId !== null ? Company::query()->findOrFail($this->companyId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Company::class);

        $this->normalise();

        $data = $this->validate($this->rules($editing));
        $this->crossCheck();

        $state = $this->state_gst_code !== '' ? StateCatalog::findByGstCode($this->state_gst_code) : null;

        $attributes = [
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->blank($this->legal_name),
            'gstin' => $this->blank($this->gstin),
            'pan' => $this->blank($this->pan),
            'cin' => $this->blank($this->cin),
            'address_line1' => $this->blank($this->address_line1),
            'address_line2' => $this->blank($this->address_line2),
            'city' => $this->blank($this->city),
            'state' => $state['name'] ?? null,
            'state_code' => $state['gst_code'] ?? null,
            'pin_code' => $this->blank($this->pin_code),
            'phone' => $this->blank($this->phone),
            'email' => $this->blank($this->email),
            'base_currency' => $this->base_currency,
            'status' => $this->status,
            'remarks' => $this->blank($this->remarks),
        ];

        // The financial year start drives document numbers: it is fixed once any number has been issued.
        if (! $editing || ! $this->hasNumberedDocuments($editing)) {
            $attributes['financial_year_start_month'] = (int) $this->financial_year_start_month;
        }

        $company = DB::transaction(function () use ($editing, $attributes, $tenant, $defaults, $data) {
            $company = $editing ?? new Company;
            $company->fill($attributes)->save();

            if ($editing === null) {
                // The creator can work in the company they just made.
                DB::table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => Auth::id(),
                    'all_locations' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $tenant->flush();
                $defaults->run($company);
            }

            $this->storeLogo($company, $data);

            return $company;
        });

        session()->flash('status', $editing ? 'Company saved.' : "Company “{$company->name}” created with its standard states, units and chart of accounts.");

        $this->redirectRoute('masters.companies.index', navigate: false);
    }

    public function render(): View
    {
        $editing = $this->companyId !== null ? Company::query()->find($this->companyId) : null;

        return view('masters.companies.form', [
            'states' => collect(StateCatalog::all())->mapWithKeys(fn (array $s) => [$s['gst_code'] => $s['name'].' ('.$s['gst_code'].')'])->all(),
            'currencies' => self::CURRENCIES,
            'fyLocked' => $editing !== null && $this->hasNumberedDocuments($editing),
            'months' => collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => date('F', mktime(0, 0, 0, $m, 1))])->all(),
        ]);
    }

    private function normalise(): void
    {
        $this->code = strtoupper(trim($this->code));
        $this->gstin = Gstin::normalise($this->gstin);
        $this->pan = strtoupper(trim($this->pan));
        $this->cin = strtoupper(trim($this->cin));
        $this->name = trim($this->name);
        $this->base_currency = strtoupper(trim($this->base_currency));
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Company $editing): array
    {
        return [
            // companies are not tenant-scoped rows, so uniqueness is global; soft-deleted rows still hold their key
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._\-]{1,19}$/', Rule::unique('companies', 'code')->ignore($editing?->id)],
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gstin' => ['nullable', 'string', new Gstin, Rule::unique('companies', 'gstin')->whereNull('deleted_at')->ignore($editing?->id)],
            'pan' => ['nullable', 'string', new Pan],
            'cin' => ['nullable', 'string', new Cin],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_gst_code' => ['nullable', Rule::in(array_column(StateCatalog::all(), 'gst_code'))],
            'pin_code' => ['nullable', new IndianPinCode],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:200'],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
            'base_currency' => ['required', Rule::in(array_keys(self::CURRENCIES))],
            'status' => ['required', 'in:active,inactive'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ];
    }

    /** Identifiers must agree with each other: GSTIN state vs the chosen state, GSTIN's PAN vs the PAN field. */
    private function crossCheck(): void
    {
        if ($this->gstin === '') {
            return;
        }

        if ($this->state_gst_code !== '' && Gstin::stateCode($this->gstin) !== $this->state_gst_code) {
            $gstState = StateCatalog::findByGstCode(Gstin::stateCode($this->gstin))['name'] ?? Gstin::stateCode($this->gstin);

            throw ValidationException::withMessages(['gstin' => "The GSTIN belongs to {$gstState}, which is not the selected state."]);
        }

        if ($this->pan !== '' && Gstin::pan($this->gstin) !== $this->pan) {
            throw ValidationException::withMessages(['pan' => 'The PAN does not match the PAN inside the GSTIN ('.Gstin::pan($this->gstin).').']);
        }
    }

    private function hasNumberedDocuments(Company $company): bool
    {
        return DB::table('number_series')->where('company_id', $company->id)->exists();
    }

    /** @param array<string, mixed> $data */
    private function storeLogo(Company $company, array $data): void
    {
        $old = $company->logo_path;

        if ($this->logo instanceof TemporaryUploadedFile) {
            // Random name, private disk, extension taken from the verified MIME type — never from the client's filename.
            $path = $this->logo->storeAs("company-logos/{$company->id}", Str::uuid()->toString().'.'.$this->logo->extension(), 'local');
            $company->forceFill(['logo_path' => $path])->save();
        } elseif ($this->removeLogo && $old) {
            $company->forceFill(['logo_path' => null])->save();
        } else {
            return;
        }

        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }

    private function blank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
