<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\CoverageKind;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Models\TerritoryArea;

/**
 * Which territory covers a place — the one place that question is answered. It looks at the lead's (or the customer's) own customer, PIN code, block,
 * district and state, and the **most specific** cover wins: a customer beats a PIN code beats a block beats a district beats a state. Only active
 * territories count. The answer is advice (who *should* work it); nothing here moves a lead or changes an owner.
 */
final class TerritoryResolver
{
    /** @return array{territory: Territory, kind: CoverageKind}|null */
    public function forLead(Lead $lead): ?array
    {
        return $this->forPlace(
            $lead->customer_ledger_id === null ? null : (int) $lead->customer_ledger_id,
            $lead->pin_code === null || $lead->pin_code === '' ? null : (string) $lead->pin_code,
            $lead->block_id === null ? null : (int) $lead->block_id,
            $lead->district_id === null ? null : (int) $lead->district_id,
            $lead->state_id === null ? null : (int) $lead->state_id,
        );
    }

    /** @return array{territory: Territory, kind: CoverageKind}|null */
    public function forPlace(?int $ledgerId, ?string $pinCode, ?int $blockId, ?int $districtId, ?int $stateId): ?array
    {
        $wanted = [
            [CoverageKind::Customer, $ledgerId], [CoverageKind::PinCode, $pinCode], [CoverageKind::Block, $blockId],
            [CoverageKind::District, $districtId], [CoverageKind::State, $stateId],
        ];
        $wanted = array_filter($wanted, fn (array $pair) => $pair[1] !== null);

        if ($wanted === []) {
            return null;
        }

        $areas = TerritoryArea::query()
            ->whereIn('territory_id', Territory::query()->where('status', 'active')->select('id'))
            ->where(function ($query) use ($wanted) {
                foreach ($wanted as [$kind, $value]) {
                    $query->orWhere(fn ($one) => $one->where('kind', $kind->value)->where($kind->column(), $value));
                }
            })
            ->get();

        $best = $areas->sortByDesc(fn (TerritoryArea $area) => $area->kind->rank())->first();

        if ($best === null) {
            return null;
        }

        $territory = Territory::query()->with('owner:id,name')->find($best->territory_id);

        return $territory === null ? null : ['territory' => $territory, 'kind' => $best->kind];
    }
}
