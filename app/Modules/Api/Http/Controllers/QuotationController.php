<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\QuotationIndexRequest;
use App\Modules\Api\Http\Resources\QuotationResource;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Quotations over the API — **read only**. A quotation is priced, taxed, approved, sent and revised on the screens, through
 * QuotationService; the app lists and shows them so a salesperson can look at what a customer was offered.
 *
 *  - **What a person sees** is QuotationAccess (those of their own leads, their team's, everyone's for `sales.scope_all`, and the ones an
 *    approver is asked about). Anything else is a 404.
 *  - **Sync.** `updated_since` returns everything changed at or after that moment, in change order; `meta.server_time` is the value to
 *    send next time. (A quotation is never deleted, so there is nothing to drop.)
 */
class QuotationController extends ApiController
{
    private const WITH = ['location:id,code,name', 'deal:id,deal_number', 'priceList:id,name', 'placeOfSupply:id,name,gst_code'];

    private const DETAIL = [...self::WITH, 'items.unit:id,code', 'stateHistory.actor:id,name'];

    public function __construct(private readonly QuotationAccess $access) {}

    public function index(QuotationIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Quotation::class);

        $since = $request->since();
        $state = (string) ($request->input('state') ?: ($since === null ? 'live' : 'all'));
        $term = trim((string) $request->input('q', ''));

        $query = $this->access->restrict(Quotation::query(), $this->user($request))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $q->where(fn (Builder $w) => $w->where('quotations.party_name', 'like', $like)->orWhere('quotations.quotation_number', 'like', $like)->orWhere('quotations.contact_person', 'like', $like));
            })
            ->when($request->filled('deal_id'), fn (Builder $q) => $q->where('quotations.deal_id', (int) $request->input('deal_id')))
            ->when($request->filled('lead_id'), fn (Builder $q) => $q->where('quotations.lead_id', (int) $request->input('lead_id')))
            ->when($request->filled('branch'), fn (Builder $q) => $q->where('quotations.location_id', (int) $request->input('branch')))
            ->when($request->filled('approval'), fn (Builder $q) => $q->where('quotations.approval_status', (string) $request->input('approval')))
            ->when($request->boolean('latest'), fn (Builder $q) => $q->currentRevisions())
            ->when($state === 'live', fn (Builder $q) => $q->whereIn('quotations.state', ['draft', 'sent', 'accepted']))
            ->when(! in_array($state, ['live', 'all'], true), fn (Builder $q) => $q->where('quotations.state', $state))
            ->when($since !== null, fn (Builder $q) => $q->where('quotations.updated_at', '>=', $since)->orderBy('quotations.updated_at')->orderBy('quotations.id'))
            ->when($since === null, fn (Builder $q) => $q->orderByDesc('quotations.id'));

        $page = $query->with(self::WITH)->paginate($request->perPage());

        return ApiResponse::page($page, QuotationResource::collection($page->getCollection())->resolve($request));
    }

    public function show(Request $request, string $quotation): JsonResponse
    {
        Gate::authorize('viewAny', Quotation::class);

        $model = $this->access->restrict(Quotation::query(), $this->user($request))->with(self::DETAIL)->whereKey((int) $quotation)->firstOrFail();

        return ApiResponse::data((new QuotationResource($model))->resolve($request));
    }
}
