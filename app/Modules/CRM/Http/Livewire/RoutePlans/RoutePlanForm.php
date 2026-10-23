<?php

namespace App\Modules\CRM\Http\Livewire\RoutePlans;

use App\Modules\CRM\Enums\StopType;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Plan a day: who, where, and an ordered list of stops with a place on the map. Every rule lives in RoutePlanService; this
 * screen only offers the people the planner manages, their branches, and a way to find a block or a customer to refer to.
 */
#[Title('Route plan')]
class RoutePlanForm extends Component
{
    use ScopesToPeople;

    public const MAX_SEARCH_RESULTS = 8;

    #[Locked]
    public ?int $planId = null;

    /** @var array<string, mixed> */
    public array $plan = [];

    /** @var list<array<string, mixed>> */
    public array $stops = [];

    public function mount(?RoutePlan $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->planId = $editing->id;
            $this->plan = [
                'user_id' => (string) $editing->user_id, 'location_id' => (string) $editing->location_id, 'plan_date' => $editing->plan_date->toDateString(),
                'title' => (string) ($editing->title ?? ''), 'geofence_radius_m' => (string) ($editing->geofence_radius_m ?? ''), 'remarks' => (string) ($editing->remarks ?? ''),
            ];
            $this->stops = $editing->stops()->get()->map(fn ($s) => [
                'key' => Str::random(6), 'id' => (string) $s->id, 'stop_type' => $s->stop_type->value, 'label' => $s->label,
                'block_id' => (string) ($s->block_id ?? ''), 'pin_code' => (string) ($s->pin_code ?? ''), 'ledger_id' => (string) ($s->ledger_id ?? ''),
                'latitude' => (string) ($s->latitude ?? ''), 'longitude' => (string) ($s->longitude ?? ''), 'radius_m' => (string) ($s->radius_m ?? ''),
                'visit_from' => $s->visit_from === null ? '' : substr($s->visit_from, 0, 5), 'visit_to' => $s->visit_to === null ? '' : substr($s->visit_to, 0, 5),
                'search' => '',
            ])->all();

            return;
        }

        Gate::authorize('create', RoutePlan::class);

        $user = $this->actor();
        $mine = $this->visiblePeople();
        $only = count($mine) === 1 ? (string) array_key_first($mine) : '';
        $branches = Location::query()->where('status', 'active')->pluck('id');

