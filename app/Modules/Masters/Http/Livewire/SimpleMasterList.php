<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One searchable, filterable, paginated list with a create/edit form, for any plain master
 * (departments, designations, units, states, districts, blocks, PIN codes...).
 *
 * A concrete screen only declares its model, its fields and a few hooks. Everything security-relevant is
 * here, once: every action is authorised through the model's policy, reads and writes are tenant-scoped
 * through the model, the code is unique within the company, uniqueness rules ignore nothing the database
 * does not ignore (soft-deleted rows still hold their code), and deleting is a soft delete guarded by usage.
 */
abstract class SimpleMasterList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $filter = '';

    public bool $formOpen = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> form values keyed by field name (no dots: Livewire treats them as paths) */
    public array $values = [];

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function title(): string;

    abstract protected function description(): string;

    /**
     * Form fields. `type`: text | textarea | checkbox | select | number. Selects take their options from options().
     *
     * @return list<array{name: string, label: string, type: string, rules?: list<mixed>, default?: mixed, help?: string, uppercase?: bool, live?: bool, placeholder?: string}>
     */
    abstract protected function fields(): array;

    /**
     * Table columns: field name => heading.
     *
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name'];
    }

    /** A shared tab bar to show above the list (e.g. 'geography'), or null. */
    protected function tabs(): ?string
    {
        return null;
    }

    /** Does the master have a `code` column that must be unique within the company? */
    protected function usesCode(): bool
    {
        return true;
    }

    /**
     * Columns the search box looks in.
     *
     * @return list<string>
     */
    protected function searchColumns(): array
    {
        return $this->usesCode() ? ['code', 'name'] : ['name'];
    }

    /** @return array{name: string, label: string, type: string, rules: list<mixed>, default: string} */
    protected function statusField(): array
    {
        return ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'rules' => ['required', 'in:active,inactive'], 'default' => 'active'];
    }

    /** @return array<int|string, string> value => label, for select fields and the optional list filter */
    protected function options(string $field): array
    {
        return $field === 'status' ? ['active' => 'Active', 'inactive' => 'Inactive'] : [];
    }

    /**
     * Last chance to derive attributes from the validated form (e.g. a PIN code's state from its district).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function beforeSave(array $attributes): array
    {
        return $attributes;
    }

    /** Label of the optional list filter (a select over options('filter')), or null for none. */
    protected function filterLabel(): ?string
    {
        return null;
    }

    /** @param  Builder<Model>  $query */
    protected function applyFilter(Builder $query, string $value): void {}

    /**
     * Eager loads and ordering for the list.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function listQuery(Builder $query): Builder
    {
        return $query->orderBy($this->usesCode() ? 'code' : 'name');
    }

    /** Text shown in the table for a column. */
    protected function cell(Model $model, string $column): string
    {
        $value = $model->getAttribute($column);

        return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) ($value ?? '');
    }

    /**
     * Why this record cannot be deleted right now (it is referenced elsewhere), or null when it can.
     * Later phases extend this as items, ledgers and documents start to use the master.
     */
    protected function usageBlocker(Model $model): ?string
    {
        return null;
    }

    /**
     * Rules that go beyond the fields' own (composite uniqueness, dependent selects).
     *
     * @return array<string, list<mixed>>
     */
    protected function extraRules(?Model $editing): array
    {
        return [];
    }

    /** React to a field change (e.g. clear a dependent select). */
    protected function afterFieldUpdated(string $field): void {}

    public function mount(): void
    {
        Gate::authorize('viewAny', $this->modelClass());
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'filter'], true)) {
            $this->resetPage();
        }
    }

    public function updatedValues(mixed $value, string $field): void
    {
        $this->afterFieldUpdated($field);
    }

    public function create(): void
    {
        Gate::authorize('create', $this->modelClass());

        $this->editingId = null;
        $this->values = $this->defaults();
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('update', $model);

        $this->editingId = $model->getKey();
        $this->values = collect($this->fields())
            ->mapWithKeys(fn (array $f) => [$f['name'] => $model->getAttribute($f['name'])])
            ->all();
        $this->values = $this->castForForm($this->values);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? $this->find($this->editingId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? $this->modelClass());

        $this->values = $this->normalise($this->values);
        $this->validate($this->rules($editing));

        $model = $editing ?? new ($this->modelClass());
        $model->fill($this->beforeSave($this->attributesFromForm()));

        try {
            $model->save();
        } catch (QueryException $e) {
            // A race with another user creating the same key: surface it as a normal validation message.
            if (($e->errorInfo[1] ?? null) === 1062) {
                $this->addError('values.'.($this->usesCode() ? 'code' : 'name'), 'That already exists.');

                return;
            }

            throw $e;
        }

        session()->flash('status', ($editing ? 'Updated ' : 'Added ').$this->title().' record.');
        $this->cancel();
    }

    public function toggleStatus(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('update', $model);

        $model->update(['status' => $model->getAttribute('status') === 'active' ? 'inactive' : 'active']);
    }

    public function delete(int $id): void
    {
        $model = $this->find($id);
        Gate::authorize('delete', $model);

        if (($reason = $this->usageBlocker($model)) !== null) {
            $this->addError('list', $reason);

            return;
        }

        $model->delete();
        session()->flash('status', 'Deleted. It can be restored by an administrator from the database.');
    }

    public function render(): View
    {
        $query = $this->modelClass()::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $q) {
                foreach ($this->searchColumns() as $column) {
                    $q->orWhere($column, 'like', '%'.$this->search.'%');
                }
            }))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->filter !== '' && $this->filterLabel() !== null, fn (Builder $q) => $this->applyFilter($q, $this->filter));

        $rows = $this->listQuery($query)->paginate(15);
        $columns = $this->columns();

        return view('masters.simple-list', [
            'tabs' => $this->tabs(),
            'title' => $this->title(),
            'description' => $this->description(),
            'columns' => $columns,
            'fields' => $this->fields(),
            'rows' => $rows,
            'cells' => collect($rows->items())->mapWithKeys(fn (Model $row) => [
                $row->getKey() => collect($columns)->map(fn (string $heading, string $column) => $this->cell($row, $column))->all(),
            ])->all(),
            'canCreate' => Gate::allows('create', $this->modelClass()),
            'filterLabel' => $this->filterLabel(),
            'filterOptions' => $this->filterLabel() !== null ? $this->options('filter') : [],
            'fieldOptions' => collect($this->fields())->filter(fn ($f) => $f['type'] === 'select')
                ->mapWithKeys(fn ($f) => [$f['name'] => $this->options($f['name'])])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return collect($this->fields())
            ->mapWithKeys(fn (array $f) => [$f['name'] => $f['default'] ?? ($f['type'] === 'checkbox' ? false : '')])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function castForForm(array $values): array
    {
        foreach ($this->fields() as $field) {
            $value = $values[$field['name']] ?? null;
            $values[$field['name']] = match ($field['type']) {
                'checkbox' => (bool) $value,
                'select', 'number', 'text', 'textarea' => $value === null ? '' : (string) $value,
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        foreach ($this->fields() as $field) {
            $name = $field['name'];
            $value = $values[$name] ?? null;

            if (is_string($value)) {
                $value = $field['type'] === 'textarea'
                    ? trim($value)
                    : trim(preg_replace('/\s+/', ' ', $value) ?? $value);

                if (! empty($field['uppercase'])) {
                    $value = strtoupper($value);
                }
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function attributesFromForm(): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
            $value = $this->values[$field['name']] ?? null;
            $attributes[$field['name']] = ($value === '' && $field['type'] !== 'text') ? null : $value;
        }

        return $attributes;
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Model $editing): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $rules['values.'.$field['name']] = $field['rules'] ?? ['nullable', 'string', 'max:255'];
        }

        if ($this->usesCode()) {
            $table = (new ($this->modelClass()))->getTable();

            // No whereNull('deleted_at'): the database key also counts soft-deleted rows, so must the rule.
            $rules['values.code'] = [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/',
                Rule::unique($table, 'code')
                    ->where('company_id', app(TenantContext::class)->activeCompanyId())
                    ->ignore($editing?->getKey()),
            ];
        }

        return [...$rules, ...$this->extraRules($editing)];
    }

    private function find(int $id): Model
    {
        return $this->modelClass()::query()->findOrFail($id);
    }
}
