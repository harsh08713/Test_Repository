<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Support\Hierarchy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * A tree-view master (chart of accounts, item groups): unlimited depth on `parent_id`, create child,
 * edit, move, (de)activate and delete, all authorised through the model's policy and tenant-scoped.
 *
 * Integrity rules enforced here: a group can never be moved under itself or one of its own descendants,
 * a group with children (or in use) cannot be deleted, and "system" groups are fixed in place.
 */
abstract class HierarchicalMasterTree extends Component
{
    public bool $formOpen = false;

    public ?int $editingId = null;

    public bool $moveOpen = false;

    public ?int $movingId = null;

    public string $newParentId = '';

    /** @var array<string, mixed> */
    public array $values = [];

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function title(): string;

    abstract protected function description(): string;

    /** Does the master carry a `nature` that children inherit from the root (chart of accounts)? */
    protected function hasNature(): bool
    {
        return false;
    }

    /** @return array<int|string, string> nature options for a new root group */
    protected function natureOptions(): array
    {
        return [];
    }

    /** Fixed-in-place records: code, name, parent and status cannot change; they cannot be deleted. */
    protected function isLocked(Model $model): bool
    {
        return false;
    }

    /** Why this record cannot be deleted (beyond having children / being locked), or null. */
    protected function usageBlocker(Model $model): ?string
    {
        return null;
    }

    /** Why this record must stay where it is in the tree (something relies on its place), or null. */
    protected function moveBlocker(Model $model): ?string
    {
        return null;
    }

    public function mount(): void
    {
        Gate::authorize('viewAny', $this->modelClass());
    }

    public function create(?int $parentId = null): void
    {
        Gate::authorize('create', $this->modelClass());

        $parent = $parentId !== null ? $this->nodes()->firstWhere('id', $parentId) : null;
        abort_if($parentId !== null && $parent === null, 404);

        $this->editingId = null;
        $this->values = [
            'parent_id' => $parentId === null ? '' : (string) $parentId,
            'code' => '',
            'name' => '',
            'nature' => $parent ? $this->natureOf($parent) : '',
            'status' => 'active',
            'remarks' => '',
        ];
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('update', $model);

        $this->editingId = $model->getKey();
        $this->values = [
            'parent_id' => $model->getAttribute('parent_id') === null ? '' : (string) $model->getAttribute('parent_id'),
            'code' => (string) $model->getAttribute('code'),
            'name' => (string) $model->getAttribute('name'),
            'nature' => $this->natureOf($model),
            'status' => (string) $model->getAttribute('status'),
            'remarks' => (string) $model->getAttribute('remarks'),
        ];
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->moveOpen = false;
        $this->editingId = null;
        $this->movingId = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? $this->find($this->editingId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? $this->modelClass());

        $locked = $editing !== null && $this->isLocked($editing);

        // A locked group only accepts a remark; anything else the browser sends is ignored.
        if ($locked) {
            $editing->update(['remarks' => $this->cleanText($this->values['remarks'] ?? null, 1000)]);
            session()->flash('status', 'Saved.');
            $this->cancel();

            return;
        }

        $this->values['code'] = strtoupper(trim((string) ($this->values['code'] ?? '')));
        $this->values['name'] = trim((string) preg_replace('/\s+/', ' ', (string) ($this->values['name'] ?? '')));
        $this->validate($this->rules($editing));

        $parent = ($this->values['parent_id'] ?? '') !== '' ? $this->find((int) $this->values['parent_id']) : null;

        $attributes = [
            'code' => $this->values['code'],
            'name' => $this->values['name'],
            'status' => $this->values['status'],
            'remarks' => $this->cleanText($this->values['remarks'] ?? null, 1000),
        ];

        if ($editing === null) {
            $attributes['parent_id'] = $parent?->getKey();
        }

        // Nature is fixed at creation (children take their parent's; only a new root group chooses one),
        // so editing can never leave a tree with mixed natures.
        if ($this->hasNature() && $editing === null) {
            $attributes['nature'] = $parent ? $this->natureOf($parent) : $this->values['nature'];
        }

        $model = $editing ?? new ($this->modelClass());
        $model->fill($attributes)->save();

        session()->flash('status', $editing ? 'Saved.' : 'Group added.');
        $this->cancel();
    }

    public function startMove(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('update', $model);

        if ($this->isLocked($model)) {
            $this->addError('tree', 'Standard groups are fixed in place and cannot be moved.');

            return;
        }

        if (($reason = $this->moveBlocker($model)) !== null) {
            $this->addError('tree', $reason);

            return;
        }

        $this->movingId = $model->getKey();
        $this->newParentId = $model->getAttribute('parent_id') === null ? '' : (string) $model->getAttribute('parent_id');
        $this->resetErrorBag();
        $this->moveOpen = true;
    }

