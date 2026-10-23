<?php

namespace App\Modules\CRM\Http\Livewire\Concerns;

use App\Models\User;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * For screens that list people's records: only the signed-in person's own and those of the people they manage (everyone's
 * for a holder of `sales.scope_all`). The policies re-check every record; this keeps lists and filters from offering more.
 */
trait ScopesToPeople
{
    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return list<int>|null null = everyone */
    protected function visibleIds(): ?array
    {
        return app(SalesScope::class)->visibleUserIds($this->actor());
    }

    /**
     * Active people the signed-in user may pick: their own team, or everyone, plus themselves.
     *
     * @return array<int, string> id => name
     */
    protected function visiblePeople(): array
    {
        $staff = app(SalesTeamService::class)->staff();
        $ids = $this->visibleIds();

        return $ids === null ? $staff : array_intersect_key($staff, array_flip($ids));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function limitToVisible(Builder $query, string $column = 'user_id'): Builder
    {
        $ids = $this->visibleIds();

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }
}
