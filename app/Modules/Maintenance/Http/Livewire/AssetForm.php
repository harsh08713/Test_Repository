<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\Finance\Models\CostCenter;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Services\AssetService;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Make or change an asset: its code, name, kind, branch, make and model, serial number, when it was installed, how critical it is, and the cost centre its upkeep is charged to. */
#[Title('Asset')]
class AssetForm extends Component
{
    #[Locked]
    public ?int $assetId = null;

    /** @var array<string, string> */
    public array $f = ['code' => '', 'name' => '', 'location_id' => '', 'category' => 'machine', 'make_model' => '', 'serial_no' => '', 'installed_on' => '', 'criticality' => 'medium', 'cost_center_id' => '', 'remarks' => ''];

    public function mount(?int $editing = null): void
    {
        if ($editing !== null) {
            $asset = Asset::query()->findOrFail($editing);
            Gate::authorize('update', $asset);
            $this->assetId = $asset->id;
            $this->f = [
                'code' => $asset->code, 'name' => $asset->name, 'location_id' => (string) $asset->location_id, 'category' => $asset->category, 'make_model' => (string) $asset->make_model,
                'serial_no' => (string) $asset->serial_no, 'installed_on' => $asset->installed_on?->toDateString() ?? '', 'criticality' => $asset->criticality,
                'cost_center_id' => (string) $asset->cost_center_id, 'remarks' => (string) $asset->remarks,
            ];

            return;
        }

        Gate::authorize('create', Asset::class);
    }

    public function save(AssetService $service): void
    {
        $this->resetErrorBag();

        try {
            if ($this->assetId !== null) {
                $asset = Asset::query()->findOrFail($this->assetId);
                Gate::authorize('update', $asset);
                $asset = $service->update($asset, $this->f, Auth::user());
            } else {
                Gate::authorize('create', Asset::class);
                $asset = $service->create($this->f, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', "Asset {$asset->code} saved.");
        $this->redirectRoute('maintenance.assets.show', $asset, navigate: false);
    }

    public function render(): View
    {
        return view('maintenance.asset-form', [
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(), 'categories' => Asset::CATEGORIES, 'criticality' => Asset::CRITICALITY,
            'centres' => CostCenter::query()->orderBy('name')->pluck('name', 'id')->all(), 'editing' => $this->assetId !== null,
        ]);
    }
}
