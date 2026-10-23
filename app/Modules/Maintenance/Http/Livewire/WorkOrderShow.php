<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Services\MaintenanceFigures;
use App\Modules\Maintenance\Services\MaintenanceOrderService;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One work order: the tasks to tick, the spare parts taken from stock, the hours worked, downtime, who it is assigned to, the loss incident it is linked to, and completing or cancelling it.
 * The forms carry an entry key made when they were opened, so a double click is one entry.
 */
#[Title('Work order')]
class WorkOrderShow extends Component
{
    #[Locked]
    public int $orderId = 0;

    public string $assigneeId = '';

    public string $incidentId = '';

    public string $partSearch = '';

    public string $partItemId = '';

    public string $partItemLabel = '';

    public string $partBatch = '';

    public string $partQuantity = '';

    public string $partKey = '';

    public string $hours = '';

    public string $workDate = '';

    public string $hourNote = '';

    public string $hoursKey = '';

    public string $dialog = '';

    public string $note = '';

    public string $restoredAt = '';

    public function mount(MaintenanceOrder $order): void
    {
        Gate::authorize('view', $order);
        $this->orderId = $order->id;
        $this->assigneeId = (string) $order->assigned_to;
        $this->incidentId = (string) $order->loss_incident_id;
        $this->partKey = Str::random(20);
        $this->hoursKey = Str::random(20);
        $this->workDate = now()->toDateString();
    }

    #[Computed]
    public function orderModel(): MaintenanceOrder
    {
        $order = MaintenanceOrder::query()->with(['asset', 'location:id,name', 'plan:id,code', 'assignee:id,name', 'reporter:id,name', 'lossIncident:id,incident_number,title', 'tasks'])->findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    public function assignTo(MaintenanceOrderService $service): void
    {
        Gate::authorize('assign', $this->orderModel());
        $this->run(fn () => $service->assign($this->orderModel(), ctype_digit($this->assigneeId) ? (int) $this->assigneeId : null, Auth::user()), 'Saved.');
    }

    public function startWork(MaintenanceOrderService $service): void
    {
        Gate::authorize('execute', $this->orderModel());
        $this->run(fn () => $service->start($this->orderModel(), Auth::user()), 'Work has started.');
    }

    public function tickTask(MaintenanceOrderService $service, int $taskId): void
    {
        Gate::authorize('execute', $this->orderModel());
        $this->run(fn () => $service->tick($this->orderModel(), $taskId, Auth::user()), 'Task done.');
    }

    public function pickPart(int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->partItemId = (string) $item->id;
            $this->partItemLabel = trim($item->code.' · '.$item->name);
            $this->partSearch = '';
            $this->partBatch = '';
        }
    }

    public function clearPart(): void
    {
        $this->partItemId = $this->partItemLabel = $this->partBatch = '';
    }

    public function issueSparePart(MaintenanceOrderService $service): void
    {
        Gate::authorize('execute', $this->orderModel());

        if ($this->run(fn () => $service->issuePart($this->orderModel(), (int) $this->partItemId, $this->partBatch === '' ? null : (int) $this->partBatch, $this->partQuantity, $this->partKey, Auth::user()), 'Part issued.')) {
            $this->partQuantity = '';
            $this->partBatch = '';
            $this->partKey = Str::random(20);
        }
    }

    public function logWork(MaintenanceOrderService $service): void
    {
        Gate::authorize('execute', $this->orderModel());

        if ($this->run(fn () => $service->logHours($this->orderModel(), $this->hours, $this->workDate, $this->hourNote, $this->hoursKey, Auth::user()), 'Hours logged.')) {
            $this->hours = $this->hourNote = '';
            $this->hoursKey = Str::random(20);
        }
    }

    public function linkLoss(MaintenanceOrderService $service): void
    {
        Gate::authorize('link', $this->orderModel());
        $this->run(fn () => $service->linkIncident($this->orderModel(), ctype_digit($this->incidentId) ? (int) $this->incidentId : null, Auth::user()), 'Saved.');
    }

