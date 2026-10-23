<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The money of a loss incident: the one place impact is worked out (FORMULAS.md §4) and the one door for impact lines.
 *
 *  - Each formula has a key, a version and named inputs; a line keeps all three, so a figure can always be traced and redone. bcmath throughout;
 *    the amount is rounded once, half up to paise. A result below zero is refused (the inputs are wrong), never stored as a negative loss.
 *  - **Kinds stay apart**: a line is actual loss, estimated exposure or recoverable opportunity. An opportunity must say its assumptions, its
 *    confidence and its target period; a manual figure must say its assumptions.
 *  - **Recalculation never edits**: the old line is marked superseded (and points at its replacement), a new line is added — audited with both
 *    amounts. Only current (not superseded) lines count.
 *  - **Validation is maker–checker**: someone holding `loss_incident.approve` who did not enter the line validates or rejects it. Only validated
 *    actual lines are "actual loss".
 */
final class LossCalculationService
{
    public const VERSION = 1;

    /**
     * Formula key => label, and its inputs: name => [label, kind, required]. Kinds: quantity (3 dp), cost (4 dp), money (2 dp), count (whole).
     *
     * @return array<string, array{label: string, inputs: array<string, array{0: string, 1: string, 2: bool}>}>
     */
    public function formulas(): array
    {
        return [
            'material_cost' => ['label' => 'Material lost (quantity × unit cost)', 'inputs' => ['quantity' => ['Quantity lost', 'quantity', true], 'unit_cost' => ['Unit cost (₹)', 'cost', true]]],
            'scrap_net' => ['label' => 'Scrap, net of salvage', 'inputs' => ['quantity' => ['Quantity scrapped', 'quantity', true], 'unit_cost' => ['Unit cost (₹)', 'cost', true], 'salvage_value' => ['Salvage value (₹)', 'money', false]]],
            'expiry' => ['label' => 'Expired stock (quantity × unit cost)', 'inputs' => ['quantity' => ['Quantity expired', 'quantity', true], 'unit_cost' => ['Unit cost (₹)', 'cost', true]]],
            'downtime' => ['label' => 'Downtime', 'inputs' => ['hours' => ['Hours down', 'quantity', true], 'cost_per_hour' => ['Cost per hour (₹)', 'cost', true], 'lost_output' => ['Output lost (units)', 'quantity', false], 'margin_per_unit' => ['Contribution margin per unit (₹)', 'cost', false]]],
            'rework' => ['label' => 'Rework', 'inputs' => ['hours' => ['Rework hours', 'quantity', true], 'labour_rate' => ['Labour rate per hour (₹)', 'cost', true], 'extra_material' => ['Extra material (₹)', 'money', false]]],
            'warranty' => ['label' => 'Warranty claims', 'inputs' => ['claims' => ['Number of claims', 'count', true], 'average_claim_cost' => ['Average claim cost (₹)', 'cost', true]]],
            'markdown' => ['label' => 'Markdown', 'inputs' => ['original_price' => ['Original price (₹)', 'cost', true], 'markdown_price' => ['Markdown price (₹)', 'cost', true], 'quantity' => ['Quantity sold', 'quantity', true]]],
            'shrinkage' => ['label' => 'Shrinkage (book − physical)', 'inputs' => ['book_qty' => ['Book quantity', 'quantity', true], 'physical_qty' => ['Physical quantity', 'quantity', true], 'unit_cost' => ['Unit cost (₹)', 'cost', true]]],
            'billing_leakage' => ['label' => 'Billing leakage', 'inputs' => ['billable_value' => ['Billable value (₹)', 'money', true], 'billed_value' => ['Billed value (₹)', 'money', true]]],
            'energy_excess' => ['label' => 'Energy above standard', 'inputs' => ['actual_units' => ['Actual units', 'quantity', true], 'standard_units' => ['Standard units', 'quantity', true], 'tariff' => ['Tariff per unit (₹)', 'cost', true]]],
            'sla_penalty' => ['label' => 'SLA penalties', 'inputs' => ['penalty_per_breach' => ['Penalty per breach (₹)', 'money', true], 'breaches' => ['Breaches', 'count', true]]],
            'manual' => ['label' => 'Entered by hand (assumptions required)', 'inputs' => ['amount' => ['Amount (₹)', 'money', true]]],
        ];
    }

