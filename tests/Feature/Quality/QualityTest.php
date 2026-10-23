<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Services\CapaService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Quality\Http\Livewire\InspectionForm;
use App\Modules\Quality\Http\Livewire\InspectionList;
use App\Modules\Quality\Http\Livewire\InspectionShow;
use App\Modules\Quality\Http\Livewire\NcrForm;
use App\Modules\Quality\Http\Livewire\NcrList;
use App\Modules\Quality\Http\Livewire\NcrShow;
use App\Modules\Quality\Http\Livewire\PlanForm;
use App\Modules\Quality\Http\Livewire\PlanList;
use App\Modules\Quality\Http\Livewire\PlanShow;
use App\Modules\Quality\Models\BatchHold;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Models\InspectionResult;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Quality\Services\InspectionPlanService;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NonConformanceService;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Q1: inspection plans, inspections, batch hold and release, non-conformance reports. A plan checks incoming resin: Diameter 9.90–10.10 mm (critical), Hardness at least 40 HRC,
 * and a pass / fail Surface check. Inspecting batch R-1 (500 kg): diameter 10.05 passes, hardness 38 is below the limit of 40 and fails.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->qm = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->qm2 = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->inspector = makeUser(['Quality Inspector'], [$this->acme]);
    $this->ahdInspector = makeUser(['Quality Inspector'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->pm = makeUser(['Production Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    $this->outsider = makeUser(['Quality/Loss Manager'], [$this->globex]);
    $purchase = makeUser(['Purchase User'], [$this->acme]);

    $this->resin = makeItem($this->acme, $purchase, ['item' => ['name' => 'Resin', 'base_unit_id' => (string) unitId($this->acme, 'KG')], 'uoms' => [], 'inventory' => ['tracking' => 'batch']], 'approved');
    $this->bolt = makeItem($this->acme, $purchase, ['item' => ['name' => 'Hex Bolt M10'], 'uoms' => []], 'approved');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return $user;
    };
    $this->batch = fn (string $no = 'R-1', string $status = 'active') => ($this->do)(function () use ($no, $status) {
        $b = new Batch;
        $b->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->resin->id, 'batch_no' => $no, 'status' => $status, 'expiry_date' => '2028-01-31'])->save();
        app(StockService::class)->receive($this->resin, $this->ahd->id, '500', '10', $b, 'test', 1, (int) $b->id);

        return $b;
    });
    $this->issueFrom = fn (Batch $b, string $qty = '10') => ($this->do)(fn () => app(StockService::class)->issue($this->resin, $this->ahd->id, $qty, $b->fresh(), 'test-issue', 1, random_int(1, 999999)));

    $this->planInput = fn (array $over = [], ?array $lines = null) => ['code' => 'QP-RESIN-IN', 'name' => 'Incoming resin', 'item_id' => (string) $this->resin->id, 'stage' => 'incoming', 'remarks' => '', 'items' => $lines ?? [
        ['name' => 'Diameter', 'kind' => 'measure', 'unit_text' => 'mm', 'min_value' => '9.90', 'max_value' => '10.10', 'is_critical' => true, 'method' => 'Vernier caliper'],
        ['name' => 'Hardness', 'kind' => 'measure', 'unit_text' => 'HRC', 'min_value' => '40', 'max_value' => '', 'is_critical' => false, 'method' => ''],
        ['name' => 'Surface', 'kind' => 'check', 'unit_text' => '', 'min_value' => '', 'max_value' => '', 'is_critical' => false, 'method' => 'Visual'],
    ], ...$over];
    $this->plan = function () {
        static $plan = null;   // one plan per test: the same code cannot be made twice

        return $plan ??= (function () {
            $made = ($this->do)(function () {
                ($this->as)($this->qm);

                return app(InspectionPlanService::class)->create(($this->planInput)(), $this->qm);
            });

            return approveDocument($this->acme, $made, $this->qm, $this->approver);
        })();
    };
    $this->start = fn (?Batch $b = null, string $lot = '500', $by = null) => ($this->do)(function () use ($b, $lot, $by) {
        ($this->as)($by ?? $this->inspector);

        return app(InspectionService::class)->start(['inspection_plan_id' => (string) ($this->plan)()->id, 'location_id' => (string) $this->ahd->id, 'lot_quantity' => $lot, 'batch_id' => (string) ($b?->id ?? '')], $by ?? $this->inspector);
    });
    $this->resultOf = fn (Inspection $i, string $name) => InspectionResult::withoutGlobalScopes(Tenancy::SCOPES)->where('inspection_id', $i->id)->where('name', $name)->firstOrFail();
    $this->record = fn (Inspection $i, string $diameter, string $hardness, string $surface = 'pass', $by = null) => ($this->do)(fn () => app(InspectionService::class)->record($i->fresh(), [
        ($this->resultOf)($i, 'Diameter')->id => ['value' => $diameter], ($this->resultOf)($i, 'Hardness')->id => ['value' => $hardness], ($this->resultOf)($i, 'Surface')->id => ['check' => $surface],
    ], $by ?? $this->inspector));
    $this->complete = fn (Inspection $i, $by = null) => ($this->do)(fn () => app(InspectionService::class)->complete($i->fresh(), $by ?? $this->inspector));
    $this->decide = fn (Inspection $i, string $decision, string $reason, $by) => ($this->do)(fn () => app(InspectionService::class)->decide($i->fresh(), $decision, $reason, $by));
    $this->failedInspection = function (?Batch $b = null) {
        $i = ($this->start)($b);
        ($this->record)($i, '10.05', '38');

        return ($this->complete)($i);
    };
    $this->status = fn (Batch $b) => Batch::withoutGlobalScopes(Tenancy::SCOPES)->find($b->id)->status;
});

