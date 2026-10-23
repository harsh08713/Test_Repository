<?php

namespace App\Modules\Loss\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Radar findings: seen with `radar_finding.view`, dismissed with `radar_finding.edit`, inside the person's branches. Reporting one as an incident needs `loss_incident.create`. */
class RadarFindingPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'radar_finding';
    }

    public function create(User $user): bool
    {
        return false;   // made only by the sweep
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