    /**
     * Work a formula out. Returns the cleaned inputs and the result; refuses unknown keys, bad numbers and a negative result.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{inputs: array<string, string>, amount: string, quantity: string|null, unit_cost: string|null}
     *
     * @throws ValidationException
     */
    public function calculate(string $key, array $inputs): array
    {
        $formula = $this->formulas()[$key] ?? null;

        if ($formula === null) {
            throw ValidationException::withMessages(['formula_key' => 'Choose how the figure is worked out.']);
        }

        $clean = [];
        $errors = [];

        foreach ($formula['inputs'] as $name => [$label, $kind, $required]) {
            $raw = trim((string) ($inputs[$name] ?? ''));

            if ($raw === '') {
                if ($required) {
                    $errors["inputs.$name"] = "{$label} is required.";
                }
                $clean[$name] = '0';

                continue;
            }

            $pattern = match ($kind) {
                'count' => '/^\d{1,9}$/',
                'quantity' => '/^\d{1,12}(\.\d{1,3})?$/',
                'money' => '/^\d{1,13}(\.\d{1,2})?$/',
                default => '/^\d{1,13}(\.\d{1,4})?$/',
            };

            if (preg_match($pattern, $raw) !== 1) {
                $errors["inputs.$name"] = "{$label}: enter a number that is not negative".($kind === 'count' ? ' (a whole number).' : '.');

                continue;
            }

            $clean[$name] = $raw;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $v = $clean;
        [$raw, $quantity, $unitCost] = match ($key) {
            'material_cost', 'expiry' => [bcmul($v['quantity'], $v['unit_cost'], 8), $v['quantity'], $v['unit_cost']],
            'scrap_net' => [bcsub(bcmul($v['quantity'], $v['unit_cost'], 8), $v['salvage_value'], 8), $v['quantity'], $v['unit_cost']],
            'downtime' => [bcadd(bcmul($v['hours'], $v['cost_per_hour'], 8), bcmul($v['lost_output'], $v['margin_per_unit'], 8), 8), $v['hours'], $v['cost_per_hour']],
            'rework' => [bcadd(bcmul($v['hours'], $v['labour_rate'], 8), $v['extra_material'], 8), $v['hours'], $v['labour_rate']],
            'warranty' => [bcmul($v['claims'], $v['average_claim_cost'], 8), $v['claims'], $v['average_claim_cost']],
            'markdown' => [bcmul(bcsub($v['original_price'], $v['markdown_price'], 8), $v['quantity'], 8), $v['quantity'], bcsub($v['original_price'], $v['markdown_price'], 4)],
            'shrinkage' => [bcmul(bcsub($v['book_qty'], $v['physical_qty'], 8), $v['unit_cost'], 8), bcsub($v['book_qty'], $v['physical_qty'], 3), $v['unit_cost']],
            'billing_leakage' => [bcsub($v['billable_value'], $v['billed_value'], 8), null, null],
            'energy_excess' => [bcmul(bcsub($v['actual_units'], $v['standard_units'], 8), $v['tariff'], 8), bcsub($v['actual_units'], $v['standard_units'], 3), $v['tariff']],
            'sla_penalty' => [bcmul($v['penalty_per_breach'], $v['breaches'], 8), $v['breaches'], $v['penalty_per_breach']],
            default => [$v['amount'], null, null],   // manual
        };

        if (bccomp($raw, '0', 8) < 0) {
            throw ValidationException::withMessages(['inputs' => 'These inputs give a figure below zero: check them (a loss is never negative).']);
        }

        return [
            'inputs' => $clean,
            'amount' => bcadd($raw, '0.005', 2),
            'quantity' => $quantity === null ? null : bcadd($quantity, '0', 3),
            'unit_cost' => $unitCost === null ? null : bcadd($unitCost, '0', 4),
        ];
    }

    /**
     * Add a figure to an incident.
     *
     * @param  array<string, mixed>  $input  impact_type, formula_key, inputs[], assumptions, confidence_pct, target_period
     *
     * @throws ValidationException
     */
    public function addLine(LossIncident $incident, array $input, User $actor): LossImpactLine
    {
        Gate::forUser($actor)->authorize('investigate', $incident);

        return $this->add($incident, $input, $actor);
    }

    /**
     * The first figure, given by the person reporting the incident in the same step (they may report without being allowed to work incidents).
     * Only for an incident they have just reported that has no figure yet.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function addOnReport(LossIncident $incident, array $input, User $actor): LossImpactLine
    {
        Gate::forUser($actor)->authorize('create', LossIncident::class);

        if ((int) $incident->reporter_id !== (int) $actor->getKey() || LossImpactLine::query()->where('loss_incident_id', $incident->getKey())->exists()) {
            throw ValidationException::withMessages(['impact' => 'Only the person reporting an incident adds its first figure while reporting it.']);
        }

        return $this->add($incident, $input, $actor);
    }

    /** @param  array<string, mixed>  $input */
    private function add(LossIncident $incident, array $input, User $actor): LossImpactLine
    {
        $data = $this->validated($input);

        return DB::transaction(function () use ($incident, $data, $actor) {
            $line = $this->write($incident, $data, $actor);

            activity('loss_incidents')->causedBy($actor)->performedOn($incident)->event('impact_added')
                ->withProperties(['line' => $line->id, 'type' => $line->impact_type, 'formula' => $line->formula_key, 'amount' => (string) $line->amount])->log('impact_added');

            return $line;
        });
    }

    /**
     * Work a line out again with new inputs: the old line is superseded, never edited.
     *
     * @param  array<string, mixed>  $input  inputs[], and optionally assumptions, confidence_pct, target_period (kept from the old line when not given)
     *
     * @throws ValidationException
     */
    public function recalculate(LossImpactLine $line, array $input, User $actor): LossImpactLine
    {
        $incident = $line->incident;
        Gate::forUser($actor)->authorize('investigate', $incident);

        return DB::transaction(function () use ($line, $incident, $input, $actor) {
            $old = LossImpactLine::query()->lockForUpdate()->findOrFail($line->getKey());

            if (! $old->isCurrent()) {
                throw ValidationException::withMessages(['line' => 'This figure has already been recalculated: work on its replacement.']);
            }

            $data = $this->validated([
                'impact_type' => $old->impact_type, 'formula_key' => $old->formula_key, 'inputs' => (array) ($input['inputs'] ?? []),
                'assumptions' => $input['assumptions'] ?? $old->assumptions, 'confidence_pct' => $input['confidence_pct'] ?? $old->confidence_pct,
                'target_period' => $input['target_period'] ?? $old->target_period,
            ]);

            $new = $this->write($incident, $data, $actor);
            $old->forceFill(['superseded_at' => now(), 'superseded_by_id' => $new->getKey()])->save();

            activity('loss_incidents')->causedBy($actor)->performedOn($incident)->event('impact_recalculated')
                ->withProperties(['old_line' => $old->id, 'new_line' => $new->id, 'before' => (string) $old->amount, 'after' => (string) $new->amount, 'formula' => $new->formula_key])
                ->log('impact_recalculated');

            return $new;
        });
    }

    /** Validate a figure: it then counts (an actual line becomes part of "actual loss"). */
    public function validateLine(LossImpactLine $line, User $actor): LossImpactLine
    {
        return $this->decide($line, 'validated', null, $actor);
    }

    /** Reject a figure (a reason is required): it then counts for nothing. */
    public function rejectLine(LossImpactLine $line, string $reason, User $actor): LossImpactLine
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Please say why the figure is rejected (3 to 1000 characters).']);
        }