    public function openDialog(string $which): void
    {
        Gate::authorize($which === 'cancel' ? 'link' : 'execute', $this->orderModel());
        $this->dialog = in_array($which, ['complete', 'cancel'], true) ? $which : '';
        $this->note = '';
        $this->restoredAt = now()->format('Y-m-d\TH:i');
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->resetErrorBag();
    }

    public function finishDialog(MaintenanceOrderService $service): void
    {
        Gate::authorize($this->dialog === 'cancel' ? 'link' : 'execute', $this->orderModel());

        $done = $this->dialog === 'cancel'
            ? $this->run(fn () => $service->cancel($this->orderModel(), $this->note, Auth::user()), 'The work order is cancelled.', 'note')
            : $this->run(fn () => $service->complete($this->orderModel(), $this->note, $this->restoredAt, Auth::user()), 'The work order is completed.');

        $done && $this->dialog = '';
    }

    private function run(\Closure $call, string $done, ?string $reasonField = null): bool
    {
        $this->resetErrorBag();

        try {
            $call();
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if ($reasonField !== null && isset($errors['reason'])) {
                $errors[$reasonField] = $errors['reason'];
            }

            $this->setErrorBag($errors);

            return false;
        } catch (FieldRuleException|StockException $e) {
            $this->addError('workflow', $e->getMessage());

            return false;
        }

        unset($this->orderModel);
        session()->flash('status', $done);

        return true;
    }

    /** @return array<int, string> */
    private function batchOptions(MaintenanceOrder $order): array
    {
        $item = ctype_digit($this->partItemId) ? app(ItemLookup::class)->usable((int) $this->partItemId) : null;

        if ($item === null || $item->tracking !== 'batch') {
            return [];
        }

        return DB::table('stock_balances as s')->join('batches as b', 'b.id', '=', 's.batch_id')
            ->where('s.company_id', $order->company_id)->where('s.location_id', $order->location_id)->where('s.item_id', $item->getKey())->where('s.quantity', '>', 0)
            ->where('b.status', 'active')->whereNull('b.deleted_at')->where(fn ($q) => $q->whereNull('b.expiry_date')->orWhere('b.expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('b.expiry_date is null')->orderBy('b.expiry_date')->orderBy('b.id')->get(['b.id', 'b.batch_no', 'b.expiry_date', 's.quantity'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->batch_no.($r->expiry_date ? ' · exp '.date('d-m-Y', strtotime((string) $r->expiry_date)) : '').' · '.rtrim(rtrim((string) $r->quantity, '0'), '.').' on hand'])->all();
    }

    public function render(MaintenanceFigures $figures): View
    {
        $order = $this->orderModel();
        $user = Auth::user();

        return view('maintenance.order-show', [
            'order' => $order, 'f' => $figures->forOrder($order), 'states' => MaintenanceOrder::STATES, 'types' => MaintenanceOrder::TYPES, 'priorities' => MaintenanceOrder::PRIORITIES,
            'parts' => $order->parts()->with(['order:id'])->latest('id')->limit(50)->get()->map(fn ($p) => $p->setRelation('item', Item::query()->find($p->item_id, ['id', 'name']))),
            'labour' => $order->labour()->latest('id')->limit(50)->get()->map(fn ($l) => $l->setRelation('who', User::query()->find($l->user_id, ['id', 'name']))),
            'partResults' => trim($this->partSearch) === '' ? collect() : app(ItemLookup::class)->search($this->partSearch, 6), 'batches' => $this->batchOptions($order),
            'technicians' => Gate::allows('assign', $order) ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->filter(fn (User $u) => $u->can('maintenance_order.execute'))->mapWithKeys(fn ($u) => [$u->id => $u->name])->all() : [],
            'incidents' => LossIncident::query()->orderByDesc('id')->limit(50)->get(['id', 'incident_number', 'title'])->mapWithKeys(fn ($i) => [$i->id => $i->incident_number.' · '.$i->title])->all(),
            'canExecute' => Gate::allows('execute', $order), 'canAssign' => Gate::allows('assign', $order), 'canLink' => Gate::allows('link', $order), 'canCancel' => $user !== null && Gate::allows('link', $order),
            'history' => Gate::allows('viewAudit', $order)
                ? ActivityLog::query()->with('causer')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
