<?php

namespace App\Modules\Production\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionFigures;
use App\Modules\Production\Services\ProductionOrderService;
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
 * One production order: where it stands, each component with what is required, issued and still allowed, the form to issue material, the form to report output, the entries made,
 * and releasing, completing and cancelling. The forms carry an entry key made when they were opened, so a double click is one entry.
 */
#[Title('Production order')]
class ProductionOrderShow extends Component
{
    #[Locked]
    public int $orderId = 0;

    public string $componentId = '';

    public string $issueBatch = '';

    public string $issueQuantity = '';

    public string $issueKey = '';

    public string $goodQuantity = '';

    public string $rejectedQuantity = '';

    public string $rejectReason = '';

    public string $batchNo = '';

    public string $mfgDate = '';

    public string $expiryDate = '';

    public string $outputKey = '';

    public string $dialog = '';

    public string $note = '';

    public function mount(ProductionOrder $order): void
    {
        Gate::authorize('view', $order);
        $this->orderId = $order->id;
        $this->issueKey = Str::random(20);
        $this->outputKey = Str::random(20);
    }

    #[Computed]
    public function orderModel(): ProductionOrder
    {
        $order = ProductionOrder::query()->with(['location:id,name', 'bom:id,code', 'item:id,name,tracking,has_expiry,base_unit_id', 'item.baseUnit:id,code', 'components.item:id,tracking,base_unit_id', 'components.item.baseUnit:id,code'])->findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    private function actor(): User
    {
        return Auth::user();
    }

    public function release(ProductionOrderService $service): void
    {
        Gate::authorize('release', $this->orderModel());
        $this->run(fn () => $service->release($this->orderModel(), $this->actor()), 'The order is released to the shop floor.');
    }

    public function issueMaterial(ProductionOrderService $service): void
    {
        Gate::authorize('execute', $this->orderModel());

        if ($this->run(fn () => $service->issue($this->orderModel(), (int) $this->componentId, $this->issueBatch === '' ? null : (int) $this->issueBatch, $this->issueQuantity, $this->issueKey, $this->actor()), 'Material issued.')) {
            $this->issueQuantity = '';
            $this->issueBatch = '';
            $this->issueKey = Str::random(20);
        }
    }

    public function reportOutput(ProductionOrderService $service): void
    {
        Gate::authorize('execute', $this->orderModel());
        $input = ['good_quantity' => $this->goodQuantity, 'rejected_quantity' => $this->rejectedQuantity, 'reject_reason' => $this->rejectReason, 'batch_no' => $this->batchNo, 'mfg_date' => $this->mfgDate, 'expiry_date' => $this->expiryDate];

        if ($this->run(fn () => $service->recordOutput($this->orderModel(), $input, $this->outputKey, $this->actor()), 'Output recorded.')) {
            $this->goodQuantity = $this->rejectedQuantity = $this->rejectReason = '';
            $this->outputKey = Str::random(20);
        }
    }

    public function openDialog(string $which): void
    {
        Gate::authorize('release', $this->orderModel());
        $this->dialog = in_array($which, ['complete', 'cancel'], true) ? $which : '';
        $this->note = '';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->resetErrorBag();
    }

    public function finishOrder(ProductionOrderService $service): void
    {
        Gate::authorize('release', $this->orderModel());
        $done = $this->dialog === 'cancel'
            ? $this->run(fn () => $service->cancel($this->orderModel(), $this->note, $this->actor()), 'The order is cancelled.', 'note')
            : $this->run(fn () => $service->complete($this->orderModel(), $this->note, $this->actor()), 'The order is completed.');

        $done && $this->dialog = '';
    }

    /** Run a service call; show its refusal where a person will see it. Returns whether it worked. */
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

    /** @return array<int, string> batch id => label (soonest expiry first) of the batches of this component that are on hand and usable at the order's branch */
    private function batchOptions(ProductionOrder $order): array
    {
        $component = $order->components->firstWhere('id', (int) $this->componentId);

        if ($component === null || $component->item?->tracking !== 'batch') {
            return [];
        }

        return DB::table('stock_balances as s')->join('batches as b', 'b.id', '=', 's.batch_id')
            ->where('s.company_id', $order->company_id)->where('s.location_id', $order->location_id)->where('s.item_id', $component->item_id)->where('s.quantity', '>', 0)
            ->where('b.status', 'active')->whereNull('b.deleted_at')->where(fn ($q) => $q->whereNull('b.expiry_date')->orWhere('b.expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('b.expiry_date is null')->orderBy('b.expiry_date')->orderBy('b.id')->get(['b.id', 'b.batch_no', 'b.expiry_date', 's.quantity'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->batch_no.($r->expiry_date ? ' · exp '.date('d-m-Y', strtotime((string) $r->expiry_date)) : '').' · '.rtrim(rtrim((string) $r->quantity, '0'), '.').' on hand'])->all();
    }

    public function render(ProductionFigures $figures): View
    {
        $order = $this->orderModel();

        return view('production.order-show', [
            'order' => $order, 'f' => $figures->forOrder($order), 'states' => ProductionOrder::STATES,
            'issues' => $order->issues()->with(['component:id,item_name', 'batch:id,batch_no'])->latest('id')->limit(50)->get(),
            'outputs' => $order->outputs()->with('batch:id,batch_no')->latest('id')->limit(50)->get(),
            'batches' => $this->batchOptions($order),
            'canEdit' => Gate::allows('update', $order), 'canRelease' => Gate::allows('release', $order), 'canExecute' => Gate::allows('execute', $order),
            'tracked' => $order->item?->tracking === 'batch',
            'history' => Gate::allows('viewAudit', $order)
                ? ActivityLog::query()->with('causer')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