afterEach(fn () => Carbon::setTestNow());

// ---- inspection plans ----------------------------------------------------------------------------------------------------------------

it('makes an inspection plan, approves it by another person, then locks it', function () {
    $made = ($this->do)(function () {
        ($this->as)($this->qm);

        return app(InspectionPlanService::class)->create(($this->planInput)(), $this->qm);
    });

    expect($made->parameters)->toHaveCount(3)->and((string) $made->parameters[0]->min_value)->toBe('9.9000')->and($made->parameters[0]->is_critical)->toBeTrue()
        ->and($made->parameters[1]->max_value)->toBeNull()->and($made->parameters[2]->kind)->toBe('check')->and($made->approval_status->value)->toBe('draft');

    expect(fn () => approveDocument($this->acme, $made, $this->qm, $this->qm))->toThrow(Exception::class);   // the maker cannot approve

    ($this->do)(fn () => app(ApprovalService::class)->approve($made->fresh(), $this->qm2));
    $approved = $made->fresh();

    expect($approved->isUsable())->toBeTrue()
        ->and(fn () => InspectionPlan::withoutGlobalScopes(Tenancy::SCOPES)->find($approved->id)->forceFill(['name' => 'Other'])->save())->toThrow(LogicException::class)
        ->and(fn () => $approved->parameters()->first()->forceFill(['name' => 'Other'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->do)(fn () => app(InspectionPlanService::class)->update($approved, ($this->planInput)(), $this->qm)))->toThrow(FieldRuleException::class);

    ($this->do)(fn () => app(InspectionPlanService::class)->setStatus($approved, false, $this->qm));
    expect($approved->fresh()->isUsable())->toBeFalse();
});

it('refuses a plan that is not sensible', function () {
    $try = fn (array $over = [], ?array $lines = null) => fn () => ($this->do)(function () use ($over, $lines) {
        ($this->as)($this->qm);

        return app(InspectionPlanService::class)->create(($this->planInput)($over, $lines), $this->qm);
    });
    $measure = fn (array $over = []) => ['name' => 'Width', 'kind' => 'measure', 'unit_text' => 'mm', 'min_value' => '1', 'max_value' => '2', 'is_critical' => false, 'method' => '', ...$over];

    expect($try(['code' => 'x']))->toThrow(ValidationException::class)
        ->and($try(['stage' => 'party']))->toThrow(ValidationException::class)
        ->and($try([], []))->toThrow(ValidationException::class)
        ->and($try([], [$measure(['min_value' => '', 'max_value' => ''])]))->toThrow(ValidationException::class, 'lowest or a highest')
        ->and($try([], [$measure(['min_value' => '5', 'max_value' => '2'])]))->toThrow(ValidationException::class, 'cannot be below')
        ->and($try([], [$measure(['min_value' => 'abc'])]))->toThrow(ValidationException::class)
        ->and($try([], [$measure(), $measure()]))->toThrow(ValidationException::class, 'already checked')
        ->and($try([], [$measure(['kind' => 'weigh'])]))->toThrow(ValidationException::class)
        ->and($try([], [$measure(['name' => ''])]))->toThrow(ValidationException::class)
        ->and(InspectionPlan::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $try()();
    expect($try())->toThrow(ValidationException::class, 'already a plan');   // the code is taken
});

// ---- starting an inspection, and the hold ----------------------------------------------------------------------------------------------

it('starts an inspection that copies the plan, holds the batch and stops it being issued', function () {
    $batch = ($this->batch)();
    ($this->issueFrom)($batch, '10');   // fine while it is active

    $i = ($this->start)($batch);

    expect($i->inspection_number)->toBe('QI/AHD/26-27/00001')->and($i->state)->toBe('open')->and($i->held_batch)->toBeTrue()->and((string) $i->lot_quantity)->toBe('500.000')
        ->and($i->results)->toHaveCount(3)->and((string) $i->results[0]->min_value)->toBe('9.9000')->and($i->item_name)->toBe('Resin')
        ->and(($this->status)($batch))->toBe('quarantine')
        ->and(BatchHold::withoutGlobalScopes(Tenancy::SCOPES)->where('batch_id', $batch->id)->pluck('action')->all())->toBe(['hold']);

    expect(fn () => ($this->issueFrom)($batch, '10'))->toThrow(StockException::class, 'quarantine');

    // one inspection of a batch at a time
    expect(fn () => ($this->start)($batch))->toThrow(FieldRuleException::class, 'already has an inspection');
});

it('refuses an inspection that is not sensible', function () {
    $batch = ($this->batch)();
    $other = ($this->batch)('R-HOLD', 'quarantine');   // on hold for a reason this module did not give
    $try = fn (array $in) => fn () => ($this->do)(function () use ($in) {
        ($this->as)($this->inspector);

        return app(InspectionService::class)->start(['inspection_plan_id' => (string) ($this->plan)()->id, 'location_id' => (string) $this->ahd->id, 'lot_quantity' => '10', 'batch_id' => (string) $this->batchUnderTest->id, ...$in], $this->inspector);
    });
    $this->batchUnderTest = $batch;
    $draftPlan = ($this->do)(function () {
        ($this->as)($this->qm);

        return app(InspectionPlanService::class)->create(($this->planInput)(['code' => 'QP-DRAFT']), $this->qm);
    });

    expect($try(['lot_quantity' => '0']))->toThrow(ValidationException::class)
        ->and($try(['batch_id' => '']))->toThrow(ValidationException::class, 'tracked by batch')
        ->and($try(['batch_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['location_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['inspection_plan_id' => (string) $draftPlan->id]))->toThrow(ValidationException::class, 'approved, active')   // a plan that is not approved
        ->and(fn () => ($this->start)($other))->toThrow(FieldRuleException::class, 'on hold for another reason')
        ->and(fn () => ($this->start)($batch, '10', $this->ahdInspector) && false)->not->toThrow(Throwable::class);   // a branch-limited inspector may work at Ahmedabad

    expect(Inspection::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);   // only the last, valid one
});

// ---- recording, completing, deciding ---------------------------------------------------------------------------------------------------

it('works out pass or fail from the limits, inclusive at both ends', function () {
    $i = ($this->start)(($this->batch)());
    $passed = fn (string $name) => ($this->resultOf)($i, $name)->fresh()->passed;

    ($this->record)($i, '9.90', '40');                                  // exactly on the limits
    expect($passed('Diameter'))->toBeTrue()->and($passed('Hardness'))->toBeTrue()->and($passed('Surface'))->toBeTrue();

    ($this->record)($i, '10.1001', '39.9999', 'fail');
    expect($passed('Diameter'))->toBeFalse()->and($passed('Hardness'))->toBeFalse()->and($passed('Surface'))->toBeFalse();

    ($this->record)($i, '10.10', '1000');                               // the hardness has no top limit
    expect($passed('Diameter'))->toBeTrue()->and($passed('Hardness'))->toBeTrue();

    $try = fn (array $v) => fn () => ($this->do)(fn () => app(InspectionService::class)->record($i->fresh(), $v, $this->inspector));
    expect($try([($this->resultOf)($i, 'Diameter')->id => ['value' => 'abc']]))->toThrow(ValidationException::class)
        ->and($try([($this->resultOf)($i, 'Diameter')->id => ['value' => '10.12345']]))->toThrow(ValidationException::class)
        ->and($try([($this->resultOf)($i, 'Surface')->id => ['check' => 'maybe']]))->toThrow(ValidationException::class);
});

it('completes only when every check is recorded; everything within limits accepts the lot and releases the batch', function () {
    $batch = ($this->batch)();
    $i = ($this->start)($batch);

    expect(fn () => ($this->complete)($i))->toThrow(ValidationException::class, 'Record every check');
    ($this->record)($i, '10.05', '45');
    $done = ($this->complete)($i);

    expect($done->state)->toBe('accepted')->and($done->completed_at)->not->toBeNull()->and(($this->status)($batch))->toBe('active')
        ->and(BatchHold::withoutGlobalScopes(Tenancy::SCOPES)->where('batch_id', $batch->id)->pluck('action')->all())->toBe(['hold', 'release']);
    expect(($this->issueFrom)($batch, '10'))->not->toBeNull();   // it can be issued again

    // a finished inspection is a record
    expect(fn () => Inspection::withoutGlobalScopes(Tenancy::SCOPES)->find($done->id)->forceFill(['lot_quantity' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->resultOf)($done, 'Diameter')->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->record)($done, '10', '50'))->toThrow(FieldRuleException::class);
});

it('fails the lot when a check is outside, keeps the batch on hold, and rejecting it raises a non-conformance report', function () {
    $batch = ($this->batch)();
    $failed = ($this->failedInspection)($batch);

    expect($failed->state)->toBe('failed')->and(($this->status)($batch))->toBe('quarantine')
        ->and(fn () => ($this->issueFrom)($batch))->toThrow(StockException::class);

    $rejected = ($this->decide)($failed, 'reject', 'Hardness too low', $this->qm);
    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->where('inspection_id', $failed->id)->firstOrFail();

    expect($rejected->state)->toBe('rejected')->and($rejected->decided_by)->toBe($this->qm->id)->and(($this->status)($batch))->toBe('quarantine')
        ->and($ncr->ncr_number)->toBe('NCR/AHD/26-27/00001')->and($ncr->severity)->toBe('major')->and((string) $ncr->quantity_affected)->toBe('500.000')->and($ncr->batch_id)->toBe($batch->id)
        ->and($ncr->description)->toContain('Hardness: measured 38 (allowed from 40 HRC)')->and($ncr->description)->not->toContain('Diameter');
});

it('makes the report critical when a critical check failed', function () {
    $i = ($this->start)(($this->batch)());
    ($this->record)($i, '10.30', '45');
    ($this->complete)($i);
    ($this->decide)($i, 'reject', '', $this->qm);

    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();
    expect($ncr->severity)->toBe('critical')->and($ncr->description)->toContain('Diameter: measured 10.3 (allowed from 9.9 up to 10.1 mm) [critical]');
});

it('lets a re-inspection take over the hold a failed one left, and release the batch when it passes', function () {
    $batch = ($this->batch)();
    $failed = ($this->failedInspection)($batch);
    ($this->decide)($failed, 'reject', '', $this->qm);

    $again = ($this->start)($batch);
    expect($again->held_batch)->toBeTrue()->and(($this->status)($batch))->toBe('quarantine');

    ($this->record)($again, '10.00', '41');
    ($this->complete)($again);

    expect(($this->status)($batch))->toBe('active')->and(BatchHold::withoutGlobalScopes(Tenancy::SCOPES)->where('batch_id', $batch->id)->pluck('action')->all())->toBe(['hold', 'release']);
});

it('accepts a failed lot with a deviation only for a reason and never by the person who inspected it', function () {
    $batch = ($this->batch)();
    $failed = ($this->failedInspection)($batch);
    $can = fn ($user, string $ability) => inCompany($this->acme, fn () => ($this->as)($user)->can($ability, $failed->fresh()));

    expect($can($this->qm, 'deviate'))->toBeTrue()->and($can($this->inspector, 'deviate'))->toBeFalse()->and($can($this->inspector, 'decide'))->toBeFalse()   // the inspector can do neither
        ->and(fn () => ($this->decide)($failed, 'deviation', 'ok', $this->qm))->toThrow(ValidationException::class, '10 to 500')
        ->and(fn () => ($this->decide)($failed, 'maybe', 'whatever reason here', $this->qm))->toThrow(ValidationException::class);

    // when the Quality Manager is the one who did the inspection, someone else must decide
    $own = ($this->start)(($this->batch)('R-2'), '100', $this->qm);
    ($this->record)($own, '10.05', '38', 'pass', $this->qm);
    ($this->complete)($own, $this->qm);
    expect($can($this->qm, 'deviate'))->toBeTrue();
    expect(fn () => ($this->decide)($own, 'deviation', 'Customer accepted this grade in writing', $this->qm))->toThrow(FieldRuleException::class, 'cannot accept its failure');

    $deviation = ($this->decide)($failed, 'deviation', 'Customer accepted this grade in writing', $this->qm2);
    expect($deviation->state)->toBe('deviation')->and(($this->status)($batch))->toBe('active')->and(NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)
        ->and(fn () => ($this->decide)($deviation, 'reject', '', $this->qm))->toThrow(FieldRuleException::class);
});

it('cancels an open inspection and releases its hold, with a reason', function () {
    $batch = ($this->batch)();
    $i = ($this->start)($batch);

    expect(fn () => ($this->do)(fn () => app(InspectionService::class)->cancel($i->fresh(), 'x', $this->inspector)))->toThrow(ValidationException::class);

    $cancelled = ($this->do)(fn () => app(InspectionService::class)->cancel($i->fresh(), 'Wrong lot chosen', $this->inspector));
    expect($cancelled->state)->toBe('cancelled')->and(($this->status)($batch))->toBe('active')
        ->and(fn () => ($this->do)(fn () => app(InspectionService::class)->cancel($cancelled->fresh(), 'Again', $this->inspector)))->toThrow(FieldRuleException::class);
});

it('inspects an item that is not tracked by batch without holding anything', function () {
    $plan = ($this->do)(function () {
        ($this->as)($this->qm);

        return app(InspectionPlanService::class)->create(($this->planInput)(['code' => 'QP-BOLT', 'item_id' => (string) $this->bolt->id, 'stage' => 'final']), $this->qm);
    });
    approveDocument($this->acme, $plan, $this->qm, $this->approver);

    $i = ($this->do)(fn () => app(InspectionService::class)->start(['inspection_plan_id' => (string) $plan->id, 'location_id' => (string) $this->ahd->id, 'lot_quantity' => '100'], $this->inspector));

    expect($i->batch_id)->toBeNull()->and($i->held_batch)->toBeFalse()->and($i->stage)->toBe('final')
        ->and(BatchHold::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)
        ->and(fn () => ($this->do)(fn () => app(InspectionService::class)->start(['inspection_plan_id' => (string) $plan->id, 'location_id' => (string) $this->ahd->id, 'lot_quantity' => '100', 'batch_id' => '5'], $this->inspector)))->toThrow(ValidationException::class);
});

// ---- non-conformance reports -------------------------------------------------------------------------------------------------------

it('raises a report by hand, decides what to do with the goods, and closes it', function () {
    $svc = fn () => app(NonConformanceService::class);
    $make = fn (array $over = []) => ($this->do)(function () use ($over) {
        ($this->as)($this->inspector);

        return app(NonConformanceService::class)->create(['location_id' => (string) $this->ahd->id, 'item_id' => (string) $this->bolt->id, 'quantity_affected' => '25', 'severity' => 'minor', 'description' => 'Thread damaged on the delivered bolts', ...$over], $this->inspector);
    });

    expect(fn () => $make(['severity' => 'huge']))->toThrow(ValidationException::class)->and(fn () => $make(['description' => 'bad']))->toThrow(ValidationException::class)
        ->and(fn () => $make(['batch_id' => '999999']))->toThrow(ValidationException::class)->and(fn () => $make(['quantity_affected' => 'lots']))->toThrow(ValidationException::class);

    $ncr = $make();
    expect($ncr->ncr_number)->toBe('NCR/AHD/26-27/00001')->and($ncr->state)->toBe('open')->and((string) $ncr->quantity_affected)->toBe('25.000');

    expect(fn () => ($this->do)(fn () => $svc()->close($ncr->fresh(), 'Done', $this->qm)))->toThrow(FieldRuleException::class, 'Decide what is to be done')
        ->and(fn () => ($this->do)(fn () => $svc()->disposition($ncr->fresh(), 'burn', 'Because', $this->qm)))->toThrow(ValidationException::class)
        ->and(fn () => ($this->do)(fn () => $svc()->disposition($ncr->fresh(), 'scrap', '', $this->qm)))->toThrow(ValidationException::class);

    $decided = ($this->do)(fn () => $svc()->disposition($ncr->fresh(), 'scrap', 'Scrap the 25 damaged bolts', $this->qm));
    expect($decided->state)->toBe('decided')->and($decided->disposition)->toBe('scrap');

    $closed = ($this->do)(fn () => $svc()->close($decided->fresh(), 'Scrapped on 12 Oct', $this->qm));
    expect($closed->state)->toBe('closed')->and($closed->closed_by)->toBe($this->qm->id)
        ->and(fn () => NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->find($closed->id)->forceFill(['description' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->do)(fn () => $svc()->disposition($closed->fresh(), 'rework', 'Again', $this->qm)))->toThrow(FieldRuleException::class);
});

it('treats "use as is" as a deviation: it needs that right, and releases the batch', function () {
    $batch = ($this->batch)();
    $failed = ($this->failedInspection)($batch);
    ($this->decide)($failed, 'reject', '', $this->qm);
    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();
    $svc = fn () => app(NonConformanceService::class);

    // a Production Manager may edit nothing here and holds no deviation right
    expect(fn () => ($this->do)(function () use ($ncr, $svc) {
        ($this->as)($this->pm);

        return $svc()->disposition($ncr->fresh(), 'use_as_is', 'The customer will take it', $this->pm);
    }))->toThrow(FieldRuleException::class, 'deviation');
    expect(($this->status)($batch))->toBe('quarantine');

    ($this->do)(fn () => $svc()->disposition($ncr->fresh(), 'use_as_is', 'The customer will take it', $this->qm2));
    expect(($this->status)($batch))->toBe('active')->and(BatchHold::withoutGlobalScopes(Tenancy::SCOPES)->where('batch_id', $batch->id)->latest('id')->first()->non_conformance_id)->toBe($ncr->id);
});

it('links the loss incident and corrective action, and will not close a critical report without an action', function () {
    $this->seed(IndustryTemplateSeeder::class);
    $auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $capaOwner = makeUser(['CAPA Owner'], [$this->acme]);
    $investigator = makeUser(['Investigator'], [$this->acme]);
    $admin = makeUser(['Company Admin'], [$this->acme]);
    ($this->as)($admin);
    app(IndustryTemplateService::class)->enable($auto, $admin);
    $centre = ($this->do)(fn () => CostCenter::query()->create(['code' => 'PRESS', 'name' => 'Press shop']));
    $category = LossCategory::query()->where('industry_template_id', $auto->id)->where('code', 'component_defect')->firstOrFail();

    $incident = ($this->do)(function () use ($auto, $category, $centre, $investigator) {
        ($this->as)($investigator);

        return app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $auto->id, 'loss_category_id' => (string) $category->id, 'location_id' => (string) $this->ahd->id, 'cost_center_id' => (string) $centre->id,
            'occurred_at' => '2026-10-05T10:00', 'severity' => 'medium', 'title' => 'Resin out of hardness', 'description' => 'A whole lot of resin was soft', 'custom_fields' => ['line' => 'L1'],
            'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => '5000'], 'assumptions' => 'Lot value'],
        ], $investigator);
    });
    $action = ($this->do)(function () use ($incident, $capaOwner, $investigator) {
        ($this->as)($investigator);

        return app(CapaService::class)->create($incident->fresh(), ['action_type' => 'corrective', 'title' => 'Audit the resin supplier', 'owner_id' => (string) $capaOwner->id, 'due_date' => '2026-10-30'], $investigator);
    });
    $otherIncident = ($this->do)(function () use ($auto, $category, $centre, $investigator) {
        ($this->as)($investigator);

        return app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $auto->id, 'loss_category_id' => (string) $category->id, 'location_id' => (string) $this->ahd->id, 'cost_center_id' => (string) $centre->id,
            'occurred_at' => '2026-10-06T10:00', 'severity' => 'low', 'title' => 'Another', 'description' => 'Something else entirely', 'custom_fields' => ['line' => 'L1'],
            'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => '100'], 'assumptions' => 'Counted by hand'],
        ], $investigator);
    });

    // a critical report
    $i = ($this->start)(($this->batch)());
    ($this->record)($i, '10.30', '45');
    ($this->complete)($i);
    ($this->decide)($i, 'reject', '', $this->qm);
    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();
    $svc = fn () => app(NonConformanceService::class);

    ($this->do)(fn () => $svc()->disposition($ncr->fresh(), 'return_to_supplier', 'Send it back with a complaint', $this->qm));
    expect(fn () => ($this->do)(fn () => $svc()->close($ncr->fresh(), 'Returned', $this->qm)))->toThrow(FieldRuleException::class, 'needs a corrective action');

    expect(fn () => ($this->do)(fn () => $svc()->link($ncr->fresh(), 999999, null, $this->qm)))->toThrow(FieldRuleException::class, 'not found')
        ->and(fn () => ($this->do)(fn () => $svc()->link($ncr->fresh(), null, 999999, $this->qm)))->toThrow(FieldRuleException::class, 'not found')
        ->and(fn () => ($this->do)(fn () => $svc()->link($ncr->fresh(), (int) $otherIncident->id, (int) $action->id, $this->qm)))->toThrow(FieldRuleException::class, 'different loss incident');

    $linked = ($this->do)(fn () => $svc()->link($ncr->fresh(), null, (int) $action->id, $this->qm));   // the action brings its incident with it
    expect($linked->corrective_action_id)->toBe($action->id)->and($linked->loss_incident_id)->toBe($incident->id);

    expect(($this->do)(fn () => $svc()->close($linked->fresh(), 'Returned to the supplier', $this->qm))->state)->toBe('closed');
});