        return $this->decide($line, 'rejected', $reason, $actor);
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    private function decide(LossImpactLine $line, string $to, ?string $reason, User $actor): LossImpactLine
    {
        $incident = $line->incident;
        Gate::forUser($actor)->authorize('validateImpact', $incident);

        return DB::transaction(function () use ($line, $incident, $to, $reason, $actor) {
            $fresh = LossImpactLine::query()->lockForUpdate()->findOrFail($line->getKey());

            if (! $fresh->isCurrent() || $fresh->validation_state !== 'pending') {
                throw ValidationException::withMessages(['line' => 'Only a current figure that is still waiting can be validated or rejected.']);
            }

            if ((int) $fresh->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['line' => 'Someone other than the person who entered a figure has to validate it.']);
            }

            $fresh->forceFill(['validation_state' => $to, 'validated_by' => $actor->getKey(), 'validated_at' => now()])->save();

            activity('loss_incidents')->causedBy($actor)->performedOn($incident)->event('impact_'.$to)
                ->withProperties(['line' => $fresh->id, 'amount' => (string) $fresh->amount, 'type' => $fresh->impact_type, 'reason' => $reason])->log('impact_'.$to);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $type = (string) ($input['impact_type'] ?? '');
        $key = (string) ($input['formula_key'] ?? '');
        $assumptions = trim((string) ($input['assumptions'] ?? ''));
        $period = trim((string) ($input['target_period'] ?? ''));
        $confidenceRaw = trim((string) ($input['confidence_pct'] ?? ''));
        $errors = [];

        if (! array_key_exists($type, LossImpactLine::TYPES)) {
            $errors['impact_type'] = 'Choose whether this is an actual loss, an estimated exposure or a recoverable opportunity.';
        }

        if ($confidenceRaw !== '' && (preg_match('/^\d{1,3}$/', $confidenceRaw) !== 1 || (int) $confidenceRaw > 100)) {
            $errors['confidence_pct'] = 'Confidence is a percentage from 0 to 100.';
        }

        if (mb_strlen($assumptions) > 2000) {
            $errors['assumptions'] = 'Assumptions can be at most 2000 characters.';
        }

        if (mb_strlen($period) > 40) {
            $errors['target_period'] = 'The target period can be at most 40 characters.';
        }

        if ($type === 'opportunity') {
            foreach (['assumptions' => $assumptions, 'confidence_pct' => $confidenceRaw, 'target_period' => $period] as $field => $value) {
                if ($value === '' && ! isset($errors[$field])) {
                    $errors[$field] = 'An opportunity has to state its assumptions, its confidence and its target period.';
                }
            }
        }

        if ($key === 'manual' && mb_strlen($assumptions) < 3) {
            $errors['assumptions'] = 'A figure entered by hand has to say how it was arrived at.';
        }

        try {
            $result = $this->calculate($key, (array) ($input['inputs'] ?? []));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([...$errors, ...$e->errors()]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'impact_type' => $type, 'formula_key' => $key, 'formula_version' => self::VERSION, 'inputs' => $result['inputs'], 'quantity' => $result['quantity'],
            'unit_cost' => $result['unit_cost'], 'amount' => $result['amount'], 'confidence_pct' => $confidenceRaw === '' ? null : (int) $confidenceRaw,
            'target_period' => $period === '' ? null : $period, 'assumptions' => $assumptions === '' ? null : $assumptions,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function write(LossIncident $incident, array $data, User $actor): LossImpactLine
    {
        $line = new LossImpactLine;
        $line->forceFill([
            'company_id' => (int) $incident->company_id, 'loss_incident_id' => $incident->getKey(), 'currency' => $incident->currency,
            'validation_state' => 'pending', 'created_by' => $actor->getKey(),
        ] + $data)->save();

        return $line;
    }
}
