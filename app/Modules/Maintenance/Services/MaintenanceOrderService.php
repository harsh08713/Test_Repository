<?php

namespace App\Modules\Maintenance\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceLabour;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Models\MaintenancePart;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Maintenance\Models\MaintenanceTask;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance work orders — the one door for their data and for everything that moves stock because of them.
 *
 *  - **Reporting / planning** a work order (a breakdown, a preventive job, a corrective job) for an active asset at a branch the person works in. A breakdown records when the asset
 *    **stopped**. The number is taken first, in the transaction. A checklist (one task per line) becomes the tasks to tick.
 *  - **Assigning** to an active technician who may do the work; **starting** (also done by the first tick, part or hours); **ticking tasks**; **issuing spare parts** (`StockService::issue`,
 *    once per entry, approved active items only); **logging hours**.
 *  - **Completing** needs every task ticked and a note; a breakdown records when the asset was **running again** (not before it stopped, not in the future) — downtime is worked out from
 *    the two. A preventive order moves its schedule on from the day it was done. **Cancelling** is only before any part was issued or hours logged.
 *  - **`generateDue()`** makes the preventive orders that have come due, once for each due date (`unique(plan, due date)`) and never while the schedule still has one open.
 *  - Every change takes the order's row lock first; every part and hours entry carries an `entry_key`, so a double click is one entry.
 */
