<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Support\FinancialYear;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues gap-free document numbers such as QT/AHD/26-27/00001
 * (prefix / branch code / financial year / sequence), one counter per
 * company + branch + document type + financial year.
 *
 * The counter row is locked (SELECT ... FOR UPDATE) inside a transaction, so concurrent
 * requests can never receive the same number. Call next() inside the same transaction that
 * saves the document: if that transaction rolls back, the number is not consumed.
 */
final class NumberSeriesService
{
    /**
     * The counter is created and locked without a gap lock (see lockSeries), so issuing the first number of a series
     * does not deadlock, nested in a document's transaction or not. Should the database still pick this transaction
     * as a deadlock victim for some other reason, it is safe to retry — which Laravel can only do when this is the
     * outermost transaction; nested, the caller's transaction is already gone and the caller must retry.
     */
    private const DEADLOCK_ATTEMPTS = 5;

    public function __construct(private readonly TenantContext $tenant) {}

    public function next(DocumentType $type, Location|int $location, ?CarbonInterface $date = null): string
    {
        [$location, $company] = $this->resolve($location);
        $prefix = $this->prefixFor($type);
        $financialYear = FinancialYear::label($date ?? now(), $company->financial_year_start_month);

        return DB::transaction(function () use ($type, $location, $company, $prefix, $financialYear) {
            $series = $this->lockSeries($type, $location, $company, $prefix, $financialYear);

            $next = $series->last_number + 1;

            // Plain UPDATE: no model events, so the hot path does not write an audit row per number.
            DB::table('number_series')->where('id', $series->id)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

            return $series->format($next, $location->code);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /** What the next number would be, without consuming it (for forms/previews). */
    public function preview(DocumentType $type, Location|int $location, ?CarbonInterface $date = null): string
    {
        [$location, $company] = $this->resolve($location);
        $prefix = $this->prefixFor($type);
        $financialYear = FinancialYear::label($date ?? now(), $company->financial_year_start_month);

        $series = NumberSeries::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('location_id', $location->id)
            ->where('document_type', $type->value)
            ->where('financial_year', $financialYear)
            ->first();

        $series ??= new NumberSeries([
            'prefix' => $prefix,
            'padding' => 5,
            'financial_year' => $financialYear,
        ]);

        return $series->format(($series->last_number ?? 0) + 1, $location->code);
    }

    /** @return array{0: Location, 1: Company} */
    private function resolve(Location|int $location): array
    {
        // Tenancy scopes are lifted, soft-deletes are not: a deleted branch or company cannot issue numbers.
        $location = $location instanceof Location
            ? $location
            : Location::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($location);

        if (! $this->tenant->isUnrestricted() && ! $this->tenant->canAccessLocation($location->id)) {
            throw TenantAccessException::location($location->id);
        }

        return [$location, Company::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($location->company_id)];
    }

    private function prefixFor(DocumentType $type): string
    {
        return $type->prefix()
            ?? throw new InvalidArgumentException("{$type->label()} is not a numbered document type.");
    }

    private function lockSeries(DocumentType $type, Location $location, Company $company, string $prefix, string $financialYear): NumberSeries
    {
        $series = fn () => NumberSeries::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('location_id', $location->id)
            ->where('document_type', $type->value)
            ->where('financial_year', $financialYear);

        // The first number of a series (a new branch, a new financial year) is where requests collide, and a number is
        // normally issued inside the transaction that saves the document — where a deadlock cannot be retried here,
        // because the database has already thrown that whole transaction away. So the counter is created without ever
        // creating a cycle of waits. tests/Concurrency proves it with real processes; each step below is there because
        // leaving it out deadlocked:
        //
        //  1. A plain read first. A *locking* read that finds no row takes a gap lock, and two requests that each hold
        //     it then block one another's INSERT.
        if (! $series()->exists()) {
            //  2. Create the counter with INSERT … ON DUPLICATE KEY UPDATE. Whoever comes second waits here for an
            //     *exclusive* lock on the first one's row; a plain INSERT would ask for a shared one, and two requests
            //     holding it would deadlock on the way up to exclusive.
            $created = DB::affectingStatement(
                'insert into number_series (company_id, location_id, document_type, financial_year, prefix, padding, last_number, created_at, updated_at)
                 values (?, ?, ?, ?, ?, 5, 0, ?, ?) on duplicate key update id = id',
                [$company->id, $location->id, $type->value, $financialYear, $prefix, now(), now()],
            );

            //  3. Whoever created the row already holds its lock, and must not ask for it again: by now the others are
            //     queued on that row, a new request would join the queue behind them, and they are waiting for us.
            if ($created === 1) {
                return $series()->firstOrFail();
            }
        }

        // Everyone else locks the row. A locking read always sees the latest committed row, whatever this
        // transaction's snapshot shows, so the number carries on from the last one issued.
        return $series()->lockForUpdate()->firstOrFail();
    }
}
