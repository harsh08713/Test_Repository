<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\DealIndexRequest;
use App\Modules\Api\Http\Requests\DealReassignRequest;
use App\Modules\Api\Http\Requests\DealRequest;
use App\Modules\Api\Http\Requests\DealStageRequest;
use App\Modules\Api\Http\Resources\DealResource;
use App\Modules\Api\Support\ApiException;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\DealService;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Deals over the API: the same rules as the screens, because every write goes through DealService (and approval through
 * ApprovalService).
 *
 *  - **What a person sees** is DealAccess (their own, their team's, everyone's for `sales.scope_all`, and the deals an approver is
 *    asked about). Anything else is a 404.
 *  - **Making a deal** is converting a qualified lead: `POST /leads/{lead}/convert`. The lead's own lines must all come across,
 *    each with the rate expected; the number, the amounts, the value, the branch and the owner are the server's.
 *  - **Changing a deal.** The customer and the lines change only while the deal is a draft or was returned; where it stands (close
 *    date, probability, remarks) while it is open. What the state forbids is a 409 with the reason, not a bare 403.
 *  - **Sync.** `updated_since` returns everything changed at or after that moment, in change order; `meta.server_time` is the
 *    value to send next time. (A deal is never deleted, so there is nothing to drop.)
 */
class DealController extends ApiController
{
    private const WITH = ['assignee:id,name', 'location:id,code,name', 'customer:id,code,name', 'lead:id,lead_number', 'lostReason:id,name'];

    private const DETAIL = [...self::WITH, 'items.item:id,code,name', 'items.unit:id,code', 'stageHistory.actor:id,name', 'stageHistory.lostReason:id,name'];

    public function __construct(
        private readonly DealService $deals,
        private readonly DealAccess $access,
    ) {}

    public function index(DealIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);

        $user = $this->user($request);
        $since = $request->since();
        $stage = (string) ($request->input('stage') ?: ($since === null ? 'open' : 'all'));
        $owner = (string) $request->input('owner', '');
        $term = trim((string) $request->input('q', ''));

