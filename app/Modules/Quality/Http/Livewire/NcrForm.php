<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Quality\Services\NonConformanceService;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Raise a non-conformance report by hand: where, which item (and batch), how much, how serious, and what went wrong. */
#[Title('Raise a non-conformance')]
class NcrForm extends Component
{
    public string $locationId = '';

    public string $itemId = '';

    public string $itemLabel = '';

    public string $itemSearch = '';

    public string $batchId = '';

    public string $quantity = '';

    public string $severity = 'major';

    public string $description = '';

    public function mount(): void
    {
        Gate::authorize('create', NonConformance::class);
    }

    public function pickItem(int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->itemId = (string) $item->id;
            $this->itemLabel = trim($item->code.' · '.$item->name);
            $this->itemSearch = '';
            $this->batchId = '';
        }
    }

    public function clearItem(): void
    {
        $this->itemId = $this->itemLabel = $this->batchId = '';
    }

    public function save(NonConformanceService $service): void
    {
        $this->resetErrorBag();

        try {
            Gate::authorize('create', NonConformance::class);
            $ncr = $service->create([
                'location_id' => $this->locationId, 'item_id' => $this->itemId, 'batch_id' => $this->batchId, 'quantity_affected' => $this->quantity,
                'severity' => $this->severity, 'description' => $this->description,
            ], Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', "Report {$ncr->ncr_number} raised.");
        $this->redirectRoute('quality.ncrs.show', $ncr, navigate: false);
    }

    public function render(): View
    {
        $batches = ctype_digit($this->itemId)
            ? Batch::query()->where('item_id', (int) $this->itemId)->orderByDesc('id')->limit(100)->get()->mapWithKeys(fn (Batch $b) => [$b->id => $b->batch_no.($b->status !== 'active' ? ' · '.$b->status : '')])->all()
            : [];

        return view('quality.ncr-form', [
            'itemResults' => trim($this->itemSearch) === '' ? collect() : app(ItemLookup::class)->search($this->itemSearch, 6),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(), 'batches' => $batches, 'severities' => NonConformance::SEVERITIES,
        ]);
    }
}
