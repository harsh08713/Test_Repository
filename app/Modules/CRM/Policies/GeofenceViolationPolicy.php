<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

/**
 * The violation log is read with the attendance permissions, by the person it is about and by their managers. Accepting a
 * refused attempt needs the dedicated override permission, the company's override switch and a person the manager manages.
 */
class GeofenceViolationPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'attendance';
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $model instanceof GeofenceViolation && app(SalesScope::class)->sees($user, (int) $model->user_id);
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Is this one the user may accept right now? (The service checks every rule again.) */
    public function override(User $user, GeofenceViolation $violation): bool
    {
        return $user->can('geofence.override')
            && $this->withinTenant($user, $violation)
            && (bool) app(SettingsService::class)->get('geofence.manager_override', (int) $violation->company_id)
            && app(SalesScope::class)->manages($user, (int) $violation->user_id)
            && $violation->attempt_type === 'attendance'   // a lead refused in the field cannot be "accepted": the person enters it again from where they should be
            && $violation->reason->isOverridable()
            && $violation->route_plan_id !== null;
    }
}
