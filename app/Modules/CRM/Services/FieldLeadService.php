<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Exceptions\FieldPositionRefused;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use Illuminate\Validation\ValidationException;

/**
 * Saves a lead entered **in the field** — from the mobile app, against today's route plan.
 *
 *  1. FieldPositionGate decides whether the person may do this from where they stand: a published plan for today, a usable
 *     reading, inside the geofence of one of its stops. If not, nothing is saved, the attempt is written to the violation
 *     log as a `lead_save`, and FieldPositionRefused tells the person why (the API answers 422).
 *  2. The lead is saved by LeadService like any other, so every lead rule — the duplicate warning, the PIN lookup, the
 *     numbering — applies unchanged. Three things are the server's, not the device's: the **branch** is the plan's branch
 *     (whatever the device sent is ignored), the channel is "field", and the position stored on the lead is the one the
 *     geofence accepted, not the raw claim.
 *
 * The source defaults to the company's "Field" source; a device may name another (e.g. "Reference").
 */
final class FieldLeadService
{
    public function __construct(
        private readonly FieldPositionGate $gate,
        private readonly LeadService $leads,
    ) {}

    /**
     * @param  array<string, mixed>  $input  as LeadService::create() takes it; `lead.location_id` is overwritten
     *
     * @throws FieldPositionRefused when the position or the plan does not allow it (already logged)
     * @throws ValidationException when the lead itself is not valid
     */
    public function create(User $user, array $input, mixed $latitude, mixed $longitude, mixed $accuracy, ?string $clientInfo = null): Lead
    {
        [$plan, $check] = $this->gate->verify($user, FieldPositionGate::LEAD_SAVE, $latitude, $longitude, $accuracy, 'mobile', $clientInfo);

        $lead = (array) ($input['lead'] ?? []);
        $lead['location_id'] = (string) $plan->location_id;

        if (trim((string) ($lead['lead_source_id'] ?? '')) === '') {
            $lead['lead_source_id'] = (string) LeadSource::query()->where('code', 'FIELD')->where('status', 'active')->value('id');
        }

        $input['lead'] = $lead;

        return $this->leads->create($input, $user, LeadChannel::Field, null, ['latitude' => (float) $check->latitude, 'longitude' => (float) $check->longitude]);
    }
}
