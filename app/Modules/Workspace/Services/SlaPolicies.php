<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Models\SlaPolicy;
use Illuminate\Support\Collection;

/**
 * Which SLA policy applies to a thing. A policy narrows by lead source, team and priority; one that names none of them applies to
 * everything of its kind. The most specific policy that matches wins (a named source and team beat a source alone, which beats none).
 * A policy applies only to things made **after** the policy was: switching one on does not suddenly put a month of old leads in breach.
 */
final class SlaPolicies
{
    /** @var array<int, Collection<int, SlaPolicy>> company id => its active policies */
    private array $cache = [];

    public function forget(): void
    {
        $this->cache = [];
    }

    public function find(int $companyId, SlaKind $kind, ?int $sourceId, ?int $teamId, ?string $priority, \DateTimeInterface $madeAt): ?SlaPolicy
    {
        $matches = $this->policies($companyId)
            ->filter(fn (SlaPolicy $p) => $p->kind === $kind
                && $p->created_at !== null && $p->created_at->lte($madeAt)
                && ($p->lead_source_id === null || (int) $p->lead_source_id === $sourceId)
                && ($p->sales_team_id === null || (int) $p->sales_team_id === $teamId)
                && ($p->priority === null || $p->priority === $priority));

        return $matches
            ->sortBy(fn (SlaPolicy $p) => [-(($p->lead_source_id !== null ? 2 : 0) + ($p->sales_team_id !== null ? 2 : 0) + ($p->priority !== null ? 1 : 0)), $p->target_minutes, $p->id])
            ->first();
    }

    /** Is there an active policy of this kind that was already in force when the thing was made? */
    public function covers(int $companyId, SlaKind $kind, \DateTimeInterface $madeAt): bool
    {
        return $this->policies($companyId)->contains(fn (SlaPolicy $p) => $p->kind === $kind && $p->created_at !== null && $p->created_at->lte($madeAt));
    }

    /** @return Collection<int, SlaPolicy> */
    private function policies(int $companyId): Collection
    {
        return $this->cache[$companyId] ??= SlaPolicy::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('company_id', $companyId)->where('status', 'active')->get();
    }
}