final class MaintenanceOrderService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  asset_id, type, priority, title, description, due_on, stopped_at, assigned_to, checklist
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): MaintenanceOrder
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $asset = is_numeric($input['asset_id'] ?? null) ? Asset::query()->where('status', 'active')->find((int) $input['asset_id']) : null;
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($asset === null || ($reach !== null && ! in_array((int) $asset->location_id, $reach, true))) {
            $errors['asset_id'] = 'Choose an active asset at a branch you work in.';
        }

        $type = (string) ($input['type'] ?? '');

        if (! array_key_exists($type, MaintenanceOrder::TYPES)) {
            $errors['type'] = 'Choose breakdown, preventive or corrective.';
        }

        $priority = (string) ($input['priority'] ?? 'normal');

        if (! array_key_exists($priority, MaintenanceOrder::PRIORITIES)) {
            $errors['priority'] = 'Choose a priority.';
        }

        $title = trim((string) ($input['title'] ?? ''));

        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            $errors['title'] = 'Say what needs doing (3 to 150 characters).';
        }

        $description = trim((string) ($input['description'] ?? ''));

        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'The description can be at most 2000 characters.';
        }

        $due = trim((string) ($input['due_on'] ?? ''));

        if ($due !== '' && ((($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $due)) === false) || $d->format('Y-m-d') !== $due)) {
            $errors['due_on'] = 'Enter a valid date, or leave it empty.';
        }

        $stopped = null;

        if ($type === 'breakdown') {
            $given = trim((string) ($input['stopped_at'] ?? ''));
            $moment = $given === '' ? CarbonImmutable::now() : $this->moment($given);

            if ($moment === null || $moment->gt(CarbonImmutable::now()->addMinutes(5)) || $moment->lt(CarbonImmutable::now()->subDays(90))) {
                $errors['stopped_at'] = 'Say when the asset stopped: not in the future, and within the last 90 days.';
            } else {
                $stopped = $moment;
            }
        }

        $assignee = null;

        if (trim((string) ($input['assigned_to'] ?? '')) !== '') {
            $assignee = $this->technician((int) $input['assigned_to'], $companyId);

            if ($assignee === null || ! $actor->can('maintenance_order.assign')) {
                $errors['assigned_to'] = $assignee === null ? 'Choose someone who does maintenance work.' : 'You may not assign work.';
            }
        }

        $tasks = array_values(array_filter(array_map(fn ($l) => trim((string) $l), preg_split('/\R/', (string) ($input['checklist'] ?? '')) ?: []), fn ($l) => $l !== ''));

        if (count($tasks) > MaintenancePlanService::MAX_TASKS || collect($tasks)->contains(fn ($l) => mb_strlen($l) > 200)) {
            $errors['checklist'] = 'A checklist can have at most '.MaintenancePlanService::MAX_TASKS.' lines of up to 200 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->make($asset, null, null, $type, $priority, $title, $description, $due === '' ? null : $due, $stopped, $assignee?->getKey(), $actor->getKey(), $tasks);
    }

    /**
     * Make the preventive orders that have come due: an active schedule on an active asset whose due date is within its lead time, and that has no open order. Safe to run twice.
     *
     * @return int orders made
     */
    public function generateDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $made = 0;

        foreach (MaintenancePlan::query()->where('status', 'active')->whereHas('asset', fn ($q) => $q->where('status', 'active'))->with('asset')->orderBy('id')->get() as $plan) {
            if ($plan->next_due_on->toDateString() > $today->addDays($plan->lead_days)->toDateString()) {
                continue;
            }

            if (MaintenanceOrder::query()->where('maintenance_plan_id', $plan->getKey())->whereIn('state', ['open', 'in_progress'])->exists()) {
                continue;
            }

            $tasks = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $plan->checklist) ?: []), fn ($l) => $l !== ''));

            try {
                $this->make($plan->asset, $plan, $plan->next_due_on->toDateString(), 'preventive', 'normal', $plan->name, null, $plan->next_due_on->toDateString(), null, null, null, $tasks);
                $made++;
            } catch (UniqueConstraintViolationException) {
                // another run made it first: once for each due date
            }
        }

        return $made;
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function assign(MaintenanceOrder $order, ?int $userId, User $actor): MaintenanceOrder
    {
        $assignee = $userId === null ? null : $this->technician($userId, (int) $order->company_id);

        if ($userId !== null && $assignee === null) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose someone who does maintenance work.']);
        }

        return DB::transaction(function () use ($order, $assignee, $actor) {
            $fresh = $this->lock($order);
            $this->assertOpen($fresh);
            $fresh->forceFill(['assigned_to' => $assignee?->getKey(), 'updated_by' => $actor->getKey()])->save();
            activity('maintenance_orders')->causedBy($actor)->performedOn($fresh)->event('assigned')->withProperties(['assigned_to' => $assignee?->getKey()])->log('assigned');

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function start(MaintenanceOrder $order, User $actor): MaintenanceOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $fresh = $this->lock($order);
            $this->assertOpen($fresh);
            $this->begin($fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function tick(MaintenanceOrder $order, int $taskId, User $actor): MaintenanceTask
    {
        return DB::transaction(function () use ($order, $taskId, $actor) {
            $fresh = $this->lock($order);
            $this->assertOpen($fresh);

            $task = MaintenanceTask::query()->where('maintenance_order_id', $fresh->getKey())->lockForUpdate()->find($taskId);

            if ($task === null) {
                throw ValidationException::withMessages(['task' => 'That is not a task of this work order.']);
            }

            if ($task->done_at !== null) {
                return $task;
            }

            $task->forceFill(['done_at' => now(), 'done_by' => $actor->getKey()])->save();
            $this->begin($fresh, $actor);

            return $task->refresh();
        });
    }

    /**
     * Take a spare part out of stock for the order (at the order's branch).
     *
     * @throws ValidationException
     * @throws FieldRuleException
     * @throws StockException
     */
    public function issuePart(MaintenanceOrder $order, int $itemId, ?int $batchId, string $quantity, string $entryKey, User $actor): MaintenancePart
    {
        $quantity = $this->quantity($quantity);
        $entryKey = $this->key($entryKey);

        return DB::transaction(function () use ($order, $itemId, $batchId, $quantity, $entryKey, $actor) {
            $fresh = $this->lock($order);

            if (($again = MaintenancePart::query()->where('maintenance_order_id', $fresh->getKey())->where('entry_key', $entryKey)->first()) !== null) {
                return $again;
            }

            $this->assertOpen($fresh);
            $item = $this->items->usable($itemId);

            if ($item === null) {
                throw ValidationException::withMessages(['item_id' => 'Choose an approved, active item.']);
            }

            $batch = $batchId === null ? null : Batch::query()->where('item_id', $item->getKey())->find($batchId);

            if ($batchId !== null && $batch === null) {
                throw ValidationException::withMessages(['batch' => 'That batch is not a batch of this item.']);
            }

            $part = new MaintenancePart;
            $part->forceFill([
                'company_id' => $fresh->company_id, 'maintenance_order_id' => $fresh->getKey(), 'item_id' => $item->getKey(), 'batch_id' => $batch?->getKey(), 'quantity' => $quantity,
                'entry_key' => $entryKey, 'issued_by' => $actor->getKey(), 'issued_at' => now(),
            ])->save();

            $this->stock->issue($item, (int) $fresh->location_id, $quantity, $batch, 'maintenance_part', (int) $part->getKey(), 1, $actor);
            $this->begin($fresh, $actor);

            return $part;
        }, 3);
    }

    /**
     * Hours worked by the person on the order.
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function logHours(MaintenanceOrder $order, string $hours, ?string $workDate, string $note, string $entryKey, User $actor): MaintenanceLabour
    {
        $entryKey = $this->key($entryKey);
        $hours = trim($hours);
        $note = trim($note);
        $date = trim((string) $workDate) === '' ? now()->toDateString() : trim((string) $workDate);
        $errors = [];

        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $hours) !== 1 || bccomp($hours, '0', 2) <= 0 || bccomp($hours, '24', 2) > 0) {
            $errors['hours'] = 'Enter the hours worked: more than 0 and at most 24, up to 2 decimals.';
        }

        if (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $d->format('Y-m-d') !== $date || $date > now()->toDateString() || $date < now()->subDays(30)->toDateString()) {
            $errors['work_date'] = 'Enter the day it was worked: not in the future, and within the last 30 days.';
        }

        if (mb_strlen($note) > 200) {
            $errors['note'] = 'A note can be at most 200 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($order, $hours, $date, $note, $entryKey, $actor) {
            $fresh = $this->lock($order);

            if (($again = MaintenanceLabour::query()->where('maintenance_order_id', $fresh->getKey())->where('entry_key', $entryKey)->first()) !== null) {
                return $again;
            }

            $this->assertOpen($fresh);

            $row = new MaintenanceLabour;
            $row->forceFill([
                'company_id' => $fresh->company_id, 'maintenance_order_id' => $fresh->getKey(), 'user_id' => $actor->getKey(), 'work_date' => $date,
                'hours' => bcadd($hours, '0', 2), 'note' => $note === '' ? null : $note, 'entry_key' => $entryKey,
            ])->save();
            $this->begin($fresh, $actor);

            return $row;
        }, 3);
    }

    /**
     * @param  string|null  $restoredAt  a breakdown: when the asset was running again (default now)
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function complete(MaintenanceOrder $order, string $note, ?string $restoredAt, User $actor): MaintenanceOrder
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Say what was done (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $note, $restoredAt, $actor) {
            $fresh = $this->lock($order);
            $this->assertOpen($fresh);

            if (MaintenanceTask::query()->where('maintenance_order_id', $fresh->getKey())->whereNull('done_at')->exists()) {
                throw ValidationException::withMessages(['tasks' => 'Tick every task before completing the work order.']);
            }

            $restored = null;

            if ($fresh->type === 'breakdown') {
                $restored = trim((string) $restoredAt) === '' ? CarbonImmutable::now() : $this->moment((string) $restoredAt);

                if ($restored === null || $restored->lt($fresh->stopped_at) || $restored->gt(CarbonImmutable::now()->addMinutes(5))) {
                    throw ValidationException::withMessages(['restored_at' => 'Say when the asset was running again: not before it stopped, and not in the future.']);
                }
            }

            $fresh->forceFill([
                'state' => 'completed', 'completed_at' => now(), 'completion_note' => $note, 'restored_at' => $restored, 'started_at' => $fresh->started_at ?? now(), 'updated_by' => $actor->getKey(),
            ])->save();

            if ($fresh->maintenance_plan_id !== null && ($plan = MaintenancePlan::query()->lockForUpdate()->find($fresh->maintenance_plan_id)) !== null) {
                $plan->forceFill(['last_done_on' => now()->toDateString(), 'next_due_on' => $plan->dueAfter(now())->toDateString(), 'updated_by' => $actor->getKey()])->save();
            }

            activity('maintenance_orders')->causedBy($actor)->performedOn($fresh)->event('completed')->log('completed');

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function cancel(MaintenanceOrder $order, string $reason, User $actor): MaintenanceOrder
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the work order is being cancelled (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $fresh = $this->lock($order);
            $this->assertOpen($fresh);

            if (MaintenancePart::query()->where('maintenance_order_id', $fresh->getKey())->exists() || MaintenanceLabour::query()->where('maintenance_order_id', $fresh->getKey())->exists()) {
                throw new FieldRuleException('Parts have been issued or hours logged on this work order, so it cannot be cancelled: complete it instead.');
            }

            $fresh->forceFill(['state' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actor->getKey()])->save();
            activity('maintenance_orders')->causedBy($actor)->performedOn($fresh)->event('cancelled')->withProperties(['reason' => $reason])->log('cancelled');

            return $fresh->refresh();
        });
    }

    /**
     * Link (or unlink, with null) the loss incident a breakdown led to. By hand: nothing is created automatically.
     *
     * @throws FieldRuleException
     */
    public function linkIncident(MaintenanceOrder $order, ?int $lossIncidentId, User $actor): MaintenanceOrder
    {
        if ($lossIncidentId !== null && LossIncident::query()->whereKey($lossIncidentId)->doesntExist()) {
            throw new FieldRuleException('That loss incident was not found.');
        }

        return DB::transaction(function () use ($order, $lossIncidentId, $actor) {
            $fresh = $this->lock($order);
            $fresh->forceFill(['loss_incident_id' => $lossIncidentId, 'updated_by' => $actor->getKey()])->save();
            activity('maintenance_orders')->causedBy($actor)->performedOn($fresh)->event('linked')->withProperties(['loss_incident_id' => $lossIncidentId])->log('linked');

            return $fresh->refresh();
        });
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /** @param  list<string>  $tasks */
    private function make(Asset $asset, ?MaintenancePlan $plan, ?string $planDue, string $type, string $priority, string $title, ?string $description, ?string $due, ?CarbonImmutable $stopped, ?int $assignee, ?int $by, array $tasks): MaintenanceOrder
    {
        return DB::transaction(function () use ($asset, $plan, $planDue, $type, $priority, $title, $description, $due, $stopped, $assignee, $by, $tasks) {
            $number = $this->numbers->next(DocumentType::MaintenanceOrder, (int) $asset->location_id, now()->startOfDay());

            $order = new MaintenanceOrder;
            $order->forceFill([
                'company_id' => (int) $asset->company_id, 'location_id' => $asset->location_id, 'order_number' => $number, 'asset_id' => $asset->getKey(), 'maintenance_plan_id' => $plan?->getKey(),
                'plan_due_on' => $planDue, 'type' => $type, 'priority' => $type === 'breakdown' && $priority === 'normal' && $asset->criticality === 'high' ? 'urgent' : $priority, 'title' => $title,
                'description' => $description === '' ? null : $description, 'reported_by' => $by, 'assigned_to' => $assignee, 'due_on' => $due, 'stopped_at' => $stopped, 'created_by' => $by, 'updated_by' => $by,
            ])->save();

            foreach ($tasks as $n => $text) {
                $row = new MaintenanceTask;
                $row->forceFill(['company_id' => $order->company_id, 'maintenance_order_id' => $order->getKey(), 'line_no' => $n + 1, 'description' => $text])->save();
            }

            activity('maintenance_orders')->causedBy($by === null ? null : User::query()->find($by))->performedOn($order)->event('created')->withProperties(['type' => $type])->log('created');

            return $order->refresh();
        }, 3);
    }

    private function begin(MaintenanceOrder $order, User $actor): void
    {
        if ($order->state === 'open') {
            $order->forceFill(['state' => 'in_progress', 'started_at' => now(), 'updated_by' => $actor->getKey()])->save();
        }
    }

    private function lock(MaintenanceOrder $order): MaintenanceOrder
    {
        return MaintenanceOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }

    /** @throws FieldRuleException */
    private function assertOpen(MaintenanceOrder $order): void
    {
        if (! $order->isOpen()) {
            throw new FieldRuleException('This work order is already finished.');
        }
    }

    /** An active user of the company who may do maintenance work. */
    private function technician(int $userId, int $companyId): ?User
    {
        $user = User::query()->where('is_active', true)->find($userId);

        return $user !== null && $this->tenant->userCanAccessCompany($user, $companyId) && $user->can('maintenance_order.execute') ? $user : null;
    }

    private function moment(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, trim($value));
            } catch (\Throwable) {
                continue;   // not this shape
            }

            if ($parsed !== null && $parsed->format($format) === trim($value)) {
                return $parsed;
            }
        }

        return null;
    }

    /** @throws ValidationException */
    private function quantity(string $quantity): string
    {
        $quantity = trim($quantity);

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero, with up to 3 decimals.']);
        }

        return bcadd($quantity, '0', 3);
    }

    /** @throws ValidationException */
    private function key(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_\-]{8,40}$/', $key) !== 1) {
            throw ValidationException::withMessages(['entry' => 'This form is out of date: reload the page and try again.']);
        }

        return $key;
    }
}
