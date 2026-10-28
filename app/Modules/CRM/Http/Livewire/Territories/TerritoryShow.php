<?php

namespace App\Modules\CRM\Http\Livewire\Territories;

use App\Models\User;
use App\Modules\CRM\Enums\CoverageKind;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Models\TerritoryArea;
use App\Modules\CRM\Services\TerritoryService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\LedgerLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A territory: its owner, what it covers, and the controls to add and take off coverage. */
#[Title('Territory')]
class TerritoryShow extends Component
{
    public const MAX_RESULTS = 8;

    #[Locked]
    public int $territoryId = 0;

    /** What kind of coverage is being added: a CoverageKind value. */
    public string $kind = 'state';

    /** What is being looked for (a name, or a six-digit PIN code). */
    public string $term = '';

    public function mount(Territory $territory): void
    {
        Gate::authorize('view', $territory);

        $this->territoryId = $territory->id;
    }

    #[Computed]
    public function territoryModel(): Territory
    {
        $territory = Territory::query()->with(['owner:id,name', 'branch:id,name', 'areas.state:id,name', 'areas.district:id,name', 'areas.block:id,name', 'areas.ledger:id,code,name'])->findOrFail($this->territoryId);
        Gate::authorize('view', $territory);

        return $territory;
    }

    public function updatedKind(): void
    {
        $this->term = '';
        $this->resetErrorBag();
    }

    /** Give the territory what was chosen (an id, or the PIN code typed). */
    public function cover(string $reference, TerritoryService $service): void
    {
        $territory = $this->territoryModel();
        Gate::authorize('manageCoverage', $territory);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->resetErrorBag();

        try {
            $service->addCoverage($territory, CoverageKind::from($this->kind), $reference, $user);
        } catch (ValidationException $e) {
            $this->addError('coverage', $e->errors()['coverage'][0] ?? 'That could not be added.');

            return;
        }

        $this->term = '';
        unset($this->territoryModel);
        session()->flash('status', 'Added to the territory.');
    }

    public function uncover(int $areaId, TerritoryService $service): void
    {
        $territory = $this->territoryModel();
        Gate::authorize('manageCoverage', $territory);

        $area = TerritoryArea::query()->where('territory_id', $territory->getKey())->findOrFail($areaId);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $service->removeCoverage($area, $user);

        unset($this->territoryModel);
        session()->flash('status', 'Taken off the territory.');
    }

    public function deleteTerritory(TerritoryService $service): void
    {
        $territory = $this->territoryModel();
        Gate::authorize('delete', $territory);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $service->delete($territory, $user);
        } catch (ValidationException $e) {
            $this->addError('territory', $e->errors()['territory'][0] ?? 'That could not be deleted.');

            return;
        }

        session()->flash('status', 'Territory deleted.');
        $this->redirectRoute('field.territories.index', navigate: false);
    }

    /**
     * What the search offers for the kind being added: [value, label, note].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function offers(LedgerLookup $ledgers): array
    {
        $term = trim($this->term);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return match (CoverageKind::tryFrom($this->kind)) {
            CoverageKind::State => State::query()->where('status', 'active')->when($term !== '', fn ($q) => $q->where('name', 'like', $like))->orderBy('name')->limit(50)->get(['id', 'name'])
                ->map(fn (State $s) => [(string) $s->id, $s->name, ''])->all(),
            CoverageKind::District => mb_strlen($term) < 2 ? [] : District::query()->with('state:id,name')->where('status', 'active')->where('name', 'like', $like)->orderBy('name')->limit(self::MAX_RESULTS)->get()
                ->map(fn (District $d) => [(string) $d->id, $d->name, (string) $d->state?->name])->all(),
            CoverageKind::Block => mb_strlen($term) < 2 ? [] : Block::query()->with('district:id,name')->where('status', 'active')->where('name', 'like', $like)->orderBy('name')->limit(self::MAX_RESULTS)->get()
                ->map(fn (Block $b) => [(string) $b->id, $b->name, (string) $b->district?->name])->all(),
            CoverageKind::Customer => mb_strlen($term) < 2 ? [] : $ledgers->search(LedgerType::Customer, $term, self::MAX_RESULTS)
                ->map(fn ($l) => [(string) $l->id, (string) $l->name, (string) $l->code])->all(),
            default => [],
        };
    }

    public function render(LedgerLookup $ledgers): View
    {
        $territory = $this->territoryModel();
        $covered = $territory->areas->groupBy(fn (TerritoryArea $a) => $a->kind->value);

        return view('crm.territories.show', [
            'territory' => $territory,
            'covered' => $covered,
            'kinds' => CoverageKind::cases(),
            'offers' => $this->offers($ledgers),
            'canEdit' => Gate::allows('update', $territory),
            'canCover' => Gate::allows('manageCoverage', $territory),
            'canDelete' => Gate::allows('delete', $territory) && $territory->areas->isEmpty(),
            'pin' => preg_match('/^\d{6}$/', trim($this->term)) === 1 ? trim($this->term) : null,
        ]);
    }
}
