<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Models\TemplateField;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Loss\Services\LossSources;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Report a loss (or change one that is still open). The template's own extra fields are drawn from the template and checked again on the server;
 * when the loss is reported from a record (`?source=stock_movement&id=…`), what that record says is filled in and a first figure is suggested.
 */
#[Title('Report a loss')]
class LossIncidentForm extends Component
{
    #[Locked]
    public ?int $incidentId = null;

    #[Url(as: 'source')]
    public string $sourceType = '';

    #[Url(as: 'id')]
    public string $sourceId = '';

    /** @var array<string, mixed> the incident's fields (no dots in keys) */
    public array $incident = [];

    /** @var array<string, mixed> the template's extra fields, by key */
    public array $custom = [];

    public string $itemLabel = '';

    public string $itemSearch = '';

    public bool $withImpact = false;

    /** @var array<string, mixed> impact_type, formula_key, inputs[], assumptions, confidence_pct, target_period */
    public array $impact = ['impact_type' => 'actual', 'formula_key' => 'material_cost', 'inputs' => [], 'assumptions' => '', 'confidence_pct' => '', 'target_period' => ''];

    public function mount(IndustryTemplateService $templates, ?int $editing = null): void
    {
        if ($editing !== null) {
            $loss = LossIncident::query()->with('item:id,code,name')->findOrFail($editing);
            Gate::authorize('update', $loss);

            $this->incidentId = $loss->id;
            $this->incident = [
                'industry_template_id' => (string) $loss->industry_template_id, 'loss_category_id' => (string) $loss->loss_category_id, 'location_id' => (string) $loss->location_id,
                'department_id' => (string) $loss->department_id, 'cost_center_id' => (string) $loss->cost_center_id, 'process' => (string) $loss->process, 'item_id' => (string) $loss->item_id, 'batch_id' => (string) $loss->batch_id,
                'asset_ref' => (string) $loss->asset_ref, 'occurred_at' => $loss->occurred_at->format('Y-m-d\TH:i'), 'quantity' => $loss->quantity === null ? '' : rtrim(rtrim((string) $loss->quantity, '0'), '.'),
                'unit_id' => (string) $loss->unit_id, 'severity' => $loss->severity, 'is_safety' => $loss->is_safety, 'is_quality' => $loss->is_quality, 'is_compliance' => $loss->is_compliance,
                'title' => $loss->title, 'description' => $loss->description,
            ];
            // a stored date and time (2026-10-12 10:00) goes back into a datetime-local input as 2026-10-12T10:00
            $this->custom = array_map(fn ($v) => is_bool($v) || is_array($v) ? $v : (string) preg_replace('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', '$1T$2', (string) $v), $loss->custom_fields ?? []);
            $this->itemLabel = $loss->item ? trim($loss->item->code.' · '.$loss->item->name) : '';

            return;
        }

        Gate::authorize('create', LossIncident::class);

        $enabled = $templates->enabled();
        $this->incident = [
            'industry_template_id' => $enabled->count() === 1 ? (string) $enabled->first()->id : '', 'loss_category_id' => '', 'location_id' => '', 'department_id' => '', 'cost_center_id' => '',
            'process' => '', 'item_id' => '', 'batch_id' => '', 'asset_ref' => '', 'occurred_at' => now()->format('Y-m-d\TH:i'), 'quantity' => '', 'unit_id' => '',
            'severity' => 'medium', 'is_safety' => false, 'is_quality' => false, 'is_compliance' => false, 'title' => '', 'description' => '',
        ];

        if ($this->sourceType !== '' && ctype_digit($this->sourceId)) {
            $this->prefill();
        }
    }

    /** Fill in what the record the loss is reported from says. */
    private function prefill(): void
    {
        $found = app(LossSources::class)->describe($this->sourceType, (int) $this->sourceId);

        if ($found === null) {
            $this->addError('form', 'That record cannot be reported as a loss (it is not one of yours, or it is not a loss).');
            $this->sourceType = $this->sourceId = '';

            return;
        }

        $item = $found['item_id'] !== null ? Item::query()->find($found['item_id']) : null;
        $this->incident = [
            ...$this->incident, 'location_id' => (string) $found['location_id'], 'item_id' => (string) ($found['item_id'] ?? ''), 'batch_id' => (string) ($found['batch_id'] ?? ''),
            'quantity' => $found['quantity'] === null ? '' : (rtrim(rtrim($found['quantity'], '0'), '.') ?: '0'), 'unit_id' => (string) ($found['unit_id'] ?? ''), 'title' => $found['title'],
            'description' => $found['label'].'.',
        ];
        $this->itemLabel = $item ? trim($item->code.' · '.$item->name) : '';
        $this->withImpact = true;
        $this->impact = [...$this->impact, ...$found['impact']];
    }

