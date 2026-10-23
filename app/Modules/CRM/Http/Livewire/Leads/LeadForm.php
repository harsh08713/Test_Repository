<?php

namespace App\Modules\CRM\Http\Livewire\Leads;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\CampaignAttribution;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Rules\IndianMobile;
use App\Modules\Shared\Rules\IndianPinCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Enter a lead by hand, or correct one. The screen only holds what was typed: every rule — the branch, who may own the
 * lead, the duplicate check, what a PIN code fills in — lives in LeadService, which checks the data again whatever the
 * form sent. What the screen adds is help while typing: the PIN code fills the state, district and block, and a mobile
 * number that is already a lead is pointed out before the save is attempted.
 */
#[Title('Lead')]
class LeadForm extends Component
{
    use ScopesToPeople;

    public const MAX_SEARCH_RESULTS = 8;

    #[Locked]
    public ?int $leadId = null;

    /** @var array<string, mixed> */
    public array $lead = [];

    /** @var list<array<string, mixed>> */
    public array $interests = [];

    public string $pinHint = '';

    /**
     * Which campaign the lead came from, and its UTM values — filled from the link a campaign sends people to (`?campaign=CP/…&utm_source=…`) or chosen by hand.
     *
     * @var array<string, string>
     */
    public array $attribution = ['campaign_id' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => ''];

    #[Locked]
    public bool $fromLink = false;

    public function mount(?Lead $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            // an inquiry from an existing customer has its own form
            if ($editing->isExisting()) {
                $this->redirectRoute('crm.leads.edit-inquiry', $editing, navigate: false);

                return;
            }

            $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

            $this->leadId = $editing->id;
            $this->lead = [
                'location_id' => (string) $editing->location_id, 'lead_date' => $editing->lead_date->toDateString(),
                'lead_source_id' => (string) $editing->lead_source_id, 'name' => $editing->name, 'contact_person' => $str($editing->contact_person),
                'mobile' => $str($editing->mobile), 'phone' => $str($editing->phone), 'email' => $str($editing->email),
                'address_line1' => $str($editing->address_line1), 'address_line2' => $str($editing->address_line2), 'city' => $str($editing->city),
                'state_id' => $str($editing->state_id), 'district_id' => $str($editing->district_id), 'block_id' => $str($editing->block_id),
                'pin_code' => $str($editing->pin_code), 'requirement' => $str($editing->requirement),
                'expected_value' => $editing->expected_value === null ? '' : rtrim(rtrim((string) $editing->expected_value, '0'), '.'),
                'assigned_to' => $str($editing->assigned_to), 'remarks' => $str($editing->remarks), 'acknowledge_duplicate' => false,
            ];
            $this->interests = $editing->interests()->with(['item:id,code,name'])->get()->map(fn (LeadInterest $row) => [
                'key' => Str::random(6), 'id' => (string) $row->id, 'item_id' => $str($row->item_id),
                'item_label' => $row->item === null ? '' : trim($row->item->code.' · '.$row->item->name),
                'description' => $str($row->description), 'quantity' => $row->quantity === null ? '' : rtrim(rtrim((string) $row->quantity, '0'), '.'),
                'unit_id' => $str($row->unit_id), 'search' => '',
            ])->all();

            return;
        }

        Gate::authorize('create', Lead::class);

        $branches = Location::query()->where('status', 'active')->pluck('id');

