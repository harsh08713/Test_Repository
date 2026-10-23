<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\CustomerLookupRequest;
use App\Modules\Api\Http\Requests\LookupRequest;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Rules\IndianPinCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The reference data an app keeps a copy of to enter leads: items (approved and active only), units, lead sources, follow-up
 * outcomes, lost reasons, the person's branches and the people they may hand leads to — and a PIN-code lookup.
 *
 * Lists take `updated_since`: then they return what changed at or after that moment, **including rows that have been switched
 * off** (flagged `active: false`) so the copy can drop them; without it, only what is in use now. For items, the ones that
 * stopped being usable come back as ids in `meta.removed_ids` — nothing else about an unapproved item ever leaves the server.
 */
class LookupController extends ApiController
{
    public function items(LookupRequest $request, ItemLookup $items): JsonResponse
    {
        Gate::authorize('viewAny', Item::class);

        $since = $request->since();
        $page = $items->page((string) $request->input('q', ''), $since, $request->perPage());

        return ApiResponse::page(
            $page,
            $page->getCollection()->map(fn (Item $item) => [...$items->present($item), 'active' => true, 'updated_at' => ApiTime::iso($item->updated_at)])->values()->all(),
            $since === null ? [] : ['removed_ids' => $items->unusableSince($since)],
        );
    }

    /**
     * `GET /api/v1/lookups/customers?q=` — approved, active customers that match a few letters, for naming one on an inquiry. A search
     * only (the app does not keep a copy of the ledger), and only what picking one needs: never credit terms or bank details.
     */
    public function customers(CustomerLookupRequest $request, LedgerLookup $customers): JsonResponse
    {
        Gate::authorize('viewAny', Ledger::class);

        $found = $customers->search(LedgerType::Customer, (string) $request->input('q'), (int) ($request->input('limit') ?: LedgerLookup::DEFAULT_LIMIT));

        return ApiResponse::data($found->map(fn (Ledger $customer) => [
            'id' => $customer->getKey(), 'code' => $customer->code, 'name' => $customer->name, 'gstin' => $customer->gstin,
        ])->values()->all());
    }

    public function units(LookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return $this->simple($request, Unit::query(), fn (Unit $unit) => ['decimal_allowed' => $unit->decimal_allowed]);
    }

    public function leadSources(LookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return $this->simple($request, LeadSource::query(), fn (LeadSource $source) => []);
    }

    public function followupOutcomes(LookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return $this->simple($request, FollowupOutcome::query(), fn (FollowupOutcome $outcome) => [
            'counts_as_contact' => (bool) $outcome->counts_as_contact, 'needs_next_followup' => (bool) $outcome->needs_next_followup,
        ]);
    }

    public function lostReasons(LookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return $this->simple($request, LostReason::query(), fn (LostReason $reason) => []);
    }

    /** The branches the person works in (inside the company the request is working in). */
    public function branches(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return ApiResponse::data(Location::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Location $branch) => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])->values()->all());
    }

    /** The people the caller may hand a lead to: themselves and the people they manage (everyone, with `sales.scope_all`). */
    public function staff(Request $request, SalesTeamService $teams): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $people = $teams->assignees($this->user($request));

        return ApiResponse::data(collect($people)->map(fn (string $name, int|string $id) => ['id' => (int) $id, 'name' => $name])->values()->all());
    }

    /**
     * `GET /api/v1/lookups/pin-codes/{pin}` — what the geography master knows about a PIN code: the state, district and block
     * where the PIN maps to exactly one, and every locality for a "pick one" prompt. `found: false` when the PIN is unknown.
     */
    public function pinCode(Request $request, string $pin, PinCodeLookup $pins): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        if (! IndianPinCode::isValid($pin)) {
            throw ValidationException::withMessages(['pin' => 'A PIN code is six digits and does not start with 0.']);
        }

        $found = $pins->resolve($pin);

        return ApiResponse::data([
            'pin_code' => $found->pin,
            'found' => $found->found,
            'ambiguous' => $found->isAmbiguous(),
            'state' => $found->state === null ? null : ['id' => $found->state->getKey(), 'name' => $found->state->name],
            'district' => $found->district === null ? null : ['id' => $found->district->getKey(), 'name' => $found->district->name],
            'block' => $found->block === null ? null : ['id' => $found->block->getKey(), 'name' => $found->block->name],
            'localities' => $found->localities->map(fn (PinCode $row) => [
                'area' => $row->area,
                'district' => $row->district === null ? null : ['id' => $row->district->getKey(), 'name' => $row->district->name],
                'block' => $row->block === null ? null : ['id' => $row->block->getKey(), 'name' => $row->block->name],
            ])->values()->all(),
        ]);
    }

    /**
     * A plain master (no approval workflow) as a page: what is in use now, or — with `updated_since` — what changed, in change
     * order, whether or not it is still in use.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $extra
     */
    private function simple(LookupRequest $request, Builder $query, callable $extra): JsonResponse
    {
        $since = $request->since();
        $term = trim((string) $request->input('q', ''));

        $page = $query
            ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($since === null, fn (Builder $q) => $q->where('status', 'active')->orderBy('name'))
            ->when($since !== null, fn (Builder $q) => $q->where('updated_at', '>=', $since)->orderBy('updated_at'))
            ->orderBy('id')
            ->paginate($request->perPage());

        return ApiResponse::page($page, $page->getCollection()->map(fn (Model $row) => [
            'id' => $row->getKey(),
            'code' => $row->getAttribute('code'),
            'name' => $row->getAttribute('name'),
            ...$extra($row),
            'active' => $row->getAttribute('status') === 'active',
            'updated_at' => ApiTime::iso($row->getAttribute('updated_at')),
        ])->values()->all());
    }
}
