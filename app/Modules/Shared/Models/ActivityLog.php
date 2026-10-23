<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Models\Scopes\CompanyScope;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit entry. Tenant-scoped on read so a company only ever sees its own history, and stamped
 * with the owning company on write: the subject's company, a Company's own id, or — for records
 * without one (users, roles) — the company the actor is working in.
 *
 * @property int|null $company_id
 */
#[ScopedBy([CompanyScope::class])]
class ActivityLog extends Activity
{
    protected static function booted(): void
    {
        // Append-only: a correction is a new event, never an edit. Retention clean-up (`activitylog:clean`)
        // uses a bulk query delete, which deliberately does not go through these model hooks.
        static::updating(fn () => throw new LogicException('Audit entries are append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit entries are append-only and cannot be deleted.'));

        static::creating(function (self $activity) {
            if ($activity->company_id !== null) {
                return;
            }

            // The logger associates the subject in memory, so this costs no query.
            $subject = $activity->relationLoaded('subject') ? $activity->getRelation('subject') : null;

            $activity->company_id = match (true) {
                $subject instanceof Company => $subject->getKey(),
                $subject !== null && $subject->getAttribute('company_id') !== null => $subject->getAttribute('company_id'),
                default => app(TenantContext::class)->activeCompanyId(),
            };
        });
    }
}
