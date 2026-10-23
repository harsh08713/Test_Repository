<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Policies\CrmMasterPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * What came of a follow-up: Call Disconnected, Not Available, Interested, Not Interested, Call Back Later, Meeting Fixed,
 * Store Visited, and whatever else the company adds.
 *
 * Two switches give an outcome its meaning: `counts_as_contact` (the person was actually reached — a new lead then becomes
 * "contacted") and `needs_next_followup` (it only makes sense with a date for the next step, e.g. "Call Back Later").
 *
 * @property string $name
 * @property bool $is_system
 * @property bool $counts_as_contact
 * @property bool $needs_next_followup
 */
#[Fillable(['code', 'name', 'counts_as_contact', 'needs_next_followup', 'status', 'remarks'])]
#[UsePolicy(CrmMasterPolicy::class)]
class FollowupOutcome extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'counts_as_contact' => 'boolean',
            'needs_next_followup' => 'boolean',
        ];
    }

    protected function auditLogName(): string
    {
        return 'followup_outcomes';
    }
}
