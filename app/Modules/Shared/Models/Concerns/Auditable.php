<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Shared\Support\PruneUnloadedAttributes;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit trail: every create / update / delete (and restore) is written to `activity_log`
 * with the old and new values of the changed attributes and the acting user. The owning
 * company is stamped by the ActivityLog model, so nothing else is needed here.
 *
 * @mixin Model
 */
trait Auditable
{
    use LogsActivity;

    public static function bootAuditable(): void
    {
        PruneUnloadedAttributes::register(static::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->auditLogName())
            ->logAll()
            ->logExcept(['updated_at', 'updated_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Groups entries in the audit viewer; defaults to the table name. */
    protected function auditLogName(): string
    {
        return $this->getTable();
    }
}
