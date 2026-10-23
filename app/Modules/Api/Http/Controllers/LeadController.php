<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\LeadIndexRequest;
use App\Modules\Api\Http\Requests\LeadRequest;
use App\Modules\Api\Http\Requests\LeadStageRequest;
use App\Modules\Api\Http\Requests\LeadTransferRequest;
use App\Modules\Api\Http\Resources\FollowupResource;
use App\Modules\Api\Http\Resources\LeadResource;
use App\Modules\Api\Support\ApiException;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\FieldLeadService;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadFilters;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Shared\Rules\IndianMobile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Leads over the API: the same rules as the screens, because every write goes through the same services.
 *
 *  - **What a person sees** is LeadAccess (their own, their team's, everyone's for `sales.scope_all`, and leads they hold a
 *    planned follow-up on). Anything else is a 404.
 *  - **Entering a lead.** Someone who may mark attendance (`attendance.create`) enters leads *from the field*: they send
 *    their `position`, and FieldLeadService checks it against today's published route plan before anything is saved — outside
 *    the geofence the answer is 422 and the attempt is logged. The branch is the plan's, whatever the device says. Everyone
 *    else enters leads as ordinary typed-in leads, with a branch of their own.
 *  - **Sync.** `updated_since` returns everything changed at or after that moment, in change order — including leads removed
 *    since (their `deleted_at` is set) — and `meta.server_time` is the value to send next time.
 */
