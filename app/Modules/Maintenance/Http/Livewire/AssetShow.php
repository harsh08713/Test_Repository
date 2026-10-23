<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Services\AssetService;
use App\Modules\Maintenance\Services\MaintenanceFigures;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One asset: its details, a year of breakdowns and downtime, its preventive schedules and its work orders. */
#[Title('Asset')]
class AssetShow extends Component
{
    #[Locked]
    public int $assetId = 0;

    public function mount(Asset $asset): void
    {
        Gate::authorize('view', $asset);
        $this->assetId = $asset->id;
    }

    #[Computed]
    public function assetModel(): Asset
    {
        $asset = Asset::query()->with(['location:id,name', 'costCenter:id,name', 'plans'])->findOrFail($this->assetId);
        Gate::authorize('view', $asset);

        return $asset;
    }

    public function toggle(AssetService $service): void
    {
        $asset = $this->assetModel();
        Gate::authorize('update', $asset);
        $this->resetErrorBag();

        try {
            $service->setStatus($asset, $asset->status !== 'active', Auth::user());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->assetModel);
        session()->flash('status', 'Saved.');
    }

    public function render(MaintenanceFigures $figures): View
    {
        $asset = $this->assetModel();

        return view('maintenance.asset-show', [
            'asset' => $asset, 'categories' => Asset::CATEGORIES, 'criticality' => Asset::CRITICALITY, 'f' => $figures->forAsset($asset->id, now()->subYear()),
            'orders' => MaintenanceOrder::query()->where('asset_id', $asset->id)->orderByDesc('id')->limit(20)->get(), 'types' => MaintenanceOrder::TYPES, 'states' => MaintenanceOrder::STATES,
            'canEdit' => Gate::allows('update', $asset), 'canReport' => Gate::allows('create', MaintenanceOrder::class) && $asset->status === 'active',
        ]);
    }
}
