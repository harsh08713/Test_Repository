<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Enums\AccountNature;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Support\Hierarchy;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Chart of accounts')]
class AccountGroupTree extends HierarchicalMasterTree
{
    protected function modelClass(): string
    {
        return AccountGroup::class;
    }

    protected function title(): string
    {
        return 'Chart of accounts';
    }

    protected function description(): string
    {
        return 'The group structure ledgers are filed under. The standard Indian groups are fixed in place; add your own beneath them. This is the grouping only — it does not post any entries.';
    }

    protected function hasNature(): bool
    {
        return true;
    }

    protected function natureOptions(): array
    {
        return collect(AccountNature::cases())->mapWithKeys(fn (AccountNature $n) => [$n->value => $n->label()])->all();
    }

    protected function isLocked(Model $model): bool
    {
        return $model instanceof AccountGroup && $model->is_system;
    }

    protected function usageBlocker(Model $model): ?string
    {
        $count = Ledger::query()->where('account_group_id', $model->getKey())->count();

        return $count > 0
            ? "{$count} ledger(s) are filed under this group, so it cannot be deleted. Move the ledgers elsewhere or deactivate the group instead."
            : null;
    }

    /**
     * Each ledger type is tied to a part of the chart of accounts (customers under Sundry Debtors, and so on), so a
     * group that holds ledgers, or whose sub-groups do, must not be moved to another part of the tree.
     */
    protected function moveBlocker(Model $model): ?string
    {
        $ids = [$model->getKey(), ...Hierarchy::descendantIds($this->nodes(), (int) $model->getKey())];
        $count = Ledger::query()->whereIn('account_group_id', $ids)->count();

        return $count > 0
            ? "{$count} ledger(s) are filed under this group or its sub-groups, so it cannot be moved: every ledger type belongs to a fixed part of the chart of accounts."
            : null;
    }
}