// ---- who may, and the screens -------------------------------------------------------------------------------------------------------------

it('keeps checking to inspectors, deciding to managers, and other companies out', function () {
    $failed = ($this->failedInspection)(($this->batch)());
    $plan = ($this->plan)();
    $can = fn ($user, string $ability, $model) => inCompany($this->acme, fn () => ($this->as)($user)->can($ability, $model));

    expect($can($this->inspector, 'view', $failed))->toBeTrue()->and($can($this->inspector, 'create', Inspection::class))->toBeTrue()->and($can($this->inspector, 'create', InspectionPlan::class))->toBeFalse()
        ->and($can($this->inspector, 'decide', $failed->fresh()))->toBeFalse()->and($can($this->qm, 'decide', $failed->fresh()))->toBeTrue()
        ->and($can($this->qm, 'create', InspectionPlan::class))->toBeTrue()->and($can($this->bde, 'view', $failed))->toBeFalse()->and($can($this->bde, 'view', $plan))->toBeFalse()
        ->and($can($this->pm, 'view', $failed))->toBeTrue()->and($can($this->pm, 'record', $failed))->toBeFalse();

    $this->actingAs($this->outsider)->get(route('quality.inspections.show', $failed))->assertNotFound();
    $this->actingAs($this->bde)->get(route('quality.inspections.index'))->assertForbidden();
});