class LeadController extends ApiController
{
    private const WITH = ['source:id,name', 'assignee:id,name', 'location:id,code,name', 'state:id,name', 'district:id,name', 'block:id,name', 'lostReason:id,name', 'customer:id,code,name', 'deal:id,lead_id,deal_number,stage'];

    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadAccess $access,
    ) {}

    public function index(LeadIndexRequest $request, LeadFilters $filters): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $user = $this->user($request);
        $since = $request->since();
        $stage = (string) ($request->input('stage') ?: ($since === null ? 'open' : 'all'));
        $owner = (string) $request->input('owner', '');

        $query = $this->access->restrict($since === null ? Lead::query() : Lead::withTrashed(), $user);

        $query = $filters->apply($query, [
            'search' => (string) $request->input('q', ''),
            'owner' => $owner === 'me' ? (string) $user->getKey() : $owner,
            'branch' => (string) $request->input('branch', ''),
            'source' => (string) $request->input('source', ''),
            'type' => (string) $request->input('type', ''),
            'due' => (string) $request->input('due', ''),
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
        ])
            ->when($stage === 'open', fn (Builder $q) => $q->open())
            ->when(LeadStage::tryFrom($stage) !== null, fn (Builder $q) => $q->where('leads.stage', $stage))
            ->when($since !== null, fn (Builder $q) => $q->where('leads.updated_at', '>=', $since)->orderBy('leads.updated_at')->orderBy('leads.id'))
            ->when($since === null, fn (Builder $q) => $q->orderByDesc('leads.lead_date')->orderByDesc('leads.id'));

        $page = $query->with(self::WITH)->paginate($request->perPage());

        return ApiResponse::page($page, LeadResource::collection($page->getCollection())->resolve($request));
    }

    public function show(Request $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead, [...self::WITH, 'interests.item:id,code,name', 'interests.unit:id,code']);

        return ApiResponse::data([
            ...$this->present($model, $request),
            'followups' => FollowupResource::collection(
                $model->followups()->with(['assignee:id,name', 'outcome:id,name', 'subject'])->limit(30)->get(),
            )->resolve($request),
        ]);
    }

    public function store(LeadRequest $request, FieldLeadService $field): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        // an inquiry from an existing customer also needs the right to read the customer ledger
        if ($request->filled('customer_ledger_id')) {
            Gate::authorize('createInquiry', Lead::class);
        }

        $user = $this->user($request);
        $input = ['lead' => $request->leadFields(), 'interests' => $request->interestRows() ?? []];

        if ($user->can('attendance.create')) {
            // the plan decides the branch; whatever the device sent for it is not used
            unset($input['lead']['location_id']);
            $position = $request->position();

            $lead = $field->create($user, $input, $position['latitude'], $position['longitude'], $position['accuracy'], $request->userAgent());
        } else {
            $lead = $this->leads->create($input, $user, LeadChannel::Manual);
        }

        $lead = $this->lead($request, $lead->getKey(), [...self::WITH, 'interests.item:id,code,name', 'interests.unit:id,code']);

        return ApiResponse::data($this->present($lead, $request), 201, [], ['Location' => route('api.v1.leads.show', ['lead' => $lead->getKey()], false)]);
    }

    public function update(LeadRequest $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead, [...self::WITH, 'interests']);
        $this->assertEditable($model);
        $this->leads->assertUnlocked($model);
        Gate::authorize('update', $model);

        $user = $this->user($request);
        $expected = $request->input('expected_updated_at');

        // "I edited the copy I loaded at 10:00": if the lead has changed since, say so rather than overwrite it.
        if ($expected !== null && ApiTime::parse($expected)?->format('Y-m-d H:i:s') !== $model->updated_at?->format('Y-m-d H:i:s')) {
            throw ApiException::conflict('version_conflict', 'This lead was changed after you loaded it. Look at the current version and apply your change to that.', [
                'current' => $this->present($model, $request),
            ]);
        }

        $sent = $request->leadFields();
        $base = $this->fieldsOf($model);

        // a PIN code changed on its own re-fills the geography from the master, instead of contradicting the old one
        if (array_key_exists('pin_code', $sent) && ! array_intersect(['state_id', 'district_id', 'block_id'], array_keys($sent))) {
            $base = [...$base, 'state_id' => null, 'district_id' => null, 'block_id' => null];
        }

        $merged = [...$base, ...$sent];

        // the mobile number was accepted when the lead was entered; only a *different* number is checked for duplicates again
        $sameMobile = ! array_key_exists('mobile', $sent) || IndianMobile::normalise((string) $sent['mobile']) === $model->mobile;
        $merged['acknowledge_duplicate'] = $sameMobile || ($sent['acknowledge_duplicate'] ?? false);

        $rows = $request->interestRows() ?? $model->interests->map(fn ($row) => [
            'id' => $row->getKey(), 'item_id' => $row->item_id, 'description' => $row->description, 'quantity' => $row->quantity, 'unit_id' => $row->unit_id,
            'expected_rate' => $row->expected_rate,
        ])->all();

        $this->leads->update($model, ['lead' => $merged, 'interests' => $rows], $user);

        return ApiResponse::data($this->present($this->lead($request, $lead, [...self::WITH, 'interests.item:id,code,name', 'interests.unit:id,code']), $request));
    }

    public function destroy(Request $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead);
        $this->assertNotConverted($model, 'deleted');
        Gate::authorize('delete', $model);

        $this->leads->delete($model, $this->user($request));

        return ApiResponse::data(['deleted' => true, 'id' => $model->getKey()]);
    }

    public function stage(LeadStageRequest $request, string $lead): JsonResponse
    {
        $model = $this->lead($request, $lead);
        $this->assertNotConverted($model, 'moved to another stage');
        Gate::authorize('changeStage', $model);

        $this->leads->changeStage(
            $model, LeadStage::from((string) $request->input('stage')), $this->user($request),
            $request->filled('comment') ? (string) $request->input('comment') : null,
            $request->filled('lost_reason_id') ? (int) $request->input('lost_reason_id') : null,
        );

        return ApiResponse::data($this->present($this->lead($request, $lead, [...self::WITH, 'interests.item:id,code,name', 'interests.unit:id,code']), $request));
    }

    /** Hand one lead or many to another person, all-or-nothing, with a reason. LeadTransferService checks every lead and the new owner. */
    public function transfer(LeadTransferRequest $request, LeadTransferService $transfers): JsonResponse
    {
        Gate::authorize('transferAny', Lead::class);

        $moved = $transfers->transfer(
            array_map('intval', (array) $request->input('lead_ids')), (int) $request->input('to_user_id'), (string) $request->input('reason'), $this->user($request),
        );

        // read them again with what the response shows, oldest first
        $leads = Lead::query()->with(self::WITH)->whereKey($moved->map(fn (Lead $lead) => $lead->getKey())->all())->orderBy('id')->get();

        return ApiResponse::data(LeadResource::collection($leads)->resolve($request));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /** Only a lead still being worked is edited by hand (the policy says the same; this says why, as a 409). */
    private function assertEditable(Lead $lead): void
    {
        if (! in_array($lead->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true)) {
            throw new FieldRuleException('A lead that is '.strtolower($lead->stage->label()).' can no longer be edited.');
        }
    }

    /** A lead that has been converted (deal, quotation, won) is the system's to move: say so rather than a bare 403. */
    private function assertNotConverted(Lead $lead, string $what): void
    {
        if ($lead->stage->isSetBySystem()) {
            throw new FieldRuleException('A lead that is '.strtolower($lead->stage->label())." has been converted, so it cannot be {$what}.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Lead $lead, Request $request): array
    {
        $user = $this->user($request);

        return LeadResource::make($lead)->additional(['permissions' => [
            'update' => $user->can('update', $lead),
            'change_stage' => $user->can('changeStage', $lead),
            'transfer' => $user->can('transfer', $lead),
            'delete' => $user->can('delete', $lead),
            'follow_up' => $user->can('followUp', $lead),
            'convert' => $user->can('convert', $lead),
            'submit' => $user->can('submit', $lead),
        ]])->resolve($request);
    }

    /**
     * The lead's own values in the shape the lead service takes — the starting point of a partial change.
     *
     * @return array<string, mixed>
     */
    private function fieldsOf(Lead $lead): array
    {
        return [
            'lead_date' => $lead->lead_date->toDateString(), 'lead_source_id' => $lead->lead_source_id, 'name' => $lead->name,
            'contact_person' => $lead->contact_person, 'mobile' => $lead->mobile, 'phone' => $lead->phone, 'email' => $lead->email,
            'address_line1' => $lead->address_line1, 'address_line2' => $lead->address_line2, 'city' => $lead->city,
            'state_id' => $lead->state_id, 'district_id' => $lead->district_id, 'block_id' => $lead->block_id, 'pin_code' => $lead->pin_code,
            'requirement' => $lead->requirement, 'expected_value' => $lead->expected_value, 'remarks' => $lead->remarks,
        ];
    }
}