    public function move(): void
    {
        $model = $this->find((int) $this->movingId);
        Gate::authorize('update', $model);

        if ($this->isLocked($model)) {
            $this->addError('newParentId', 'Standard groups are fixed in place and cannot be moved.');

            return;
        }

        if (($reason = $this->moveBlocker($model)) !== null) {
            $this->addError('newParentId', $reason);

            return;
        }

        $all = $this->nodes();
        $newParentId = $this->newParentId === '' ? null : (int) $this->newParentId;
        $newParent = $newParentId === null ? null : $all->firstWhere('id', $newParentId);

        if ($newParentId !== null && $newParent === null) {
            $this->addError('newParentId', 'Choose a group from the list.');

            return;
        }

        if (Hierarchy::wouldCreateCycle($all, (int) $model->getKey(), $newParentId)) {
            $this->addError('newParentId', 'A group cannot be moved under itself or one of its own sub-groups.');

            return;
        }

        if ($this->hasNature() && $newParent !== null && $this->natureOf($newParent) !== $this->natureOf($model)) {
            $this->addError('newParentId', 'A group cannot move under a group of a different nature ('.ucfirst($this->natureOf($model)).' → '.ucfirst($this->natureOf($newParent)).').');

            return;
        }

        $model->update(['parent_id' => $newParentId]);

        session()->flash('status', 'Group moved.');
        $this->cancel();
    }

    public function toggleStatus(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('update', $model);

        if ($this->isLocked($model)) {
            $this->addError('tree', 'Standard groups cannot be deactivated.');

            return;
        }

        $model->update(['status' => $model->getAttribute('status') === 'active' ? 'inactive' : 'active']);
    }

    public function delete(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('delete', $model);

        $reason = match (true) {
            $this->isLocked($model) => 'Standard groups cannot be deleted.',
            $this->nodes()->contains(fn (Model $m) => (int) $m->getAttribute('parent_id') === (int) $model->getKey()) => 'This group has sub-groups. Move or delete them first.',
            default => $this->usageBlocker($model),
        };

        if ($reason !== null) {
            $this->addError('tree', $reason);

            return;
        }

        $model->delete();
        session()->flash('status', 'Group deleted.');
    }

    public function render(): View
    {
        $all = $this->nodes();
        $movingId = $this->movingId;

        return view('masters.tree', [
            'title' => $this->title(),
            'description' => $this->description(),
            'tree' => Hierarchy::tree($all),
            'hasNature' => $this->hasNature(),
            'natureOptions' => $this->natureOptions(),
            'canCreate' => Gate::allows('create', $this->modelClass()),
            'locked' => $all->mapWithKeys(fn (Model $m) => [$m->getKey() => $this->isLocked($m)])->all(),
            'parentLabel' => $this->values['parent_id'] ?? '' ? $all->firstWhere('id', (int) $this->values['parent_id'])?->getAttribute('name') : null,
            'editingLocked' => $this->editingId !== null && $this->isLocked($this->find($this->editingId)),
            'moveTargets' => $this->moveOpen && $movingId !== null ? $this->moveTargets($all, $movingId) : [],
        ]);
    }

    /**
     * Valid new parents for a group: everything except itself and its own sub-tree
     * (and, for natured trees, anything of another nature), labelled with their path.
     *
     * @param  Collection<int, Model>  $all
     * @return array<int, string>
     */
    private function moveTargets(Collection $all, int $movingId): array
    {
        $moving = $all->firstWhere('id', $movingId);
        $excluded = [$movingId, ...Hierarchy::descendantIds($all, $movingId)];
        $byId = $all->keyBy(fn (Model $m) => (int) $m->getKey());

        $targets = [];
        foreach ($all as $candidate) {
            $id = (int) $candidate->getKey();

            if (in_array($id, $excluded, true)) {
                continue;
            }

            if ($this->hasNature() && $moving !== null && $this->natureOf($candidate) !== $this->natureOf($moving)) {
                continue;
            }

            $path = array_reverse(array_map(fn (int $a) => $byId->get($a)?->getAttribute('name'), Hierarchy::ancestorIds($all, $id)));
            $targets[$id] = implode(' › ', [...$path, $candidate->getAttribute('name')]);
        }

        asort($targets);

        return $targets;
    }

    private function natureOf(Model $model): string
    {
        $nature = $model->getAttribute('nature');

        return $nature instanceof \BackedEnum ? (string) $nature->value : (string) $nature;
    }

    private function cleanText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Model $editing): array
    {
        $companyId = app(TenantContext::class)->activeCompanyId();
        $table = (new ($this->modelClass()))->getTable();

        $rules = [
            'values.code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/',
                Rule::unique($table, 'code')->where('company_id', $companyId)->ignore($editing?->getKey()),
            ],
            'values.name' => ['required', 'string', 'max:150'],
            'values.status' => ['required', 'in:active,inactive'],
            'values.remarks' => ['nullable', 'string', 'max:1000'],
            'values.parent_id' => ['nullable', 'integer', Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
        ];

        if ($this->hasNature()) {
            $rules['values.nature'] = ['nullable', 'in:'.implode(',', array_keys($this->natureOptions()))];

            if (($this->values['parent_id'] ?? '') === '') {
                $rules['values.nature'] = ['required', 'in:'.implode(',', array_keys($this->natureOptions()))];
            }
        }

        return $rules;
    }

    /** @return Collection<int, Model> */
    /** @return Collection<int, Model> every node of the tree for the active company */
    protected function nodes(): Collection
    {
        return $this->modelClass()::query()->orderBy('code')->get();
    }

    private function find(int $id): Model
    {
        return $this->modelClass()::query()->findOrFail($id);
    }
}
