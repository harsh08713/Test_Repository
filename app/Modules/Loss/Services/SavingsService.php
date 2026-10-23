<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Verified Savings Ledger — the one door for savings measurements (docs/savings.md).
 *
 *  - **Start** (`start`): only on a corrective action someone else has already verified, once (`unique(corrective_action_id)` + the action's row lock).
 *    The draft gets sensible dates (30 days before the action was done; from the day it was done until today) which the person changes.
 *  - **Work it out** (`calculate`, shared by the form's preview and the save): the actual validated loss of the incident's category at its branch (and
 *    item) in the baseline period and in the measurement window — read from `LossFigures`, never typed — the baseline scaled by volume (both volumes
 *    entered) or else by days, and the saving = scaled baseline − loss in the window − what the action cost. It can be negative.
 *  - **Submit** (`submit`): windows of at least `MIN_WINDOW_DAYS`, ended, after the action was done, evidence written, and **no other live measurement
 *    of the same category and branch (and item) whose window overlaps** — the same loss cannot be claimed twice. The figures are worked out again and
 *    kept as the evidence the approver sees; from then on nothing changes.
 *  - **Decide** (`approve` / `reject`): by `savings_measurement.approve`, never the person who measured it or the action's owner. A rejected one goes
 *    back to draft with `reopen`.
 *  - **Recurrence** (`sweepRecurrence`, daily): a verified measurement whose category recurs at the same branch after its window is flagged once.
 *
 * A finance-verified saving is a different figure from the corrective action's own "verified result" and is never added to it.
 */
final class SavingsService
{
    public const MIN_WINDOW_DAYS = 7;

    public function __construct(private readonly LossFigures $figures, private readonly TenantContext $tenant, private readonly LossNotifier $notifier) {}

    /** @return Collection<int, CorrectiveAction> verified actions that have no measurement yet */
    public function awaiting(): Collection
    {
        return CorrectiveAction::query()->where('state', 'verified')
            ->whereNotIn('id', SavingsMeasurement::query()->select('corrective_action_id'))
            ->with('incident:id,incident_number,title')->orderByDesc('verified_at')->orderBy('id')->get();
    }

    /** @throws ValidationException */
    public function start(CorrectiveAction $action, User $actor): SavingsMeasurement
    {
        Gate::forUser($actor)->authorize('create', SavingsMeasurement::class);
        Gate::forUser($actor)->authorize('view', $action);

        return DB::transaction(function () use ($action, $actor) {
            $locked = CorrectiveAction::query()->lockForUpdate()->findOrFail($action->getKey());

            if ($locked->state !== 'verified') {
                throw ValidationException::withMessages(['action' => 'Only an action that has been verified can have its saving measured.']);
            }

            if (SavingsMeasurement::query()->where('corrective_action_id', $locked->getKey())->exists()) {
                throw ValidationException::withMessages(['action' => 'This action already has a measurement.']);
            }

            $incident = LossIncident::query()->findOrFail($locked->loss_incident_id);
            $done = CarbonImmutable::parse(($locked->completed_at ?? $locked->verified_at)->toDateString());
            $today = CarbonImmutable::parse(now()->toDateString());
            $input = [
                'baseline_from' => $done->subDays(30)->toDateString(), 'baseline_to' => $done->subDay()->toDateString(),
                'measure_from' => $done->toDateString(), 'measure_to' => $today->toDateString(),
                'baseline_volume' => '', 'measure_volume' => '', 'volume_unit' => '', 'implementation_cost' => '0', 'evidence' => '',
            ];
            $v = $this->validated($input, $locked, strict: false);

            $m = new SavingsMeasurement;
            $m->forceFill([
                'company_id' => (int) $locked->company_id, 'location_id' => (int) $incident->location_id, 'corrective_action_id' => $locked->getKey(),
                'loss_category_id' => (int) $incident->loss_category_id, 'item_id' => $incident->item_id, 'state' => 'draft', 'created_by' => $actor->getKey(),
            ] + $v + $this->calculate($v, $incident))->save();

            activity('savings_measurements')->causedBy($actor)->performedOn($m)->event('started')->withProperties(['action' => $locked->action_number])->log('started');

            return $m;
        });
    }

    /**
     * Change a draft. The figures are worked out again from the loss records.
     *
     * @param  array<string, mixed>  $input  baseline_from, baseline_to, measure_from, measure_to, baseline_volume, measure_volume, volume_unit, implementation_cost, evidence
     *
     * @throws ValidationException
     */
    public function update(SavingsMeasurement $measurement, array $input, User $actor): SavingsMeasurement
    {
        Gate::forUser($actor)->authorize('update', $measurement);

        return DB::transaction(function () use ($measurement, $input, $actor) {
            $m = SavingsMeasurement::query()->lockForUpdate()->findOrFail($measurement->getKey());

            if ($m->state !== 'draft') {
                throw ValidationException::withMessages(['measurement' => 'Only a draft can be changed.']);
            }

            $action = $this->actionOf($m);
            $v = $this->validated($input, $action, strict: false);
            $m->forceFill($v + $this->calculate($v, $this->incidentOf($action)))->save();
            activity('savings_measurements')->causedBy($actor)->performedOn($m)->event('changed')->log('changed');

            return $m;
        });
    }

    /**
     * What the figures would be for this input, saving nothing (the form's live preview).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function preview(SavingsMeasurement $measurement, array $input): array
    {
        $action = $this->actionOf($measurement);
        $v = $this->validated($input, $action, strict: false);

        return $v + $this->calculate($v, $this->incidentOf($action));
    }

    /** @throws ValidationException */
    public function submit(SavingsMeasurement $measurement, User $actor): SavingsMeasurement
    {
        Gate::forUser($actor)->authorize('submit', $measurement);

        $submitted = DB::transaction(function () use ($measurement, $actor) {
            // One branch at a time, so two measurements of the same loss cannot both pass the overlap check.
            Location::query()->lockForUpdate()->findOrFail($measurement->location_id);
            $m = SavingsMeasurement::query()->lockForUpdate()->findOrFail($measurement->getKey());

            if ($m->state !== 'draft') {
                throw ValidationException::withMessages(['measurement' => 'Only a draft can be submitted.']);
            }

            $action = $this->actionOf($m);
            $v = $this->validated([
                'baseline_from' => $m->baseline_from->toDateString(), 'baseline_to' => $m->baseline_to->toDateString(), 'measure_from' => $m->measure_from->toDateString(),
                'measure_to' => $m->measure_to->toDateString(), 'baseline_volume' => (string) $m->baseline_volume, 'measure_volume' => (string) $m->measure_volume,
                'volume_unit' => (string) $m->volume_unit, 'implementation_cost' => (string) $m->implementation_cost, 'evidence' => (string) $m->evidence,
            ], $action, strict: true);

            $clash = $this->overlapping($m);

            if ($clash !== null) {
                throw ValidationException::withMessages(['measure_from' => "This loss is already being measured by {$clash}: its window overlaps this one, so the same saving would be counted twice."]);
            }

            $m->forceFill($v + $this->calculate($v, $this->incidentOf($action)) + ['state' => 'submitted', 'submitted_at' => now()])->save();
            activity('savings_measurements')->causedBy($actor)->performedOn($m)->event('submitted')->log('submitted');

            return $m;
        });

        // Told once, when it is sent (a draft cannot be sent twice): the people who may verify it, except the maker and the action's owner.
        $this->notifier->tell('savings_measurement.approve', (int) $submitted->company_id, new LossAlertNotification('savings_submitted',
            $this->actionOf($submitted)->action_number.' — net saving ₹'.$submitted->net_saving, 'A savings measurement is waiting for Finance to verify it.', route('loss.savings.show', $submitted->id)),
            [(int) $actor->getKey(), (int) $this->actionOf($submitted)->owner_id]);

        return $submitted;
    }

    /** @throws ValidationException */
    public function approve(SavingsMeasurement $measurement, string $notes, User $actor): SavingsMeasurement
    {
        return $this->decide($measurement, 'verified', $notes, $actor);
    }

    /** @throws ValidationException */
    public function reject(SavingsMeasurement $measurement, string $reason, User $actor): SavingsMeasurement
    {
        return $this->decide($measurement, 'rejected', $reason, $actor);
    }

    /** A rejected measurement goes back to draft to be corrected and sent again. */
    public function reopen(SavingsMeasurement $measurement, User $actor): SavingsMeasurement
    {
        Gate::forUser($actor)->authorize('create', SavingsMeasurement::class);

        return DB::transaction(function () use ($measurement, $actor) {
            $m = SavingsMeasurement::query()->lockForUpdate()->findOrFail($measurement->getKey());

            if ($m->state !== 'rejected') {
                throw ValidationException::withMessages(['measurement' => 'Only a rejected measurement can be re-opened.']);
            }

            $m->forceFill(['state' => 'draft', 'submitted_at' => null, 'decided_by' => null, 'decided_at' => null])->save();
            activity('savings_measurements')->causedBy($actor)->performedOn($m)->event('reopened')->log('reopened');

            return $m;
        });
    }

    /**
     * Flag each finance-verified measurement whose loss has come back: an incident of the same category at the same branch (and item) in the days after
     * its window. Claimed by a conditional UPDATE, so repeated or overlapping runs flag it once.
     *
     * @return int measurements flagged
     */
    public function sweepRecurrence(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $flagged = 0;
        $due = SavingsMeasurement::withoutGlobalScopes(Tenancy::SCOPES)->where('state', 'verified')->whereNull('recurred_at')->orderBy('id')->limit(1000)->get();

        foreach ($due as $m) {
            $incident = $this->tenant->withCompany((int) $m->company_id, fn () => LossIncident::query()->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
                ->where('loss_category_id', $m->loss_category_id)->where('location_id', $m->location_id)->when($m->item_id !== null, fn ($q) => $q->where('item_id', $m->item_id))
                ->where('occurred_at', '>', $m->measure_to->copy()->endOfDay())->orderBy('occurred_at')->orderBy('id')->first());

            if ($incident === null) {
                continue;
            }

            $claimed = SavingsMeasurement::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($m->getKey())->whereNull('recurred_at')
                ->update(['recurred_at' => $now, 'recurrence_incident_id' => $incident->getKey(), 'updated_at' => $now]);

            $flagged += $claimed;

            if ($claimed > 0) {
                $this->notifier->tellPeople([$m->created_by, $m->decided_by], new LossAlertNotification('savings_recurred', "Measurement #{$m->id} — the loss {$incident->incident_number} has come back",
                    'A saving Finance verified is under threat: an incident of the same loss category at the same branch happened after its measurement window.', route('loss.savings.show', $m->id)));
            }
        }

        return $flagged;
    }

    // ---- internals --------------------------------------------------------------------------------------------------

    private function decide(SavingsMeasurement $measurement, string $to, string $notes, User $actor): SavingsMeasurement
    {
        Gate::forUser($actor)->authorize('decide', $measurement);
        $notes = trim($notes);

        if (mb_strlen($notes) < 3 || mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['notes' => $to === 'verified' ? 'Say what Finance checked (3 to 2000 characters).' : 'Say why it is rejected (3 to 2000 characters).']);
        }

        return DB::transaction(function () use ($measurement, $to, $notes, $actor) {
            $m = SavingsMeasurement::query()->lockForUpdate()->findOrFail($measurement->getKey());

            if ($m->state !== 'submitted') {
                throw ValidationException::withMessages(['measurement' => 'This measurement is no longer waiting for a decision.']);
            }

            $m->forceFill(['state' => $to, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_notes' => $notes])->save();
            activity('savings_measurements')->causedBy($actor)->performedOn($m)->event($to)->withProperties(['net_saving' => (string) $m->net_saving])->log($to);

            return $m;
        });
    }

    private function actionOf(SavingsMeasurement $m): CorrectiveAction
    {
        return CorrectiveAction::query()->findOrFail($m->corrective_action_id);
    }

    private function incidentOf(CorrectiveAction $action): LossIncident
    {
        return LossIncident::query()->findOrFail($action->loss_incident_id);
    }

    /** The action number of another live measurement of the same loss whose window overlaps this one's, if any. */
    private function overlapping(SavingsMeasurement $m): ?string
    {
        $other = SavingsMeasurement::query()->whereKeyNot($m->getKey())->whereIn('state', ['submitted', 'verified'])
            ->where('loss_category_id', $m->loss_category_id)->where('location_id', $m->location_id)
            ->where(fn ($q) => $m->item_id === null ? $q : $q->where(fn ($i) => $i->whereNull('item_id')->orWhere('item_id', $m->item_id)))
            ->where('measure_from', '<=', $m->measure_to->toDateString())->where('measure_to', '>=', $m->measure_from->toDateString())
            ->with('action:id,action_number')->first();

        return $other === null ? null : $other->action->action_number;
    }

    /**
     * Check what was typed. A draft (`strict` false) only has to make sense; a submission also needs windows long enough, ended, evidence and an
     * action that was done before the window.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $in, CorrectiveAction $action, bool $strict): array
    {
        $errors = [];
        $date = function (string $key) use ($in): ?CarbonImmutable {
            try {
                $raw = trim((string) ($in[$key] ?? ''));

                return $raw === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $raw);
            } catch (Throwable) {
                return null;
            }
        };

        $bf = $date('baseline_from');
        $bt = $date('baseline_to');
        $mf = $date('measure_from');
        $mt = $date('measure_to');

        foreach (['baseline_from' => $bf, 'baseline_to' => $bt, 'measure_from' => $mf, 'measure_to' => $mt] as $key => $value) {
            if ($value === null) {
                $errors[$key] = 'Enter a date.';
            }
        }

        $today = CarbonImmutable::parse(now()->toDateString());
        $done = CarbonImmutable::parse(($action->completed_at ?? $action->verified_at ?? now())->toDateString());

        if ($errors === []) {
            if ($bt->lt($bf)) {
                $errors['baseline_to'] = 'The baseline cannot end before it starts.';
            }
            if ($mt->lt($mf)) {
                $errors['measure_to'] = 'The window cannot end before it starts.';
            }
            if ($mf->lt($done)) {
                $errors['measure_from'] = 'The window must start on or after the day the action was done ('.$done->format('d-m-Y').').';
            }
            if ($bt->gte($mf)) {
                $errors['baseline_to'] = 'The baseline must end before the window starts.';
            }
            if ($mt->gt($today)) {
                $errors['measure_to'] = 'The window cannot end in the future.';
            }

            if ($strict && $errors === []) {
                foreach (['baseline' => [$bf, $bt], 'measure' => [$mf, $mt]] as $name => [$from, $to]) {
                    if ((int) $from->diffInDays($to) + 1 < self::MIN_WINDOW_DAYS) {
                        $errors["{$name}_to"] = 'Measure over at least '.self::MIN_WINDOW_DAYS.' days.';
                    }
                }
            }
        }

        $volume = function (string $key) use ($in, &$errors): ?string {
            $raw = trim((string) ($in[$key] ?? ''));

            if ($raw === '') {
                return null;
            }

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $raw) !== 1 || bccomp($raw, '0', 3) <= 0) {
                $errors[$key] = 'Enter a quantity above zero, or leave both blank to compare by days.';

                return null;
            }

            return bcadd($raw, '0', 3);
        };

        $baseVolume = $volume('baseline_volume');
        $measureVolume = $volume('measure_volume');
        $unit = trim((string) ($in['volume_unit'] ?? ''));

        if (($baseVolume === null) !== ($measureVolume === null) && ! isset($errors['baseline_volume'], $errors['measure_volume'])) {
            $errors['measure_volume'] = 'Give the volume for both periods, or for neither.';
        }

        if ($baseVolume !== null && $measureVolume !== null && ($unit === '' || mb_strlen($unit) > 30)) {
            $errors['volume_unit'] = 'Say what the volume counts (units produced, kg, orders…).';
        }

        $cost = trim((string) ($in['implementation_cost'] ?? '0')) ?: '0';

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $cost) !== 1) {
            $errors['implementation_cost'] = 'Enter what the action cost in rupees (0 if nothing).';
            $cost = '0';
        }

        $evidence = trim((string) ($in['evidence'] ?? ''));

        if (mb_strlen($evidence) > 2000 || ($strict && mb_strlen($evidence) < 10)) {
            $errors['evidence'] = 'Say what supports this figure — where the loss records, volumes and cost come from (10 to 2000 characters).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'baseline_from' => $bf->toDateString(), 'baseline_to' => $bt->toDateString(), 'measure_from' => $mf->toDateString(), 'measure_to' => $mt->toDateString(),
            'baseline_volume' => $baseVolume, 'measure_volume' => $measureVolume, 'volume_unit' => $baseVolume === null ? null : $unit,
            'implementation_cost' => bcadd($cost, '0', 2), 'evidence' => $evidence === '' ? null : $evidence,
        ];
    }

    /**
     * The three figures, hand-checkable: baseline × (volume in the window ÷ volume in the baseline) — or × (days ÷ days) — less the loss in the
     * window, less the cost. Half-up to the paisa.
     *
     * @param  array<string, mixed>  $v  checked dates, volumes and cost (see validated())
     * @return array<string, string|null>
     */
    private function calculate(array $v, LossIncident $incident): array
    {
        $category = (int) $incident->loss_category_id;
        $location = (int) $incident->location_id;
        $item = $incident->item_id === null ? null : (int) $incident->item_id;
        $baseline = $this->figures->actualFor(CarbonImmutable::parse($v['baseline_from']), CarbonImmutable::parse($v['baseline_to']), $category, $location, $item);
        $measured = $this->figures->actualFor(CarbonImmutable::parse($v['measure_from']), CarbonImmutable::parse($v['measure_to']), $category, $location, $item);

        if ($v['baseline_volume'] !== null && $v['measure_volume'] !== null) {
            $method = 'volume';
            $factor = bcdiv((string) $v['measure_volume'], (string) $v['baseline_volume'], 10);
        } else {
            $method = 'days';
            $baselineDays = (int) CarbonImmutable::parse($v['baseline_from'])->diffInDays(CarbonImmutable::parse($v['baseline_to'])) + 1;
            $measureDays = (int) CarbonImmutable::parse($v['measure_from'])->diffInDays(CarbonImmutable::parse($v['measure_to'])) + 1;
            $factor = bcdiv((string) $measureDays, (string) $baselineDays, 10);
        }

        $normalised = bcadd(bcmul($baseline, $factor, 6), '0.005', 2);
        $gross = bcsub($normalised, $measured, 2);

        return [
            'baseline_loss' => $baseline, 'measure_loss' => $measured, 'method' => $method, 'normalised_baseline' => $normalised, 'gross_saving' => $gross,
            'net_saving' => bcsub($gross, (string) $v['implementation_cost'], 2),
        ];
    }
}
