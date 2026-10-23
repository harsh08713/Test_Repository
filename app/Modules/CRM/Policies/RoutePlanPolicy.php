<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Route plans: a person sees their own; a manager plans and sees the days of the people they manage; `sales.scope_all`
 * covers everyone. A plan can no longer be changed once its day is over, and a cancelled one has to be reinstated first.
 */
class RoutePlanPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'route_plan';
    }

    private function manages(User $user, Model $model): bool
    {
        return $model instanceof RoutePlan && app(SalesScope::class)->manages($user, (int) $model->user_id);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $model instanceof RoutePlan && app(SalesScope::class)->sees($user, (int) $model->user_id);
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof RoutePlan && parent::update($user, $model) && $this->manages($user, $model)
            && $model->status !== RoutePlanStatus::Cancelled && ! $model->isPast();
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof RoutePlan && parent::delete($user, $model) && $this->manages($user, $model) && $model->status === RoutePlanStatus::Draft;
    }

    private function handles(User $user, RoutePlan $plan): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $plan) && $this->manages($user, $plan) && ! $plan->isPast();
    }

    public function publish(User $user, RoutePlan $plan): bool
    {
        return $plan->status === RoutePlanStatus::Draft && $this->handles($user, $plan);
    }

    public function cancel(User $user, RoutePlan $plan): bool
    {
        return $plan->status !== RoutePlanStatus::Cancelled && $this->handles($user, $plan);
    }

    public function reinstate(User $user, RoutePlan $plan): bool
    {
        return $plan->status === RoutePlanStatus::Cancelled && $this->handles($user, $plan);
    }
}