it('shows the plan screens and saves a plan from the form', function () {
    $plan = ($this->plan)();
    ($this->as)($this->qm);

    Livewire::actingAs($this->qm)->test(PlanList::class)->assertSee('QP-RESIN-IN')->assertSee('Incoming resin')->set('search', 'nothing-like-this')->assertDontSee('QP-RESIN-IN');
    Livewire::actingAs($this->qm)->test(PlanShow::class, ['plan' => $plan])->assertSee('Diameter')->assertSee('Vernier caliper')->assertSee('Start an inspection');

    $form = Livewire::actingAs($this->qm)->test(PlanForm::class)->set('code', 'qp-bolt')->set('name', 'Final bolts')->set('stage', 'final')->call('pickItem', $this->bolt->id)
        ->set('rows.0.name', 'Length')->set('rows.0.min_value', '49.5')->set('rows.0.max_value', '50.5')->set('rows.0.unit_text', 'mm')->call('save')->assertHasNoErrors();
    $made = InspectionPlan::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'QP-BOLT')->firstOrFail();
    $form->assertRedirect(route('quality.plans.show', $made));
    expect($made->parameters)->toHaveCount(1);
});

it('runs an inspection through its screens: start, fill in, complete, reject, and raise the report', function () {
    $batch = ($this->batch)();
    $plan = ($this->plan)();
    ($this->as)($this->inspector);

    Livewire::actingAs($this->inspector)->test(InspectionForm::class)->set('planId', (string) $plan->id)->set('locationId', (string) $this->ahd->id)->set('lotQuantity', '500')
        ->set('batchId', (string) $batch->id)->call('save')->assertHasNoErrors();
    $i = Inspection::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();

    Livewire::actingAs($this->inspector)->test(InspectionList::class)->assertSeeHtml('wire:key="qi-'.$i->id.'"')->set('state', 'accepted')->assertDontSeeHtml('wire:key="qi-'.$i->id.'"');

    $diameter = ($this->resultOf)($i, 'Diameter')->id;
    $hardness = ($this->resultOf)($i, 'Hardness')->id;
    $surface = ($this->resultOf)($i, 'Surface')->id;
    $page = fn ($user) => Livewire::actingAs($user)->test(InspectionShow::class, ['inspection' => $i->fresh()]);

    $page($this->inspector)->assertSee('Diameter')->set("entry.$diameter.value", '10.05')->set("entry.$hardness.value", '38')->set("entry.$surface.check", 'pass')->call('completeInspection')->assertHasNoErrors();
    expect($i->fresh()->state)->toBe('failed');

    $page($this->inspector)->call('openDialog', 'reject')->assertForbidden();                                               // the inspector cannot decide
    $page($this->qm)->call('openDialog', 'reject')->set('reason', 'Hardness too low')->call('finishDialog')->assertHasNoErrors();
    expect($i->fresh()->state)->toBe('rejected')->and(NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();
    Livewire::actingAs($this->qm)->test(NcrList::class)->assertSee($ncr->ncr_number)->set('severity', 'critical')->assertDontSeeHtml('wire:key="ncr-'.$ncr->id.'"');
    Livewire::actingAs($this->qm)->test(NcrShow::class, ['ncr' => $ncr])->assertSee('Hardness: measured 38')
        ->set('disposition', 'rework')->set('dispositionNote', 'Blend with a good lot')->call('decide')->assertHasNoErrors()
        ->call('openClose')->set('closingNote', 'Blended and re-inspected')->call('closeReport')->assertHasNoErrors();
    expect($ncr->fresh()->state)->toBe('closed');
});

it('raises a report from its form', function () {
    ($this->as)($this->inspector);

    Livewire::actingAs($this->inspector)->test(NcrForm::class)->call('pickItem', $this->bolt->id)->set('locationId', (string) $this->ahd->id)->set('quantity', '12')->set('severity', 'minor')
        ->set('description', 'Packaging torn on arrival at the gate')->call('save')->assertHasNoErrors();

    expect(NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail()->item_name)->toBe('Hex Bolt M10');
});

it('opens every quality page for a manager', function () {
    $failed = ($this->failedInspection)(($this->batch)());
    ($this->decide)($failed, 'reject', '', $this->qm);
    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();

    foreach (['/quality/plans', '/quality/plans/create', '/quality/plans/'.($this->plan)()->id, '/quality/inspections', '/quality/inspections/create', '/quality/inspections/'.$failed->id,
        '/quality/non-conformances', '/quality/non-conformances/create', '/quality/non-conformances/'.$ncr->id] as $url) {
        $this->actingAs($this->qm)->get($url)->assertOk();
    }
});

it('lists inspections and non-conformances in the reports, with the figures the pages show', function () {
    $batch = ($this->batch)();
    $failed = ($this->failedInspection)($batch);
    ($this->decide)($failed, 'reject', '', $this->qm);
    $ncr = NonConformance::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();
    $run = fn (string $key, $user) => ($this->do)(function () use ($key, $user) {
        ($this->as)($user);
        $report = app(ReportCatalog::class)->find($key);
        $input = ReportInput::resolve($report, [], $user);

        return ['report' => $report, 'rows' => iterator_to_array($report->rows($input, $user), false), 'summary' => $report->summary($input, $user)];
    });

    $inspections = $run('inspection-register', $this->qm);
    expect($inspections['report']->canRun($this->bde))->toBeFalse()->and($inspections['rows'])->toHaveCount(1)
        ->and($inspections['rows'][0]['inspection_number'])->toBe($failed->inspection_number)->and((int) $inspections['rows'][0]['failed_checks'])->toBe(1)
        ->and($inspections['rows'][0]['state_label'])->toBe('Rejected')->and($inspections['rows'][0]['batch_no'])->toBe('R-1')->and($inspections['summary']['Rejected'])->toBe('1');

    $reports = $run('ncr-register', $this->qm);
    expect($reports['rows'])->toHaveCount(1)->and($reports['rows'][0]['ncr_number'])->toBe($ncr->ncr_number)->and($reports['rows'][0]['severity_label'])->toBe('Major')
        ->and($reports['rows'][0]['quantity_text'])->toBe('500')->and($reports['summary']['Not closed and without a corrective action'])->toBe('1')
        ->and($run('ncr-register', $this->inspector)['report']->canExport($this->inspector))->toBeFalse();   // viewing is not exporting
});
