<?php

namespace App\Modules\Shared\Policies;

use App\Models\User;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-matrix driven policy: every ability maps to `{form}.{action}`. Subclasses only declare their
 * form key. There is no blanket bypass for any role: Super Admin simply holds every permission.
 *
 * Two things must both hold for any action on a record:
 *   1. the user holds the permission; and
 *   2. the record is in the user's tenant (their companies and branches) — checked here as well as by the
 *      global scopes, so a record obtained by any other route (a model passed to a component, a job) is
 *      still refused.
 *
 * Approvable records that are pending or approved are locked against editing/deleting until they are
 * rejected or re-opened by the workflow.
 */
abstract class BaseDocumentPolicy
{
    /** Permission form key, e.g. `item` for `item.view`, `item.edit`... */
    abstract protected function form(): string;

    protected function allows(User $user, PermissionAction $action): bool
    {
        return $user->can($action->permission($this->form()));
    }

    /** Is this record inside the companies and branches this user belongs to? */
    protected function withinTenant(User $user, Model $model): bool
    {
        $tenant = app(TenantContext::class);

        $companyId = $model instanceof Company ? $model->getKey() : $model->getAttribute('company_id');

        if ($companyId === null) {
            return true;   // not a company-owned record (e.g. a User): the policy's own rules decide
        }

        if (! $tenant->userCanAccessCompany($user, (int) $companyId)) {
            return false;
        }

        $locationId = $model instanceof Location ? $model->getKey() : $model->getAttribute('location_id');

        if ($locationId !== null) {
            $allowed = $tenant->accessibleLocationIdsFor($user, (int) $companyId);

            if ($allowed !== null && ! in_array((int) $locationId, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAction::View);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::View) && $this->withinTenant($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAction::Create);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model) && $this->isUnlocked($model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Delete) && $this->withinTenant($user, $model) && $this->isUnlocked($model);
    }

    public function approve(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Approve) && $this->withinTenant($user, $model);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, PermissionAction::Export);
    }

    public function import(User $user): bool
    {
        return $this->allows($user, PermissionAction::Import);
    }

    /**
     * Commenting is separate from editing so view-only users (e.g. Operations on Item Master)
     * can still annotate a record. Anyone who may edit it may also comment.
     */
    public function comment(User $user, Model $model): bool
    {
        return $this->view($user, $model)
            && ($this->allows($user, PermissionAction::Comment) || $this->allows($user, PermissionAction::Edit));
    }

    /** Submit a draft/rejected document for approval: same right as editing it. */
    public function submit(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Edit)
            && $this->withinTenant($user, $model)
            && (! method_exists($model, 'isEditableByWorkflow') || $model->isEditableByWorkflow());
    }

    private function isUnlocked(Model $model): bool
    {
        return ! method_exists($model, 'isEditableByWorkflow') || $model->isEditableByWorkflow();
    }
}
