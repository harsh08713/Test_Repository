<?php

namespace App\Modules\Api\Http\Requests;

use App\Models\User;
use App\Modules\Api\Support\IsoDateTime;
use App\Modules\Api\Support\Scalar;

/**
 * A lead to enter (POST) or to change (PATCH). Fields are flat; what the lead is interested in is a list under `interests`;
 * and for someone who enters leads from the field, `position` carries the device's reading (checked against today's route
 * plan by the server before anything is saved).
 *
 * On a change only the fields that are sent are changed; sending `interests` replaces the list (rows with an `id` are updated,
 * rows without are added, rows left out are removed).
 */
class LeadRequest extends ApiRequest
{
    /** The lead fields a client may send; ids of company, status, stage, numbers and approvals are never among them. */
    private const FIELDS = [
        'lead_date', 'lead_source_id', 'name', 'contact_person', 'mobile', 'phone', 'email', 'address_line1', 'address_line2', 'city',
        'state_id', 'district_id', 'block_id', 'pin_code', 'requirement', 'expected_value', 'remarks',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $user = $this->user();

        return [
            'lead_date' => ['nullable', 'date_format:Y-m-d'],
            'lead_source_id' => ['nullable', 'integer', 'min:1'],
            // an inquiry from an existing customer takes the name, the contact and the address from the customer's ledger
            'name' => $creating ? ['required_without:customer_ledger_id', 'nullable', 'string', 'max:200'] : ['sometimes', 'string', 'max:200'],
            'customer_ledger_id' => $creating ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_id' => ['nullable', 'integer', 'min:1'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'block_id' => ['nullable', 'integer', 'min:1'],
            'pin_code' => ['nullable', 'string', 'max:10'],
            'requirement' => ['nullable', 'string', 'max:2000'],
            'expected_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],

            // only when entering: where the lead goes (ownership afterwards changes only through a transfer)
            'location_id' => $creating ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'assigned_to' => $creating ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            // "this is a separate inquiry although an open lead has the same mobile number"
            'acknowledge_duplicate' => ['nullable', 'boolean'],
            'expected_updated_at' => $creating ? ['prohibited'] : ['nullable', new IsoDateTime],

            // a person who works in the field sends where they are; everyone else's position is not used
            'position' => $creating && $user instanceof User && $user->can('attendance.create') ? ['required', 'array'] : ['nullable', 'array'],
            'position.latitude' => $this->positionRule($creating, $user),
            'position.longitude' => $this->positionRule($creating, $user),
            'position.accuracy_m' => $this->positionRule($creating, $user),

            'interests' => ['sometimes', 'array', 'max:30'],
            'interests.*' => ['array'],
            'interests.*.id' => ['nullable', 'integer', 'min:1'],
            'interests.*.item_id' => ['nullable', 'integer', 'min:1'],
            'interests.*.description' => ['nullable', 'string', 'max:200'],
            'interests.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'interests.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'interests.*.expected_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /** @return list<mixed> */
    private function positionRule(bool $creating, mixed $user): array
    {
        return $creating && $user instanceof User && $user->can('attendance.create') ? ['required', new Scalar] : ['nullable', new Scalar];
    }

    /**
     * The lead fields that were sent, as the lead service takes them. On create every field is present (blank when not sent);
     * on a change only the ones sent.
     *
     * @return array<string, mixed>
     */
    public function leadFields(): array
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            if ($this->isMethod('POST') || $this->has($field)) {
                $fields[$field] = $this->input($field);
            }
        }

        if ($this->isMethod('POST')) {
            foreach (['location_id', 'assigned_to', 'customer_ledger_id'] as $field) {
                $fields[$field] = $this->input($field);
            }
        }

        if ($this->isMethod('POST') || $this->has('acknowledge_duplicate')) {
            $fields['acknowledge_duplicate'] = $this->boolean('acknowledge_duplicate');
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>|null null when the client did not send `interests` (leave them alone)
     */
    public function interestRows(): ?array
    {
        if (! $this->has('interests')) {
            return null;
        }

        return array_values(array_map(
            fn ($row) => array_intersect_key((array) $row, array_flip(['id', 'item_id', 'description', 'quantity', 'unit_id', 'expected_rate'])),
            (array) $this->input('interests'),
        ));
    }

    /** @return array{latitude: mixed, longitude: mixed, accuracy: mixed} */
    public function position(): array
    {
        return [
            'latitude' => $this->input('position.latitude'),
            'longitude' => $this->input('position.longitude'),
            'accuracy' => $this->input('position.accuracy_m'),
        ];
    }
}