        $query = $this->access->restrict(Deal::query(), $user)
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $term) ?? '';

                $q->where(fn (Builder $w) => $w->where('deals.party_name', 'like', $like)->orWhere('deals.deal_number', 'like', $like)->orWhere('deals.contact_person', 'like', $like)
                    ->when(strlen($digits) >= 4, fn (Builder $m) => $m->orWhere('deals.mobile', 'like', '%'.substr($digits, -10).'%')));
            })
            ->when($owner === 'me', fn (Builder $q) => $q->where('deals.assigned_to', $user->getKey()))
            ->when(ctype_digit($owner), fn (Builder $q) => $q->where('deals.assigned_to', (int) $owner))
            ->when(ctype_digit((string) $request->input('branch', '')), fn (Builder $q) => $q->where('deals.location_id', (int) $request->input('branch')))
            ->when($request->filled('approval'), fn (Builder $q) => $q->where('deals.approval_status', (string) $request->input('approval')))
            ->when($request->input('closing') === 'late', fn (Builder $q) => $q->open()->where('deals.expected_close_date', '<', now()->toDateString()))
            ->when($request->input('closing') === 'month', fn (Builder $q) => $q->whereBetween('deals.expected_close_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]))
            ->when($stage === 'open', fn (Builder $q) => $q->open())
            ->when(DealStage::tryFrom($stage) !== null, fn (Builder $q) => $q->where('deals.stage', $stage))
            ->when($since !== null, fn (Builder $q) => $q->where('deals.updated_at', '>=', $since)->orderBy('deals.updated_at')->orderBy('deals.id'))
            ->when($since === null, fn (Builder $q) => $q->orderBy('deals.expected_close_date')->orderByDesc('deals.id'));

        $page = $query->with(self::WITH)->paginate($request->perPage());

        return ApiResponse::page($page, DealResource::collection($page->getCollection())->resolve($request));
    }

    public function show(Request $request, string $deal): JsonResponse
    {
        return ApiResponse::data($this->present($this->deal($request, $deal, self::DETAIL), $request));
    }

    /** Turn a qualified lead into a deal — once. DealService checks the lead, the branch, the lines and the numbers; a refusal is a 409 or a 422 with its reason. */
    public function convert(DealRequest $request, string $lead): JsonResponse
    {
        Gate::authorize('create', Deal::class);

        $user = $this->user($request);
        $source = $this->lead($request, $lead);

        $made = $this->deals->convert($source, ['deal' => $request->dealFields(), 'items' => $request->itemRows() ?? []], $user);

        $made = $this->deal($request, $made->getKey(), self::DETAIL);

        return ApiResponse::data($this->present($made, $request), 201, [], ['Location' => route('api.v1.deals.show', ['deal' => $made->getKey()], false)]);
    }

    public function update(DealRequest $request, string $deal): JsonResponse
    {
        $model = $this->deal($request, $deal, ['items']);
        Gate::authorize('work', $model);

        $user = $this->user($request);
        $expected = $request->input('expected_updated_at');

        // "I edited the copy I loaded at 10:00": if the deal has changed since, say so rather than overwrite it.
        if ($expected !== null && ApiTime::parse($expected)?->format('Y-m-d H:i:s') !== $model->updated_at?->format('Y-m-d H:i:s')) {
            throw ApiException::conflict('version_conflict', 'This deal was changed after you loaded it. Look at the current version and apply your change to that.', [
                'current' => $this->present($this->deal($request, $deal, self::DETAIL), $request),
            ]);
        }

        $sent = $request->dealFields();
        $base = [
            'customer_ledger_id' => $model->customer_ledger_id, 'expected_close_date' => $model->expected_close_date->toDateString(),
            'probability' => $model->probability, 'remarks' => $model->remarks,
        ];

        if ($request->has('items') || array_key_exists('customer_ledger_id', $sent)) {
            // what the deal is: its customer and lines. With no new lines, the lines it has now stay as they are.
            $rows = $request->itemRows() ?? $model->items->map(fn (DealItem $line) => [
                'id' => $line->getKey(), 'item_id' => $line->item_id, 'unit_id' => $line->unit_id, 'quantity' => $line->quantity, 'expected_rate' => $line->expected_rate,
            ])->all();

            $this->deals->update($model, ['deal' => [...$base, ...$sent], 'items' => $rows], $user);
        } else {
            // where it stands
            $this->deals->updateProgress($model, ['deal' => [...$base, ...$sent]], $user);
        }

        return ApiResponse::data($this->present($this->deal($request, $deal, self::DETAIL), $request));
    }

    /** Move a deal between discovery and negotiation, mark it lost, or bring a lost one back (which takes its own permission). */
    public function stage(DealStageRequest $request, string $deal): JsonResponse
    {
        $model = $this->deal($request, $deal);
        $to = DealStage::from((string) $request->input('stage'));

        Gate::authorize($model->stage === DealStage::Lost && $to !== DealStage::Lost ? 'reopenLost' : 'work', $model);

        $this->deals->changeStage(
            $model, $to, $this->user($request),
            $request->filled('comment') ? (string) $request->input('comment') : null,
            $request->filled('lost_reason_id') ? (int) $request->input('lost_reason_id') : null,
        );

        return ApiResponse::data($this->present($this->deal($request, $deal, self::DETAIL), $request));
    }

    /** Send a draft or returned deal for approval. A decision is taken in the approval inbox, not here. */
    public function submit(Request $request, string $deal, ApprovalService $approvals): JsonResponse
    {
        $model = $this->deal($request, $deal);
        Gate::authorize('work', $model);

        $approvals->submit($model, $this->user($request));

        return ApiResponse::data($this->present($this->deal($request, $deal, self::DETAIL), $request));
    }

    /** Hand the deal, and its lead with it, to another person: the same checks as handing a lead over. */
    public function reassign(DealReassignRequest $request, string $deal): JsonResponse
    {
        $model = $this->deal($request, $deal);
        Gate::authorize('assign', $model);

        $this->deals->reassign($model, (int) $request->input('to_user_id'), (string) $request->input('reason'), $this->user($request));

        // the deal may no longer be one the person can open: say what happened rather than a 404
        $now = $this->access->restrict(Deal::query(), $this->user($request))->with(self::DETAIL)->whereKey($model->getKey())->first();

        return ApiResponse::data($now === null
            ? ['id' => $model->getKey(), 'handed_over' => true]
            : $this->present($now, $request));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function present(Deal $deal, Request $request): array
    {
        $user = $this->user($request);

        return DealResource::make($deal)->additional(['permissions' => [
            'update' => $user->can('update', $deal),
            'update_progress' => $user->can('updateProgress', $deal),
            'change_stage' => $user->can('changeStage', $deal),
            'mark_lost' => $user->can('markLost', $deal),
            'reopen_lost' => $user->can('reopenLost', $deal),
            'submit' => $user->can('submit', $deal),
            'reopen_approval' => $user->can('reopenApproval', $deal),
            'reassign' => $user->can('reassign', $deal),
        ]])->resolve($request);
    }
}