    /** A different template has different categories and fields: start those again. */
    public function updatedIncident(mixed $value, string $key): void
    {
        if ($key === 'industry_template_id') {
            $this->incident['loss_category_id'] = '';
            $this->custom = [];
        }
    }

    public function pickItem(int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->incident['item_id'] = (string) $item->id;
            $this->incident['unit_id'] = (string) $item->base_unit_id;
            $this->incident['batch_id'] = '';
            $this->itemLabel = trim($item->code.' · '.$item->name);
            $this->itemSearch = '';
        }
    }

    public function clearItem(): void
    {
        $this->incident['item_id'] = $this->incident['batch_id'] = $this->incident['unit_id'] = '';
        $this->itemLabel = '';
    }

    public function save(LossIncidentService $service): void
    {
        $this->resetErrorBag();
        $custom = array_filter($this->custom, fn ($v) => $v !== '' && $v !== null && $v !== false && $v !== []);

        // a browser sends a date and time as 2026-10-12T10:00; the template's fields are checked as 2026-10-12 10:00
        foreach ($custom as $key => $value) {
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
                $custom[$key] = str_replace('T', ' ', $value);
            }
        }

        $input = [...$this->incident, 'custom_fields' => $custom];

        try {
            if ($this->incidentId !== null) {
                $loss = LossIncident::query()->findOrFail($this->incidentId);
                $loss = $service->update($loss, $input, Auth::user());
            } else {
                $loss = $service->create([
                    ...$input, 'source_type' => $this->sourceType, 'source_id' => $this->sourceId, 'impact' => $this->withImpact ? $this->impact : null,
                ], Auth::user());
            }
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors[match (true) {
                    str_starts_with($key, 'custom_fields.') => 'custom.'.substr($key, 14),
                    str_starts_with($key, 'inputs') || in_array($key, ['impact_type', 'formula_key', 'assumptions', 'confidence_pct', 'target_period', 'impact'], true) => 'impact.'.$key,
                    default => 'incident.'.$key,
                }] = $messages;
            }
            $this->setErrorBag($errors);

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $this->incidentId !== null ? 'Saved.' : "Loss {$loss->incident_number} reported.".($loss->isDuplicate() ? ' Another incident already covers the same record, so this one is linked to it and its money is not counted twice.' : ''));
        $this->redirectRoute('loss.incidents.show', $loss, navigate: false);
    }

    public function render(IndustryTemplateService $templates, LossCalculationService $calculator): View
    {
        $template = ctype_digit((string) $this->incident['industry_template_id']) ? IndustryTemplate::query()->find((int) $this->incident['industry_template_id']) : null;
        $version = $template === null ? null : ($this->incidentId !== null ? LossIncident::query()->whereKey($this->incidentId)->value('template_version') : $templates->versionFor($template));
        $item = ctype_digit((string) $this->incident['item_id']) ? Item::query()->with(['baseUnit', 'uoms.unit'])->find((int) $this->incident['item_id']) : null;

        return view('loss.form', [
            'editing' => $this->incidentId !== null,
            'templates' => $this->incidentId !== null && $template !== null ? collect([$template]) : $templates->enabled(),
            'categories' => $template === null ? collect() : LossCategory::query()->where('industry_template_id', $template->id)->where('version', $version)->where('is_active', true)->orderBy('name')->get(),
            'fields' => $template === null ? collect() : TemplateField::query()->where('industry_template_id', $template->id)->where('version', $version)->orderBy('sort_order')->get(),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'costCenters' => CostCenter::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'departments' => Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'item' => $item,
            'units' => $item === null ? [] : collect([$item->baseUnit, ...$item->uoms->map->unit])->filter()->unique('id')->mapWithKeys(fn ($u) => [$u->id => $u->name])->all(),
            'batches' => $item?->tracking === 'batch' ? Batch::query()->where('item_id', $item->id)->orderBy('batch_no')->pluck('batch_no', 'id')->all() : [],
            'results' => trim($this->itemSearch) === '' ? collect() : app(ItemLookup::class)->search($this->itemSearch, 6),
            'formulas' => $calculator->formulas(),
            'types' => LossImpactLine::TYPES,
            'severities' => LossIncident::SEVERITIES,
            'sourceLabel' => $this->sourceType !== '' && ctype_digit($this->sourceId) ? app(LossSources::class)->describe($this->sourceType, (int) $this->sourceId)['label'] ?? null : null,
        ]);
    }
}
