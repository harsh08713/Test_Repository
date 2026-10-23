<?php

namespace App\Modules\Masters\Http\Livewire\Locations;

use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Create / edit a branch. The PIN code fills state and city from the geography master where that is
 * unambiguous. The branch code appears in every document number, so it is fixed once numbers exist.
 */
#[Title('Branch')]
class LocationForm extends Component
{
    #[Locked]
    public ?int $locationId = null;

    public string $code = '';

    public string $name = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $state_gst_code = '';

    public string $pin_code = '';

    public string $phone = '';

    public string $email = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $status = 'active';

    public string $remarks = '';

    public string $pinHint = '';

    public function mount(?Location $location = null): void
    {
        if ($location?->exists) {
            Gate::authorize('update', $location);

            $this->locationId = $location->id;
            foreach (['code', 'name', 'address_line1', 'address_line2', 'city', 'pin_code', 'phone', 'email', 'status', 'remarks'] as $field) {
                $this->{$field} = (string) $location->{$field};
            }
            $this->state_gst_code = (string) $location->state_code;
            $this->latitude = $location->latitude === null ? '' : (string) $location->latitude;
            $this->longitude = $location->longitude === null ? '' : (string) $location->longitude;
        } else {
            Gate::authorize('create', Location::class);
        }
    }

    public function updatedPinCode(PinCodeLookup $lookup): void
    {
        $this->pinHint = '';
        $pin = trim($this->pin_code);

        if (! IndianPinCode::isValid($pin)) {
            return;
        }

        $result = $lookup->resolve($pin);

        if (! $result->found) {
            $this->pinHint = 'This PIN code is not in the geography master — enter the state and city yourself.';

            return;
        }

        if ($result->state !== null) {
            $this->state_gst_code = $result->state->gst_code;
        }

        if ($result->district !== null && trim($this->city) === '') {
            $this->city = $result->district->name;
        }

        $this->pinHint = $result->isAmbiguous()
            ? 'This PIN code covers more than one district or block, so only the unambiguous parts were filled.'
            : 'State and city filled from the PIN code master.';
    }

    public function save(TenantContext $tenant): void
    {
        $editing = $this->locationId !== null ? Location::query()->findOrFail($this->locationId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Location::class);

        $codeLocked = $editing !== null && $this->hasNumberedDocuments($editing);

        $this->code = $codeLocked ? (string) $editing->code : strtoupper(trim($this->code));
        $this->name = trim($this->name);

        $this->validate($this->rules($editing));

        if (($this->latitude === '') !== ($this->longitude === '')) {
            throw ValidationException::withMessages([$this->latitude === '' ? 'latitude' : 'longitude' => 'Enter both latitude and longitude, or neither.']);
        }

        $state = $this->state_gst_code !== '' ? State::query()->where('gst_code', $this->state_gst_code)->first() : null;

        if ($this->state_gst_code !== '' && $state === null) {
            throw ValidationException::withMessages(['state_gst_code' => 'Choose a state from the list.']);
        }

        $location = $editing ?? new Location;
        $location->fill([
            'code' => $this->code,
            'name' => $this->name,
            'address_line1' => $this->blank($this->address_line1),
            'address_line2' => $this->blank($this->address_line2),
            'city' => $this->blank($this->city),
            'state' => $state?->name,
            'state_code' => $state?->gst_code,
            'pin_code' => $this->blank($this->pin_code),
            'phone' => $this->blank($this->phone),
            'email' => $this->blank($this->email),
            'latitude' => $this->latitude === '' ? null : $this->latitude,
            'longitude' => $this->longitude === '' ? null : $this->longitude,
            'status' => $this->status,
            'remarks' => $this->blank($this->remarks),
        ]);

        DB::transaction(function () use ($location, $editing, $tenant) {
            $location->save();

            // A branch-restricted user who creates a branch must be able to see it afterwards.
            if ($editing === null && ($tenant->accessibleLocationIds() !== null)) {
                DB::table('location_user')->insert([
                    'location_id' => $location->id,
                    'user_id' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $tenant->flush();
            }
        });

        session()->flash('status', $editing ? 'Branch saved.' : 'Branch added.');
        $this->redirectRoute('masters.locations.index', navigate: false);
    }

    public function render(): View
    {
        $editing = $this->locationId !== null ? Location::query()->find($this->locationId) : null;

        return view('masters.locations.form', [
            'states' => State::query()->where('status', 'active')->orderBy('name')->pluck('name', 'gst_code')->all(),
            'codeLocked' => $editing !== null && $this->hasNumberedDocuments($editing),
        ]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Location $editing): array
    {
        $companyId = app(TenantContext::class)->activeCompanyId();

        return [
            // The branch code is part of every document number (QT/AHD/26-27/00001): short, upper-case, unique in the company.
            'code' => ['required', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique('locations', 'code')->where('company_id', $companyId)->ignore($editing?->id)],
            'name' => ['required', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_gst_code' => ['nullable', 'regex:/^\d{2}$/'],
            'pin_code' => ['nullable', new IndianPinCode],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:active,inactive'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function hasNumberedDocuments(Location $location): bool
    {
        return DB::table('number_series')->where('location_id', $location->id)->exists();
    }

    private function blank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
