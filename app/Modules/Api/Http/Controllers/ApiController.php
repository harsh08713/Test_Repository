<?php

namespace App\Modules\Api\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\LeadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared by the API controllers.
 *
 * Records are found **through the person's own reach** (LeadAccess for leads, DealAccess for deals, FollowupAgenda for follow-ups), never by a bare
 * id. A record of another company, another branch or another person's book is therefore simply "not found" (404) — the API does
 * not confirm that an id exists to someone who may not see it. Only once the record is in hand do the policies decide whether
 * *this action* on it is allowed (403).
 */
abstract class ApiController
{
    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @param  list<string>  $with */
    protected function lead(Request $request, int|string $id, array $with = [], bool $withTrashed = false): Lead
    {
        Gate::authorize('viewAny', Lead::class);

        $query = $withTrashed ? Lead::withTrashed() : Lead::query();

        return app(LeadAccess::class)->restrict($query, $this->user($request))->with($with)->whereKey((int) $id)->firstOrFail();
    }

    /** @param  list<string>  $with */
    protected function deal(Request $request, int|string $id, array $with = []): Deal
    {
        Gate::authorize('viewAny', Deal::class);

        return app(DealAccess::class)->restrict(Deal::query(), $this->user($request))->with($with)->whereKey((int) $id)->firstOrFail();
    }

    protected function followup(Request $request, int|string $id): Followup
    {
        Gate::authorize('viewAny', Followup::class);

        return app(FollowupAgenda::class)->query($this->user($request))->with(['assignee:id,name', 'outcome:id,name', 'subject'])->whereKey((int) $id)->firstOrFail();
    }
}
