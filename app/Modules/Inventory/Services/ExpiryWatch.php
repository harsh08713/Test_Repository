<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Notifications\StockExpiryNotification;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Support\Format;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the scheduler does about expiry, every day for every company:
 *
 *  - **Expire**: a batch past its expiry date that still has stock is marked *expired* — from then on it cannot be issued or moved, only scrapped.
 *  - **Warn**: a batch with stock that expires within `inventory.expiry_warning_days` (30 by default; 0 = off) tells the people who hold
 *    `stock.view`, once. The warning is *claimed* with one conditional UPDATE (`WHERE expiry_alerted_at IS NULL`) before anyone is told, so
 *    overlapping or repeated runs never send it twice.
 *
 * A batch with nothing left is ignored. (Raising a loss incident from an expired batch arrives with the loss module.)
 */
final class ExpiryWatch
{
    private const MAX_DAYS = 365;

    public function __construct(private readonly SettingsService $settings, private readonly TenantContext $tenant) {}

    /** @return array{expired: int, warned: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $expired = 0;
        $warned = 0;

        $companies = Batch::withoutGlobalScopes(Tenancy::SCOPES)->whereNotNull('expiry_date')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $this->tenant->withCompany((int) $companyId, function () use ($now, $companyId, &$expired, &$warned) {
                $expired += $this->expire($now, (int) $companyId);
                $warned += $this->warn($now, (int) $companyId);
            });
        }

        return ['expired' => $expired, 'warned' => $warned];
    }

    private function withStock(int $companyId): \Closure
    {
        return fn ($q) => $q->select('batch_id')->from('stock_balances')->where('company_id', $companyId)->whereNotNull('batch_id')->where('quantity', '>', 0);
    }

    private function expire(Carbon $now, int $companyId): int
    {
        return Batch::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')
            ->where('expiry_date', '<', $now->toDateString())->whereIn('id', $this->withStock($companyId))
            ->update(['status' => 'expired']);
    }

    private function warn(Carbon $now, int $companyId): int
    {
        $days = min(self::MAX_DAYS, max(0, (int) $this->settings->get('inventory.expiry_warning_days', $companyId)));

        if ($days === 0) {
            return 0;
        }

        $sent = 0;
        $due = Batch::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')->whereNull('expiry_alerted_at')
            ->whereBetween('expiry_date', [$now->toDateString(), $now->copy()->addDays($days)->toDateString()])
            ->whereIn('id', $this->withStock($companyId))->with('item:id,name')->orderBy('expiry_date')->limit(500)->get();

        foreach ($due as $batch) {
            $claimed = Batch::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($batch->getKey())->whereNull('expiry_alerted_at')->update(['expiry_alerted_at' => $now]);

            if ($claimed === 0) {
                continue;   // another run has it
            }

            $stock = DB::table('stock_balances')->where('batch_id', $batch->getKey())->where('quantity', '>', 0)->selectRaw('sum(quantity) as quantity, sum(value) as value')->first();
            $note = new StockExpiryNotification(
                (string) ($batch->item->name ?? 'An item'), $batch->batch_no, $batch->expiry_date?->format('d-m-Y') ?? '', rtrim(rtrim((string) ($stock->quantity ?? '0'), '0'), '.') ?: '0',
                '₹ '.Format::number((string) ($stock->value ?? '0'), 2), route('inventory.batches'),
            );

            foreach ($this->audience($companyId) as $user) {
                $user->notify($note);
            }

            $sent++;
        }

        return $sent;
    }

    /** @return Collection<int, User> */
    private function audience(int $companyId)
    {
        return User::query()->permission('stock.view')->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))->get();
    }
}
