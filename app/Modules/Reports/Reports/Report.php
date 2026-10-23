<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn;
use App\Modules\Reports\Support\ReportInput;
use Generator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use LogicException;
use stdClass;

/**
 * One report. A report knows its columns, which filters it understands and how to turn them into a query that is **already limited to
 * what the signed-in person may see** — it restricts through the same access classes the screens use (`LeadAccess::restrict()` and so
 * on), never by a role name, and the company and branch boundaries come from the models' global scopes. The page, the Excel/PDF export
 * and the queue worker all call `query()`, so a number cannot differ between what is on the screen and what is downloaded.
 *
 * A query must order by something unique as its last key: an export reads it page by page.
 *
 * Some figures are worked out by a service whose formula must not be written a second time (budget against actual, profitability, the loss Pareto). Such a report
 * overrides `collection()` instead of `query()`: it hands back every row, already restricted by the service, and the page, the export and the count all read those
 * same rows. A collection report holds its rows in memory, so it is for figures grouped to a few hundred lines, never for a register.
 */
abstract class Report
{
    /** Rows read per round trip when exporting. */
    public const CHUNK = 1000;

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** The heading it is listed under: Leads, Pipeline, Sales, Field sales, Masters, Control. */
    abstract public function group(): string;

    /** The permission to see the underlying records (a report never shows more than its source screen does). */
    abstract public function permission(): string;

    /** @return list<ReportColumn> */
    abstract public function columns(): array;

    /**
     * The rows as one query, already restricted to what the person may see. A report whose figures come from a service overrides `collection()` instead.
     *
     * @return EloquentBuilder<*>|QueryBuilder
     */
    public function query(ReportInput $input, User $user): EloquentBuilder|QueryBuilder
    {
        throw new LogicException('Report '.$this->key().' is built from a collection, not a query.');
    }

    /**
     * Every row, for a report whose figures come from a service (see above); null for a query report.
     *
     * @return Collection<int, stdClass>|null
     */
    public function collection(ReportInput $input, User $user): ?Collection
    {
        return null;
    }

    /**
     * The filters it understands, from: date, branch, person, team, source, choice.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return ['date', 'branch', 'person'];
    }

    public function dateLabel(): string
    {
        return 'Date';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    /** @return array<int|string, string> value => label */
    public function choices(): array
    {
        return [];
    }

    /** What the choice starts on when the page opens ('' = all). */
    public function defaultChoice(): string
    {
        return '';
    }

    /** Does a new run start with this month's dates filled in? Registers do; "everything open now" reports do not. */
    public function startsOnThisMonth(): bool
    {
        return true;
    }

    /** A sentence printed above the figures, e.g. what is a forecast and what is an actual. */
    public function note(): ?string
    {
        return null;
    }

    /**
     * Headline figures for the same filters, shown above the table.
     *
     * @return array<string, string> label => value (already formatted)
     */
    public function summary(ReportInput $input, User $user): array
    {
        return [];
    }

    /**
     * One row as plain values keyed by column.
     *
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $out = [];

        foreach ($this->columns() as $column) {
            $out[$column->key] = data_get($row, $column->key);
        }

        return $out;
    }

    public function canRun(User $user): bool
    {
        return $user->can('report.view') && $user->can($this->permission());
    }

    public function canExport(User $user): bool
    {
        return $this->canRun($user) && $user->can('report.export');
    }

    /** @return LengthAwarePaginator<int, mixed> */
    public function page(ReportInput $input, User $user, int $perPage = 25): LengthAwarePaginator
    {
        $all = $this->collection($input, $user);

        if ($all === null) {
            return $this->query($input, $user)->paginate($perPage);
        }

        $number = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator($all->forPage($number, $perPage)->values(), $all->count(), $perPage, $number, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }

    /**
     * Every row, a page at a time, so a large report never sits in memory whole.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(ReportInput $input, User $user, int $limit = PHP_INT_MAX): Generator
    {
        $all = $this->collection($input, $user);

        if ($all !== null) {
            foreach ($all->take($limit) as $row) {
                yield $this->present($row);
            }

            return;
        }

        $query = $this->query($input, $user);
        $page = 1;
        $sent = 0;

        do {
            $chunk = (clone $query)->forPage($page, self::CHUNK)->get();

            foreach ($chunk as $row) {
                if ($sent++ >= $limit) {
                    return;
                }

                yield $this->present($row);
            }

            $page++;
        } while ($chunk->count() === self::CHUNK);
    }

    /**
     * A row of a collection report: the service's array as a plain object (`present()` reads columns by name).
     *
     * @param  array<string, mixed>  $values
     */
    protected function asRow(array $values): stdClass
    {
        $row = new stdClass;

        foreach ($values as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }

    /** Count without reading a row (a large report says how many it has before it is exported). */
    public function count(ReportInput $input, User $user): int
    {
        $all = $this->collection($input, $user);

        if ($all !== null) {
            return $all->count();
        }

        $query = $this->query($input, $user);

        return ($query instanceof EloquentBuilder ? $query->toBase() : $query)->getCountForPagination();
    }

    /**
     * One row of totals over the same query, e.g. `count(*) as total, sum(x) as value` — the query's own columns, grouping and order are dropped.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    protected function totals(EloquentBuilder|QueryBuilder $query, string $expression): object
    {
        $base = clone ($query instanceof EloquentBuilder ? $query->toBase() : $query);
        $base->columns = null;
        $base->groups = null;

        return $base->reorder()->select(new Expression($expression))->first() ?? (object) [];
    }

    /**
     * A filter on a date column, both ends inclusive.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    protected function between(EloquentBuilder|QueryBuilder $query, string $column, ReportInput $input, bool $isDateTime = false): void
    {
        if ($input->from !== null) {
            $query->where($column, '>=', $input->from.($isDateTime ? ' 00:00:00' : ''));
        }

        if ($input->to !== null) {
            $isDateTime ? $query->where($column, '<=', $input->to.' 23:59:59') : $query->where($column, '<=', $input->to);
        }
    }
}