        $this->lead = [
            'location_id' => $branches->count() === 1 ? (string) $branches->first() : '', 'lead_date' => now()->toDateString(), 'lead_source_id' => '',
            'name' => '', 'contact_person' => '', 'mobile' => '', 'phone' => '', 'email' => '', 'address_line1' => '', 'address_line2' => '', 'city' => '',
            'state_id' => '', 'district_id' => '', 'block_id' => '', 'pin_code' => '', 'requirement' => '', 'expected_value' => '',
            'assigned_to' => (string) $this->actor()->getKey(), 'remarks' => '', 'acknowledge_duplicate' => false,
        ];
        $this->addInterest();
        $this->readCampaignLink();
    }

    // ---- help while typing ----------------------------------------------------------------------------------

    public function updatedLead(mixed $value, string $field): void
    {
        if ($field === 'pin_code') {
            $this->fillFromPin(trim((string) $value));
        }

        // a district belongs to one state and a block to one district: a different parent invalidates the child
        if ($field === 'state_id') {
            $this->lead['district_id'] = '';
            $this->lead['block_id'] = '';
        }

        if ($field === 'district_id') {
            $this->lead['block_id'] = '';
        }

        // a different number is a different question
        if ($field === 'mobile') {
            $this->lead['acknowledge_duplicate'] = false;
        }
    }

    /** A PIN code the geography master knows fills the state, the district and — where there is only one — the block. */
    private function fillFromPin(string $pin): void
    {
        $this->pinHint = '';

        if (! IndianPinCode::isValid($pin)) {
            return;
        }

        $result = app(PinCodeLookup::class)->resolve($pin);

        if (! $result->found) {
            $this->pinHint = 'This PIN code is not in the geography master — choose the state and district yourself.';

            return;
        }

        if ($result->state !== null) {
            $this->lead['state_id'] = (string) $result->state->id;
        }

        if ($result->district !== null) {
            $this->lead['district_id'] = (string) $result->district->id;
            $this->lead['block_id'] = $result->block === null ? '' : (string) $result->block->id;

            if (trim((string) ($this->lead['city'] ?? '')) === '') {
                $this->lead['city'] = $result->district->name;
            }
        }

        $this->pinHint = $result->isAmbiguous()
            ? 'This PIN code covers more than one district or block, so only the parts that are certain were filled in.'
            : 'State, district and block filled in from the PIN code.';
    }

    // ---- items of interest ----------------------------------------------------------------------------------

    public function addInterest(): void
    {
        if (count($this->interests) < LeadService::MAX_INTERESTS) {
            $this->interests[] = ['key' => Str::random(6), 'id' => '', 'item_id' => '', 'item_label' => '', 'description' => '', 'quantity' => '', 'unit_id' => '', 'search' => ''];
        }
    }

    public function removeInterest(int $index): void
    {
        unset($this->interests[$index]);
        $this->interests = array_values($this->interests);
    }

    /** Pick an item from the search results. Only approved, active items can be picked (ItemLookup). */
    public function pickItem(int $index, int $id): void
    {
        if (! isset($this->interests[$index])) {
            return;
        }

        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->interests[$index] = [
                ...$this->interests[$index],
                'item_id' => (string) $item->id, 'item_label' => trim($item->code.' · '.$item->name), 'unit_id' => (string) $item->base_unit_id, 'search' => '',
            ];
        }
    }

    public function clearItem(int $index): void
    {
        if (isset($this->interests[$index])) {
            $this->interests[$index] = [...$this->interests[$index], 'item_id' => '', 'item_label' => '', 'unit_id' => '', 'search' => ''];
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(LeadService $service): void
    {
        $user = $this->actor();

        $editing = $this->leadId !== null ? Lead::query()->findOrFail($this->leadId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Lead::class);

        // what the last attempt was told is no longer true of this one
        $this->resetErrorBag();

        // Drop the rows nobody filled in, so the service's row numbers are this form's row numbers.
        $this->interests = array_values(array_filter($this->interests, fn (array $row) => trim((string) ($row['id'] ?? '')) !== ''
            || trim((string) ($row['item_id'] ?? '')) !== '' || trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['quantity'] ?? '')) !== ''));

        $input = ['lead' => $this->lead, 'interests' => $this->interests];

        try {
            // Validation errors thrown by the service are shown against the fields (same paths as this form's state).
            $saved = $editing === null ? $service->create($input, $user) : $service->update($editing, $input, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        // where it came from (a failure here must never lose the lead)
        if ($editing === null && ($this->attribution['campaign_id'] !== '' || array_filter($this->attribution, fn ($v, $k) => $k !== 'campaign_id' && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH) !== [])) {
            try {
                app(CampaignAttribution::class)->tag($saved, ctype_digit($this->attribution['campaign_id']) ? (int) $this->attribution['campaign_id'] : null, $this->attribution, $this->fromLink ? 'link' : 'manual');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        session()->flash('status', $editing ? 'Lead saved.' : "Lead {$saved->lead_number} added. Plan the first follow-up so it is not forgotten.");

        // Someone who entered a lead for another person may not be able to open it afterwards.
        Gate::allows('view', $saved)
            ? $this->redirectRoute('crm.leads.show', $saved, navigate: false)
            : $this->redirectRoute('crm.leads.index', navigate: false);
    }

    /** A campaign link opens the form with the campaign and the UTM values already in it (only plain text of the five standard UTM keys is taken). */
    private function readCampaignLink(): void
    {
        if (! Gate::allows('viewAny', Campaign::class)) {
            return;
        }

        $code = trim((string) request()->query('campaign', ''));
        $campaign = $code === '' ? null : Campaign::query()->where('code', $code)->first();
        $utm = app(CampaignAttribution::class)->cleanUtm((array) request()->query());

        if ($campaign !== null || $utm !== []) {
            $this->attribution = [...$this->attribution, ...$utm, 'campaign_id' => $campaign === null ? '' : (string) $campaign->getKey()];
            $this->fromLink = true;
        }
    }

    public function render(LeadService $service, SalesTeamService $teams, SalesScope $scope, LeadAccess $access): View
    {
        $user = $this->actor();
        $id = fn (string $key): ?int => ctype_digit((string) ($this->lead[$key] ?? '')) ? (int) $this->lead[$key] : null;

        // ---- duplicates: other leads of the company with this mobile number, wherever they are ----
        $mobile = IndianMobile::normalise((string) ($this->lead['mobile'] ?? ''));
        $duplicates = $mobile === null ? collect() : $service->duplicates($mobile, $this->leadId);
        $openable = $duplicates->isEmpty() || ! $user->can('lead.view') ? [] : $access->openable($user, $duplicates);

        // ---- who can own it (only asked of people who may hand leads out, and only when it is first entered) ----
        $canAssign = $this->leadId === null && $user->can('lead.assign');
        $owners = [];
        if ($canAssign) {
            $managed = $scope->managedUserIds($user);
            $staff = $id('location_id') !== null ? $teams->staffInBranch((int) $id('location_id')) : $teams->staff();
            $owners = $managed === null ? $staff : array_intersect_key($staff, array_flip([...$managed, (int) $user->getKey()]));
        }

        // ---- the units each interest row can be in, and what its item search found ----
        $saved = $this->leadId === null ? [] : LeadInterest::query()->where('lead_id', $this->leadId)->whereNotNull('item_id')->pluck('item_id')->map(fn ($v) => (int) $v)->all();
        $lookup = app(ItemLookup::class);
        $itemUnits = [];
        $results = [];

        foreach ($this->interests as $i => $row) {
            $itemId = ctype_digit((string) ($row['item_id'] ?? '')) ? (int) $row['item_id'] : null;

            if ($itemId !== null) {
                // approved and active — or the item already on this lead, which may have been switched off since
                $item = $lookup->usable($itemId) ?? (in_array($itemId, $saved, true) ? Item::query()->with(['baseUnit', 'uoms.unit'])->find($itemId) : null);

                $units = [];
                if ($item !== null) {
                    $units[(int) $item->base_unit_id] = $item->baseUnit->name.' ('.$item->baseUnit->code.')';
                    foreach ($item->uoms as $uom) {
                        $units[(int) $uom->unit_id] = $uom->unit->name.' ('.$uom->unit->code.')';
                    }
                }
                $itemUnits[$i] = $units;

                continue;
            }

            $term = trim((string) ($row['search'] ?? ''));
            if ($term !== '') {
                $results[$i] = $lookup->search($term, self::MAX_SEARCH_RESULTS)->map(fn (Item $item) => ['id' => $item->id, 'label' => trim($item->code.' · '.$item->name)])->all();
            }
        }

        return view('crm.leads.form', [
            'editing' => $this->leadId !== null,
            'campaigns' => $this->leadId === null && $user->can('campaign.view') ? Campaign::query()->where('state', '<>', 'cancelled')->orderByDesc('id')->limit(100)->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [$c->id => $c->code.' · '.$c->name])->all() : [],
            'leadNumber' => $this->leadId === null ? null : Lead::query()->whereKey($this->leadId)->value('lead_number'),
            'branches' => Location::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $id('location_id') ?? 0))->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => LeadSource::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $id('lead_source_id') ?? 0))->orderBy('name')->pluck('name', 'id')->all(),
            'states' => State::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'districts' => $id('state_id') === null ? [] : District::query()->where('state_id', $id('state_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'blocks' => $id('district_id') === null ? [] : Block::query()->where('district_id', $id('district_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'canAssign' => $canAssign,
            'owners' => $owners,
            'ownerName' => $this->leadId !== null && $id('assigned_to') !== null ? ($teams->staff()[(int) $id('assigned_to')] ?? null) : null,
            'duplicates' => $duplicates,
            'openDuplicates' => $duplicates->filter(fn (Lead $other) => $other->isOpen())->count(),
            'openable' => $openable,
            'units' => Unit::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])->mapWithKeys(fn (Unit $u) => [$u->id => "{$u->name} ({$u->code})"])->all(),
            'itemUnits' => $itemUnits,
            'results' => $results,
            'maxInterests' => LeadService::MAX_INTERESTS,
            'today' => now()->toDateString(),
        ]);
    }
}
