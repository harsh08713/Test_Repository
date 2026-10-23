<?php

namespace App\Modules\Production\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Services\BomService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Make (or change a draft of) a bill of materials: the item it makes, how much, and the components with their wastage. Only approved, active items can be picked; the service checks again. */
#[Title('Bill of materials')]
class BomForm extends Component
{
    #[Locked]
    public ?int $bomId = null;

    public string $code = '';

    public string $itemId = '';

    public string $itemLabel = '';

    public string $itemSearch = '';

    public string $outputQuantity = '1';

    public string $remarks = '';

    /** @var list<array<string, string>> */
    public array $rows = [];

    public function mount(?int $editing = null): void
    {
        if ($editing !== null) {
            $bom = Bom::query()->with(['components.item:id,code,name', 'item:id,code,name'])->findOrFail($editing);
            Gate::authorize('update', $bom);

            $this->bomId = $bom->id;
            $this->code = $bom->code;
            $this->itemId = (string) $bom->item_id;
            $this->itemLabel = trim($bom->item?->code.' · '.$bom->item?->name);
            $this->outputQuantity = rtrim(rtrim((string) $bom->output_quantity, '0'), '.');
            $this->remarks = (string) $bom->remarks;
            $this->rows = $bom->components->map(fn ($l) => [
                'key' => Str::random(6), 'item_id' => (string) $l->item_id, 'item_label' => trim($l->item?->code.' · '.$l->item?->name), 'search' => '',
                'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'scrap_percent' => rtrim(rtrim((string) $l->scrap_percent, '0'), '.') ?: '0',
            ])->all();

            return;
        }

        Gate::authorize('create', Bom::class);
        $this->rows = [$this->blank()];
    }

    /** @return array<string, string> */
    private function blank(): array
    {
        return ['key' => Str::random(6), 'item_id' => '', 'item_label' => '', 'search' => '', 'quantity' => '', 'scrap_percent' => '0'];
    }

    public function addRow(): void
    {
        if (count($this->rows) < BomService::MAX_LINES) {
            $this->rows[] = $this->blank();
        }
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows) ?: [$this->blank()];
    }

    public function pickItem(int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->itemId = (string) $item->id;
            $this->itemLabel = trim($item->code.' · '.$item->name);
            $this->itemSearch = '';
        }
    }

    public function clearItem(): void
    {
        $this->itemId = $this->itemLabel = '';
    }

    public function pickComponent(int $index, int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null && isset($this->rows[$index])) {
            $this->rows[$index] = [...$this->rows[$index], 'item_id' => (string) $item->id, 'item_label' => trim($item->code.' · '.$item->name), 'search' => ''];
        }
    }

    public function clearComponent(int $index): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index] = [...$this->rows[$index], 'item_id' => '', 'item_label' => '', 'search' => ''];
        }
    }

    public function save(BomService $service): void
    {
        $this->resetErrorBag();
        $input = ['code' => $this->code, 'item_id' => $this->itemId, 'output_quantity' => $this->outputQuantity, 'remarks' => $this->remarks, 'items' => $this->rows];

        try {
            if ($this->bomId !== null) {
                $bom = Bom::query()->findOrFail($this->bomId);
                Gate::authorize('update', $bom);
                $bom = $service->update($bom, $input, Auth::user());
            } else {
                Gate::authorize('create', Bom::class);
                $bom = $service->create($input, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Bill {$bom->code} saved. Send it for approval when it is ready.");
        $this->redirectRoute('production.boms.show', $bom, navigate: false);
    }

    public function render(): View
    {
        $lookup = app(ItemLookup::class);
        $results = [];

        foreach ($this->rows as $i => $row) {
            if (trim($row['search'] ?? '') !== '') {
                $results[$i] = $lookup->search($row['search'], 6)->map(fn ($h) => ['id' => $h->id, 'label' => trim($h->code.' · '.$h->name)])->all();
            }
        }

        return view('production.bom-form', [
            'itemResults' => trim($this->itemSearch) === '' ? collect() : $lookup->search($this->itemSearch, 6),
            'results' => $results, 'editing' => $this->bomId !== null, 'max' => BomService::MAX_LINES,
        ]);
    }
}
