<?php

namespace App\Modules\Loss\Radar;

use App\Models\User;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossNotifier;
use App\Modules\Loss\Services\LossSources;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Profit Leakage Radar — the one door for radar findings.
 *
 *  - **Sweep** (`sweep`, run daily by `radar:sweep`, every company): each detector names records that look like a loss; each record is described by
 *    `LossSources` (the same description a person gets when reporting it) and valued by `LossCalculationService` — the Radar works out no formula
 *    of its own. Below the company's `radar.min_amount` nothing is kept. One finding per detector and source record (a unique key), so a repeat
 *    sweep refreshes the finding instead of making another, and a record a person already reported (or dismissed) is never proposed again.
 *  - **A finding is a proposal**: it creates no incident and adds to no loss figure. A person reports it (the loss form, with its source filled in)
 *    and the next `syncReported` links the two; or dismisses it with a reason. An open finding whose record no longer qualifies is marked
 *    *no longer applies*.
 */
final class RadarService
{
    /** @var list<Detector> */
    private array $detectors;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
        private readonly LossSources $sources,
        private readonly LossCalculationService $calculator,
        private readonly LossNotifier $notifier,
    ) {
        $this->detectors = [new ExpiredStockDetector, new ScrapDetector, new RejectedReceiptDetector, new ProductionVarianceDetector, new PriceVarianceDetector($settings)];
    }

    /** @return list<Detector> */
    public function detectors(): array
    {
        return $this->detectors;
    }

    /** @return array{companies: int, new: int, refreshed: int, resolved: int} */
    public function sweep(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $total = ['companies' => 0, 'new' => 0, 'refreshed' => 0, 'resolved' => 0];

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->orderBy('id')->pluck('id') as $companyId) {
            $one = $this->tenant->withCompany((int) $companyId, fn () => $this->sweepCompany((int) $companyId, $now));

            if ($one !== null) {
                $total['companies']++;
                $total['new'] += $one['new'];
                $total['refreshed'] += $one['refreshed'];
                $total['resolved'] += $one['resolved'];
            }
        }

        return $total;
    }

    /** @return array{new: int, refreshed: int, resolved: int}|null null when the company has the Radar switched off */
    private function sweepCompany(int $companyId, CarbonInterface $now): ?array
    {
        if (! (bool) $this->settings->get('radar.enabled', $companyId)) {
            return null;
        }

        $min = (string) max(1, (int) $this->settings->get('radar.min_amount', $companyId));
        $lookback = max(1, (int) $this->settings->get('radar.lookback_days', $companyId));
        $result = ['new' => 0, 'refreshed' => 0, 'resolved' => 0];

        $this->syncReported($companyId);

        foreach ($this->detectors as $detector) {
            foreach ($detector->candidates($now, $lookback) as [$type, $id]) {
                $outcome = $this->keep($detector, $companyId, $type, $id, $min, $lookback, $now);

                if ($outcome !== null) {
                    $result[$outcome]++;
                }
            }

            // Still open but not seen this time: the record no longer qualifies (stock used up, scrap aged out, receipt settled).
            $result['resolved'] += RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('detector', $detector->key())
                ->where('state', 'open')->where('last_seen_at', '<', $now)->update(['state' => 'resolved', 'updated_at' => $now]);
        }

        return $result;
    }

    /** @return 'new'|'refreshed'|null */
    private function keep(Detector $detector, int $companyId, string $type, int $id, string $min, int $lookback, CarbonInterface $now): ?string
    {
        $finding = RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('detector', $detector->key())
            ->where('source_type', $type)->where('source_id', $id)->first();

        if ($finding !== null && in_array($finding->state, ['reported', 'dismissed'], true)) {
            return null;
        }

        $described = $this->sources->describe($type, $id);

        if ($described === null) {
            return null;
        }

        try {
            $figure = $this->calculator->calculate($described['impact']['formula_key'], $described['impact']['inputs']);
        } catch (ValidationException) {
            return null;   // the record does not carry what the formula needs: nothing reliable to estimate
        }

        if (bccomp($figure['amount'], $min, 2) < 0) {
            return null;
        }

        if ($finding === null && LossIncident::query()->where('source_type', $type)->where('source_id', $id)->exists()) {
            return null;   // somebody already reported it
        }

        $ratio = bcdiv($figure['amount'], $min, 4);
        $attributes = [
            'location_id' => $described['location_id'], 'title' => $described['title'], 'impact_type' => $described['impact']['impact_type'],
            'severity' => bccomp($ratio, '20', 4) >= 0 ? 'high' : (bccomp($ratio, '5', 4) >= 0 ? 'medium' : 'low'),
            'estimated_amount' => $figure['amount'], 'last_seen_at' => $now, 'state' => 'open',
            'evidence' => [
                'source' => $described['label'], 'source_type' => $type, 'source_id' => $id, 'item_id' => $described['item_id'], 'batch_id' => $described['batch_id'],
                'quantity' => $described['quantity'], 'formula_key' => $described['impact']['formula_key'], 'inputs' => $figure['inputs'],
                'assumptions' => $described['impact']['assumptions'], 'method' => $detector->method(), 'minimum_amount' => $min, 'lookback_days' => $lookback,
                'data_as_of' => $now->toIso8601String(), 'figure_status' => 'Estimate by the Radar; not part of any loss total until a person reports it and the figure is validated.',
            ],
        ];

        if ($finding !== null) {
            $finding->forceFill($attributes)->save();

            return 'refreshed';
        }

        try {
            (new RadarFinding)->forceFill($attributes + [
                'company_id' => $companyId, 'detector' => $detector->key(), 'source_type' => $type, 'source_id' => $id, 'first_detected_at' => $now,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return null;   // an overlapping sweep made it first
        }

        $this->tellAboutNew($detector, $companyId, $attributes);

        return 'new';
    }

    /**
     * A new finding graded high tells the people who may review findings, once — it is told only when the finding is first made (the unique key),
     * never again by a refresh.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tellAboutNew(Detector $detector, int $companyId, array $attributes): void
    {
        if ($attributes['severity'] !== 'high') {
            return;
        }

        $this->notifier->tell('radar_finding.view', $companyId, new LossAlertNotification('radar_high', (string) $attributes['title'],
            'The Leakage radar found a possible loss of ₹'.$attributes['estimated_amount'].' ('.$detector->key().'). It is a proposal: review it and report or dismiss it.', route('loss.radar')));
    }

    /** Link every open finding whose source has a loss incident (reported by a person, whichever way) and mark it reported. */
    public function syncReported(int $companyId): int
    {
        return DB::update(
            'UPDATE radar_findings f SET f.state = ?, f.updated_at = ?, f.loss_incident_id = (SELECT MIN(i.id) FROM loss_incidents i WHERE i.company_id = f.company_id AND i.source_type = f.source_type AND i.source_id = f.source_id AND i.deleted_at IS NULL)
             WHERE f.company_id = ? AND f.state IN (\'open\', \'resolved\') AND EXISTS (SELECT 1 FROM loss_incidents i2 WHERE i2.company_id = f.company_id AND i2.source_type = f.source_type AND i2.source_id = f.source_id AND i2.deleted_at IS NULL)',
            ['reported', now(), $companyId],
        );
    }

    /**
     * Set a finding aside because it is not a loss worth pursuing. The reason is kept; the finding is never proposed again.
     *
     * @throws ValidationException
     */
    public function dismiss(RadarFinding $finding, string $reason, User $actor): RadarFinding
    {
        Gate::forUser($actor)->authorize('update', $finding);

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Say why in at least 10 characters, so the next person knows.']);
        }

        return DB::transaction(function () use ($finding, $reason, $actor) {
            $fresh = RadarFinding::query()->lockForUpdate()->findOrFail($finding->getKey());

            if ($fresh->state !== 'open') {
                throw ValidationException::withMessages(['reason' => 'This finding is no longer open.']);
            }

            $fresh->forceFill(['state' => 'dismissed', 'dismissed_by' => $actor->getKey(), 'dismissed_at' => now(), 'dismiss_reason' => mb_substr($reason, 0, 300)])->save();
            activity('radar_findings')->causedBy($actor)->performedOn($fresh)->event('dismissed')->withProperties(['detector' => $fresh->detector, 'source' => "{$fresh->source_type}#{$fresh->source_id}"])->log('dismissed');

            return $fresh;
        });
    }
}
