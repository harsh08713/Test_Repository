<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads follow-ups for the list, the calendar and (later) the mobile API: whose a person may see, narrowed to one person
 * or one team, and a window of time for the calendar. It never widens anybody's reach — the filters only ever narrow what
 * SalesScope allows, so asking for a person or a team the actor does not manage returns nothing.
 */
final class FollowupAgenda
{
    /** The widest window the calendar may ask for (a month view shows six weeks). */
    public const MAX_WINDOW_DAYS = 62;

    public const MAX_EVENTS = 1000;

    public function __construct(private readonly SalesScope $scope) {}

    /**
     * The follow-ups $actor may see: their own, their teams', or everyone's — optionally only one person's or one team's.
     *
     * @return Builder<Followup>
     */
    public function query(User $actor, ?int $personId = null, ?int $teamId = null): Builder
    {
        $query = Followup::query();
        $visible = $this->scope->visibleUserIds($actor);

        if ($visible !== null) {
            $query->whereIn('assigned_to', $visible);
        }

        if ($personId !== null) {
            $query->where('assigned_to', $personId);
        }

        if ($teamId !== null) {
            $query->whereIn('assigned_to', $this->teamUserIds($actor, $teamId));
        }

        return $query;
    }

    /**
     * The teams $actor may filter by: the active teams they run, or every active team for someone who sees everyone.
     *
     * @return array<int, string> id => name
     */
    public function teams(User $actor): array
    {
        return SalesTeam::query()->where('status', 'active')
            ->when(! $this->scope->seesEveryone($actor), fn ($q) => $q->where('manager_user_id', $actor->getKey()))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Who is on a team today — its manager and its current members — or nobody when $actor may not filter by it.
     *
     * @return list<int>
     */
    public function teamUserIds(User $actor, int $teamId): array
    {
        if (! array_key_exists($teamId, $this->teams($actor))) {
            return [];
        }

        $today = now()->toDateString();
        $members = SalesTeamMember::query()->where('sales_team_id', $teamId)->where('joined_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $today))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $manager = (int) SalesTeam::query()->whereKey($teamId)->value('manager_user_id');

        return array_values(array_unique([...$members, $manager]));
    }

    /**
     * What the calendar shows between two moments: planned and done follow-ups (not the ones called off), oldest first,
     * each with the lead it belongs to.
     *
     * @return Collection<int, Followup>
     */
    public function between(User $actor, CarbonInterface $from, CarbonInterface $to, ?int $personId = null, ?int $teamId = null): Collection
    {
        // A client that asks for years gets the first two months of them, not the whole table.
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $to = $from->copy()->addDays(self::MAX_WINDOW_DAYS);
        }

        return $this->query($actor, $personId, $teamId)
            ->with(['assignee:id,name', 'outcome:id,name', 'subject'])
            ->where('status', '!=', FollowupStatus::Cancelled->value)
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<', $to)
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(self::MAX_EVENTS)
            ->get();
    }

    /** What a follow-up is about, for a list or a calendar entry: the lead's name, or a neutral label if it is gone. */
    public function subjectLabel(Followup $followup): string
    {
        $subject = $followup->subject;

        return $subject instanceof Lead ? $subject->name : 'Record no longer available';
    }
}
