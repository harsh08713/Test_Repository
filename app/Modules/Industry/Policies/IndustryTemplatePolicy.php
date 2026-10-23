<?php

namespace App\Modules\Industry\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Templates are system data: anyone with `industry_template.view` may read them; `configure` enables a template for the
 * company and sets its thresholds. Nobody edits or deletes the shared rows through the app.
 */
class IndustryTemplatePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'industry_template';
    }

    public function configure(User $user): bool
    {
        return $user->can('industry_template.configure');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
