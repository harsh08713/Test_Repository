<?php

namespace App\Modules\Sales\Services;

use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Enums\CreditResult;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Support\Format;

/**
 * The credit check a sales order carries: this customer's credit limit against what is already out plus this order, treated as the company's
 * `credit_limit.behaviour` setting says (warn and allow, block, or do not check).
 *
 * **What "already out" means here — and the assumption behind it.** There is no receivables ledger or payment recording yet (accounting is
 * not in scope), so the system cannot know what a customer has actually paid. Instead it counts the customer's *other* orders that are waiting
 * for approval or approved, not cancelled, and whose order date is still inside the customer's **credit days** (ledger field): goods sold on
 * credit are treated as unpaid until their credit period is over. A customer with no credit days is counted for the orders of today only.
 * A customer whose ledger has no credit limit (or a limit of zero) has nothing to exceed. When payments are recorded (a later phase) only
 * `exposure()` changes.
 */
final class CreditCheck
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{result: CreditResult, limit: string|null, exposure: string}
     */
    public function evaluate(SalesOrder $order): array
    {
        $companyId = (int) $order->company_id;
        $behaviour = (string) $this->settings->get('credit_limit.behaviour', $companyId);
        $ledger = Ledger::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->find($order->customer_ledger_id);
        $limit = $ledger?->credit_limit;
        $limit = $limit === null || bccomp((string) $limit, '0', 2) <= 0 ? null : bcadd((string) $limit, '0', 2);
        $exposure = $this->exposure($order, (int) ($ledger->credit_days ?? 0));

        if ($behaviour === 'ignore') {
            return ['result' => CreditResult::Ignored, 'limit' => $limit, 'exposure' => $exposure];
        }

        if ($limit === null) {
            return ['result' => CreditResult::NoLimit, 'limit' => null, 'exposure' => $exposure];
        }

        if (bccomp(bcadd($exposure, (string) $order->grand_total, 2), $limit, 2) <= 0) {
            return ['result' => CreditResult::Ok, 'limit' => $limit, 'exposure' => $exposure];
        }

        $result = match (true) {
            $behaviour === 'block' && $order->credit_override_reason !== null => CreditResult::Overridden,
            $behaviour === 'block' => CreditResult::Blocked,
            default => CreditResult::Warn,
        };

        return ['result' => $result, 'limit' => $limit, 'exposure' => $exposure];
    }

    /** Work the answer out now and keep it on the order (what the approver will see). */
    public function refresh(SalesOrder $order): CreditResult
    {
        $answer = $this->evaluate($order);

        $order->forceFill([
            'credit_result' => $answer['result']->value, 'credit_limit' => $answer['limit'], 'credit_exposure' => $answer['exposure'], 'credit_checked_at' => now(),
        ])->save();

        return $answer['result'];
    }

    /** What the customer already has out on other orders (see the class note). */
    public function exposure(SalesOrder $order, int $creditDays): string
    {
        $since = now()->startOfDay()->subDays(max(0, $creditDays))->toDateString();

        $sum = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('company_id', $order->company_id)->where('customer_ledger_id', $order->customer_ledger_id)
            ->where('state', '<>', 'cancelled')->whereIn('approval_status', ['pending', 'approved'])
            ->when($order->exists, fn ($q) => $q->whereKeyNot($order->getKey()))
            ->where('order_date', '>=', $since)
            ->toBase()->selectRaw('coalesce(sum(grand_total), 0) as total')->value('total');

        return bcadd((string) $sum, '0', 2);
    }

    /**
     * What a customer has out on orders still inside their credit days, for a screen that shows the customer rather than an order
     * (Customer 360). The same sum as `exposure()` without an order to leave out.
     */
    public function exposureOf(Ledger $ledger): string
    {
        $since = now()->startOfDay()->subDays(max(0, (int) ($ledger->credit_days ?? 0)))->toDateString();

        $sum = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('company_id', $ledger->company_id)->where('customer_ledger_id', $ledger->getKey())
            ->where('state', '<>', 'cancelled')->whereIn('approval_status', ['pending', 'approved'])
            ->where('order_date', '>=', $since)
            ->toBase()->selectRaw('coalesce(sum(grand_total), 0) as total')->value('total');

        return bcadd((string) $sum, '0', 2);
    }

    /** In words, for a message: "limit ₹5,00,000.00; ₹4,20,000.00 already out; this order ₹1,00,000.00". */
    public function describe(SalesOrder $order): string
    {
        $answer = $this->evaluate($order);

        return 'limit '.Format::inr($answer['limit'] ?? '0').'; '.Format::inr($answer['exposure']).' already out; this order '.Format::inr($order->grand_total);
    }
}