        $this->plan = [
            'user_id' => $only === (string) $user->getKey() ? '' : $only, 'location_id' => $branches->count() === 1 ? (string) $branches->first() : '',
            'plan_date' => now()->toDateString(), 'title' => '', 'geofence_radius_m' => '', 'remarks' => '',
        ];
        $this->addStop();
    }

    public function addStop(): void
    {
        if (count($this->stops) < RoutePlanService::MAX_STOPS) {
            $this->stops[] = [
                'key' => Str::random(6), 'id' => '', 'stop_type' => StopType::Area->value, 'label' => '', 'block_id' => '', 'pin_code' => '', 'ledger_id' => '',
                'latitude' => '', 'longitude' => '', 'radius_m' => '', 'visit_from' => '', 'visit_to' => '', 'search' => '',
            ];
        }
    }

    public function removeStop(int $index): void
    {
        unset($this->stops[$index]);
        $this->stops = array_values($this->stops);
    }

    public function moveStop(int $index, int $by): void
    {
        $to = $index + ($by < 0 ? -1 : 1);

        if (isset($this->stops[$index], $this->stops[$to])) {
            [$this->stops[$index], $this->stops[$to]] = [$this->stops[$to], $this->stops[$index]];
        }
    }

    /** Changing what kind of stop a row is clears what the old kind pointed at. */
    public function updatedStops(mixed $value, string $key): void
    {
        if (preg_match('/^(\d+)\.stop_type$/', $key, $m) === 1) {
            $i = (int) $m[1];
            $this->stops[$i]['block_id'] = $this->stops[$i]['pin_code'] = $this->stops[$i]['ledger_id'] = $this->stops[$i]['search'] = '';
        }

        // a typed PIN code names its own stop
        if (preg_match('/^(\d+)\.pin_code$/', $key, $m) === 1) {
            $i = (int) $m[1];
            $pin = trim((string) $value);

            if (preg_match('/^\d{6}$/', $pin) === 1 && trim((string) ($this->stops[$i]['label'] ?? '')) === '' && app(PinCodeLookup::class)->resolve($pin)->found) {
                $this->stops[$i]['label'] = "PIN {$pin}";
            }
        }
    }

    /** Pick a block or a customer from the search results: the row then refers to it and takes its name. */
    public function pick(int $index, int $id): void
    {
        $row = $this->stops[$index] ?? null;
        if ($row === null) {
            return;
        }

        if ($row['stop_type'] === StopType::Block->value) {
            $block = Block::query()->with('district:id,name')->find($id);
            if ($block !== null) {
                $this->stops[$index]['block_id'] = (string) $block->id;
                $this->stops[$index]['label'] = $block->name.($block->district ? ', '.$block->district->name : '');
                $this->stops[$index]['search'] = '';
            }
        } elseif ($row['stop_type'] === StopType::Customer->value) {
            $customer = app(LedgerLookup::class)->usable($id, LedgerType::Customer);
            if ($customer !== null) {
                $this->stops[$index]['ledger_id'] = (string) $customer->id;
                $this->stops[$index]['label'] = $customer->name;
                $this->stops[$index]['search'] = '';
            }
        }
    }

    public function save(RoutePlanService $service): void
    {
        $user = $this->actor();

        $editing = $this->planId !== null ? RoutePlan::query()->findOrFail($this->planId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? RoutePlan::class);

        try {
            $saved = $service->save($editing, ['plan' => $this->plan, 'stops' => $this->stops], $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Plan saved.' : 'Plan saved as a draft. Publish it when it is ready: attendance can be marked only against a published plan.');
        $this->redirectRoute('field.plans.show', $saved, navigate: false);
    }

    public function render(SalesScope $scope): View
    {
        $user = $this->actor();
        $managed = $scope->managedUserIds($user);
        $people = $this->visiblePeople();
        $people = $managed === null ? $people : array_intersect_key($people, array_flip($managed));

        // the choices behind each row that is searching for a block or a customer
        $results = [];
        foreach ($this->stops as $i => $stop) {
            $term = trim((string) ($stop['search'] ?? ''));

            if ($term === '') {
                continue;
            }

            $like = '%'.addcslashes($term, '%_\\').'%';

            if ($stop['stop_type'] === StopType::Block->value) {
                $results[$i] = Block::query()->with('district:id,name')->where('status', 'active')->where('name', 'like', $like)->orderBy('name')->limit(self::MAX_SEARCH_RESULTS)->get()
                    ->map(fn (Block $b) => ['id' => $b->id, 'label' => $b->name.($b->district ? ' — '.$b->district->name : '')])->all();
            } elseif ($stop['stop_type'] === StopType::Customer->value) {
                $results[$i] = app(LedgerLookup::class)->search(LedgerType::Customer, $term, self::MAX_SEARCH_RESULTS)
                    ->map(fn (Ledger $l) => ['id' => $l->id, 'label' => trim($l->code.' · '.$l->name)])->all();
            }
        }

        return view('crm.plans.form', [
            'editing' => $this->planId !== null,
            'people' => $people,
            'branches' => Location::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', is_numeric($this->plan['location_id'] ?? null) ? (int) $this->plan['location_id'] : 0))->orderBy('name')->pluck('name', 'id')->all(),
            'types' => StopType::cases(),
            'results' => $results,
            'max' => RoutePlanService::MAX_STOPS,
            'defaultRadius' => (int) config('formezy.settings.geofence.radius_m.default', 500),
        ]);
    }
}
