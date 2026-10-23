<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\FollowupIndexRequest;
use App\Modules\Api\Http\Requests\FollowupWriteRequest;
use App\Modules\Api\Http\Resources\FollowupResource;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\FollowupService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Follow-ups over the API. Reading goes through FollowupAgenda (a person's own, their teams', or everyone's — and the filters
 * only narrow that), writing through FollowupService, the same as the screens. Times are ISO 8601; a time without an offset is
 * company wall-clock time.
 */
class FollowupController extends ApiController
{
    public function __construct(
        private readonly FollowupService $followups,
        private readonly FollowupAgenda $agenda,
    ) {}

    public function index(FollowupIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Followup::class);

        $user = $this->user($request);
        $status = (string) $request->input('status', 'all');
        $since = $request->since();
        $now = now();
        $morph = (new Lead)->getMorphClass();

        $query = $this->agenda->query($user, $request->filled('person') ? (int) $request->input('person') : null, $request->filled('team') ? (int) $request->input('team') : null)
            ->with(['assignee:id,name', 'outcome:id,name', 'subject'])
            ->when(FollowupStatus::tryFrom($status) !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($status === 'overdue', fn (Builder $q) => $q->pending()->where('scheduled_at', '<', $now))
            ->when($status === 'today', fn (Builder $q) => $q->pending()->whereBetween('scheduled_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->input('type')))
            ->when($request->filled('lead_id'), fn (Builder $q) => $q->where('subject_type', $morph)->where('subject_id', (int) $request->input('lead_id')))
            ->when(ApiTime::parse($request->input('from')) !== null, fn (Builder $q) => $q->where('scheduled_at', '>=', ApiTime::parse($request->input('from'))))
            ->when(ApiTime::parse($request->input('to')) !== null, fn (Builder $q) => $q->where('scheduled_at', '<', ApiTime::parse($request->input('to'))));

        if ($since !== null) {
            $query->where('updated_at', '>=', $since)->orderBy('updated_at')->orderBy('id');   // change order, for syncing
        } elseif (in_array($status, ['pending', 'overdue', 'today'], true)) {
            $query->orderBy('scheduled_at')->orderBy('id');                                    // what is next, first
        } else {
            $query->orderByDesc('scheduled_at')->orderByDesc('id');
        }

        $page = $query->paginate($request->perPage());

        return ApiResponse::page($page, FollowupResource::collection($page->getCollection())->resolve($request));
    }

    /** What the calendar shows between two moments (at most 62 days, 1,000 entries); called-off follow-ups are left out. */
    public function calendar(FollowupIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Followup::class);

        $from = ApiTime::parse($request->input('from'));
        $to = ApiTime::parse($request->input('to'));
        abort_if($from === null || $to === null, 422);

        $capped = $to->lt($from) || $from->diffInDays($to) > FollowupAgenda::MAX_WINDOW_DAYS;
        $window = $capped ? $from->copy()->addDays(FollowupAgenda::MAX_WINDOW_DAYS) : $to;

        $events = $this->agenda->between(
            $this->user($request), $from, $to,
            $request->filled('person') ? (int) $request->input('person') : null,
            $request->filled('team') ? (int) $request->input('team') : null,
        );

        return ApiResponse::data(FollowupResource::collection($events)->resolve($request), 200, [
            'window' => ['from' => ApiTime::iso($from), 'to' => ApiTime::iso($window), 'capped' => $capped],
            'truncated' => $events->count() >= FollowupAgenda::MAX_EVENTS,
        ]);
    }

    public function plan(FollowupWriteRequest $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead);
        $this->assertLeadOpen($model);
        Gate::authorize('followUp', $model);

        $followup = $this->followups->schedule($model, ['plan' => [
            'scheduled_at' => ApiTime::wall($request->input('scheduled_at')),
            'type' => $request->input('type'),
            'assigned_to' => $request->input('assigned_to'),
            'notes' => $request->input('notes'),
        ]], $this->user($request));

        return $this->created($followup, $request);
    }

    public function log(FollowupWriteRequest $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead);
        $this->assertLeadOpen($model);
        Gate::authorize('followUp', $model);

        $followup = $this->followups->log($model, ['log' => [
            'type' => $request->input('type'),
            'outcome_id' => $request->input('outcome_id'),
            'notes' => $request->input('notes'),
            'next_at' => ApiTime::wall($request->input('next_at')),
            'next_type' => $request->input('next_type'),
        ]], $this->user($request));

        return $this->created($followup, $request);
    }

    public function complete(FollowupWriteRequest $request, string $followup): JsonResponse
    {
        $model = $this->followup($request, $followup);
        $this->assertPlanned($model);
        Gate::authorize('update', $model);

        $done = $this->followups->complete($model, ['done' => [
            'outcome_id' => $request->input('outcome_id'),
            'notes' => $request->input('notes'),
            'next_at' => ApiTime::wall($request->input('next_at')),
            'next_type' => $request->input('next_type'),
        ]], $this->user($request));

        return $this->present($done, $request);
    }

    public function reschedule(FollowupWriteRequest $request, string $followup): JsonResponse
    {
        $model = $this->followup($request, $followup);
        $this->assertPlanned($model);
        Gate::authorize('update', $model);

        return $this->present($this->followups->reschedule($model, ApiTime::wall($request->input('scheduled_at')), $this->user($request)), $request);
    }

    public function cancel(FollowupWriteRequest $request, string $followup): JsonResponse
    {
        $model = $this->followup($request, $followup);
        $this->assertPlanned($model);
        Gate::authorize('update', $model);

        return $this->present($this->followups->cancel($model, (string) $request->input('reason'), $this->user($request)), $request);
    }

    /** A lead that has been won or lost takes no more follow-ups: say so (409), rather than let the policy answer a bare 403. */
    private function assertLeadOpen(Lead $lead): void
    {
        if (! $lead->isOpen()) {
            throw new FieldRuleException('This lead is '.strtolower($lead->stage->label()).', so nothing more can be planned or recorded on it.');
        }
    }

    /** Only a planned follow-up can be completed, moved or called off; one that is done or called off is a record. */
    private function assertPlanned(Followup $followup): void
    {
        if (! $followup->isPending()) {
            throw new FieldRuleException('This follow-up is already '.strtolower($followup->status->label()).'.');
        }
    }

    private function created(Followup $followup, Request $request): JsonResponse
    {
        return ApiResponse::data(FollowupResource::make($followup->load(['assignee:id,name', 'outcome:id,name', 'subject']))->resolve($request), 201);
    }

    private function present(Followup $followup, Request $request): JsonResponse
    {
        return ApiResponse::data(FollowupResource::make($followup->load(['assignee:id,name', 'outcome:id,name', 'subject']))->resolve($request));
    }
}
