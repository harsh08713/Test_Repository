<?php

namespace App\Modules\Masters\Services;

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes rates. The single gate for rate data (the form and the demo seeder share it); it does not
 * decide who may (RatePolicy does) and it does not run the approval (ApprovalService does).
 *
 * Input: ['rate' => [item_id, location_id, unit_id, price_list_id, rate, effective_from, effective_to, remarks]]
 * — the nesting is the Livewire form's property name, so validation errors land on the right fields.
 */
final class RateMaintenance
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws ApprovalStateException when the rate is locked by the workflow
     */
    public function save(?Rate $rate, array $input, User $by): Rate
    {
        if ($rate !== null && ! $rate->isEditableByWorkflow()) {
            throw ApprovalStateException::notReady('This rate is awaiting approval or approved, so it cannot be edited. Re-open it first.');
        }

        $data = $this->validate($input, $rate);

        return DB::transaction(function () use ($data, $rate, $by) {
            $model = $rate ?? new Rate;

            $model->fill(['rate' => $data['rate'], 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'], 'remarks' => $data['remarks']]);

            // Who and where the rate is for never changes once it has been approved (the validator enforces it);
            // for a new rate, or one still in draft, they are set here — never mass-assigned.
            $model->forceFill([
                'item_id' => $data['item_id'], 'location_id' => $data['location_id'],
                'unit_id' => $data['unit_id'], 'price_list_id' => $data['price_list_id'],
                'updated_by' => $by->getKey(),
            ]);

            if ($rate === null) {
                $model->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $by->getKey()]);
            }

            $model->save();

            return $model->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{item_id: int, location_id: int, unit_id: int, price_list_id: int|null, rate: string, effective_from: string, effective_to: string|null, remarks: string|null}
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Rate $editing): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $data = $this->normalise((array) ($input['rate'] ?? []));
        $locked = $editing !== null && $editing->approvals()->where('status', 'approved')->exists();

        $rules = [
            'rate.item_id' => ['required', 'integer'],
            'rate.location_id' => ['required', 'integer'],
            'rate.unit_id' => ['required', 'integer'],
            'rate.price_list_id' => ['nullable', 'integer'],
            'rate.rate' => ['bail', 'required', 'regex:/^\d{1,11}(\.\d{1,'.Rate::SCALE.'})?$/', fn (string $attribute, mixed $value, \Closure $fail) => bccomp((string) $value, '0', Rate::SCALE) > 0 ? null : $fail('The rate must be more than zero.')],
            'rate.effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'rate.effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:rate.effective_from', 'before_or_equal:2100-12-31'],
            'rate.remarks' => ['nullable', 'string', 'max:1000'],
        ];

        $validator = Validator::make(['rate' => $data], $rules, [
            'rate.rate.regex' => 'Enter the rate as a number with at most '.Rate::SCALE.' decimals, e.g. 125.50.',
            'rate.effective_to.after_or_equal' => 'The end date cannot be before the start date.',
            'rate.effective_from.date_format' => 'Enter the start date as a valid date.',
            'rate.effective_to.date_format' => 'Enter the end date as a valid date.',
        ], [
            'rate.item_id' => 'item', 'rate.location_id' => 'branch', 'rate.unit_id' => 'unit', 'rate.price_list_id' => 'price list',
            'rate.rate' => 'rate', 'rate.effective_from' => 'start date', 'rate.effective_to' => 'end date',
        ]);

        $validator->after(function (\Illuminate\Validation\Validator $v) use ($data, $editing, $locked, $companyId) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->crossChecks($v, $data, $editing, $locked, $companyId);
        });

        $validator->validate();

        return [
            'item_id' => (int) $data['item_id'],
            'location_id' => (int) $data['location_id'],
            'unit_id' => (int) $data['unit_id'],
            'price_list_id' => $data['price_list_id'] === null ? null : (int) $data['price_list_id'],
            'rate' => bcadd((string) $data['rate'], '0', Rate::SCALE),
            'effective_from' => (string) $data['effective_from'],
            'effective_to' => $data['effective_to'],
            'remarks' => $data['remarks'],
        ];
    }

    /**
     * Stop an approved rate on $on (inclusive), or give an open-ended one an end. It can only shorten a rate, so it
     * never exposes anything unapproved and needs no new approval; the reason, who and the old and new end date go to
     * the audit log. An end date in the past is refused: what applied on a past day is history.
     *
     * @throws ValidationException
     * @throws ApprovalStateException
     */
    public function endDate(Rate $rate, string $on, string $reason, User $by): Rate
    {
        $data = Validator::make(['on' => trim($on), 'reason' => trim($reason)], [
            'on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'on.required' => 'Choose the last day the rate should apply.',
            'on.date_format' => 'Enter the last day as a valid date.',
            'reason.required' => 'Please say why the rate is being ended.',
            'reason.min' => 'Please say why the rate is being ended.',
        ])->validate();

        return DB::transaction(function () use ($rate, $data, $by) {
            $fresh = Rate::query()->lockForUpdate()->findOrFail($rate->getKey());

            if (! $fresh->isApproved()) {
                throw ApprovalStateException::notReady('Only an approved rate can be ended early.');
            }

            $on = $data['on'];
            $today = now()->toDateString();

            if ($on < $today) {
                throw ValidationException::withMessages(['on' => 'The last day cannot be in the past.']);
            }

            if ($on < $fresh->effective_from->toDateString()) {
                throw ValidationException::withMessages(['on' => 'The last day cannot be before the rate starts ('.$fresh->effective_from->format('j M Y').').']);
            }

            if ($fresh->effective_to !== null && $on >= $fresh->effective_to->toDateString()) {
                throw ValidationException::withMessages(['on' => 'The rate already ends on '.$fresh->effective_to->format('j M Y').'. Ending can only shorten it.']);
            }

            $old = $fresh->effective_to?->toDateString();

            // One audit event that carries the reason, instead of a bare field change plus a second entry.
            activity()->withoutLogs(fn () => $fresh->forceFill(['effective_to' => $on, 'updated_by' => $by->getKey()])->save());

            activity('rate')
                ->causedBy($by)
                ->performedOn($fresh)
                ->event('ended_early')
                ->withProperties(['old' => ['effective_to' => $old], 'attributes' => ['effective_to' => $on], 'comment' => $data['reason']])
                ->log('ended_early');

            return $fresh->refresh();
        });
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    private function normalise(array $rate): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;

        return [
            'item_id' => $text($rate['item_id'] ?? null),
            'location_id' => $text($rate['location_id'] ?? null),
            'unit_id' => $text($rate['unit_id'] ?? null),
            'price_list_id' => $text($rate['price_list_id'] ?? null),
            'rate' => $text(str_replace(',', '', (string) ($rate['rate'] ?? ''))),
            'effective_from' => $text($rate['effective_from'] ?? null),
            'effective_to' => $text($rate['effective_to'] ?? null),
            'remarks' => $text($rate['remarks'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function crossChecks(\Illuminate\Validation\Validator $v, array $data, ?Rate $editing, bool $locked, int $companyId): void
    {
        $errors = $v->errors();
        $itemId = (int) $data['item_id'];
        $locationId = (int) $data['location_id'];
        $unitId = (int) $data['unit_id'];
        $listId = $data['price_list_id'] === null ? null : (int) $data['price_list_id'];

        // Identity is fixed once a rate has been approved: change the price or dates by re-opening, anything else by a new rate.
        if ($locked && $editing !== null) {
            foreach (['item_id' => 'item', 'location_id' => 'branch', 'unit_id' => 'unit', 'price_list_id' => 'price list'] as $field => $label) {
                $now = $data[$field] === null ? null : (int) $data[$field];
                $was = $editing->getAttribute($field) === null ? null : (int) $editing->getAttribute($field);

                if ($now !== $was) {
                    $errors->add("rate.$field", "The {$label} of a rate that has been approved cannot be changed. Add a new rate instead.");
                }
            }

            if ($errors->isNotEmpty()) {
                return;
            }
        }

        $unchanged = fn (string $field, int $value) => $editing !== null && (int) $editing->getAttribute($field) === $value;

        $item = Item::query()->with('uoms')->find($itemId);
        if ($item === null || (int) $item->company_id !== $companyId) {
            $errors->add('rate.item_id', 'Choose an item.');
        } elseif (! $item->isUsable() && ! $unchanged('item_id', $itemId)) {
            $errors->add('rate.item_id', 'Only an approved, active item can be given a rate.');
        }

        // Location's own scope hides the branches this person does not work in, so a tampered id is "not found".
        $branch = Location::query()->find($locationId);
        if ($branch === null) {
            $errors->add('rate.location_id', 'Choose one of your branches.');
        } elseif ($branch->status !== 'active' && ! $unchanged('location_id', $locationId)) {
            $errors->add('rate.location_id', 'That branch is not active.');
        }

        $unit = Unit::query()->find($unitId);
        if ($unit === null) {
            $errors->add('rate.unit_id', 'Choose a unit.');
        } elseif ($item !== null) {
            if ($unitId !== (int) $item->base_unit_id && $item->uoms->doesntContain(fn ($row) => (int) $row->unit_id === $unitId)) {
                $errors->add('rate.unit_id', "{$item->name} is not sold in that unit. Choose its base unit or one of its conversions.");
            } elseif ($unit->status !== 'active' && ! $unchanged('unit_id', $unitId)) {
                $errors->add('rate.unit_id', 'That unit is not active.');
            }
        }

        if ($listId !== null) {
            $list = PriceList::query()->find($listId);

            if ($list === null) {
                $errors->add('rate.price_list_id', 'Choose a price list, or leave it blank for the standard rate.');
            } elseif ($list->status !== 'active' && ! ($editing !== null && (int) $editing->price_list_id === $listId)) {
                $errors->add('rate.price_list_id', 'That price list is not active.');
            }
        }

        if ($errors->isNotEmpty()) {
            return;
        }

        $conflicts = Rate::conflictsFor($itemId, $locationId, $unitId, $listId, (string) $data['effective_from'], $data['effective_to'], $editing?->getKey());

        if ($conflicts->isNotEmpty()) {
            $others = $conflicts->map(fn (Rate $r) => $r->periodLabel().' ('.strtolower($r->approval_status->label()).')')->implode('; ');
            $errors->add('rate.effective_from', "These dates overlap another rate for the same item, branch, unit and price list: {$others}. Rates cannot overlap: end-date the other one, or change these dates.");
        }
    }
}
