<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Api\Support\IsoDateTime;
use App\Modules\CRM\Services\DealService;

/**
 * A deal to make from a lead (POST) or to change (PATCH). Fields are flat; the lines are a list under `items`.
 *
 * On a change only the fields that are sent are changed. Sending `items` — or a customer — changes what the deal *is* (and is
 * refused once the deal is in approval or approved); sending only the close date, the probability or the remarks updates where
 * it stands, which an open deal always allows. Sending `items` replaces the lines: rows with an `id` are updated, rows without
 * are added, rows left out are removed.
 */
class DealRequest extends ApiRequest
{
    /** The deal fields a client may send; ids of company and branch, the number, the value, the stage and approvals are never among them. */
    private const FIELDS = ['customer_ledger_id', 'expected_close_date', 'probability', 'remarks'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'customer_ledger_id' => ['nullable', 'integer', 'min:1'],
            'expected_close_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'expected_updated_at' => $creating ? ['prohibited'] : ['nullable', new IsoDateTime],

            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.DealService::MAX_LINES],
            'items.*' => ['array'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.lead_interest_id' => ['nullable', 'integer', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.expected_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /**
     * The deal fields that were sent, as DealService takes them.
     *
     * @return array<string, mixed>
     */
    public function dealFields(): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            if ($this->isMethod('POST') || $this->has($field)) {
                $fields[$field] = $this->input($field);
            }
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>|null null when the client did not send `items` (leave the lines alone)
     */
    public function itemRows(): ?array
    {
        if (! $this->has('items')) {
            return null;
        }

        return array_values(array_map(
            fn ($row) => array_intersect_key((array) $row, array_flip(['id', 'lead_interest_id', 'item_id', 'unit_id', 'quantity', 'expected_rate'])),
            (array) $this->input('items'),
        ));
    }
}
