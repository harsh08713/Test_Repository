<?php

namespace App\Modules\Shared\Services;

/**
 * Decides whether a restored copy of the database is a faithful one, by comparing it with the live database table by table. Pure: it is given the numbers and returns a verdict.
 *
 *  - **FAIL**: a live table is missing from the restore, a table has a different number of columns, or the restore has no rows where the live table has some.
 *  - **WARN**: the restore has fewer rows than live (the live database has grown since the backup: normal) or more (rows were removed since: worth a look).
 *  - **OK**: the same number of rows.
 *  - Tables that change by themselves (sessions, cache, jobs) are listed but never judged.
 */
final class RestoreComparison
{
    /** Tables whose rows come and go on their own. */
    public const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /**
     * @param  array<string, array{rows: int, columns: int}>  $live
     * @param  array<string, array{rows: int, columns: int}>  $restored
     * @return array{tables: array<string, array{live: int, restored: int|null, status: string, note: string}>, failures: list<string>, warnings: list<string>}
     */
    public static function compare(array $live, array $restored): array
    {
        $tables = [];
        $failures = [];
        $warnings = [];

        ksort($live);

        foreach ($live as $name => $now) {
            $then = $restored[$name] ?? null;

            if ($then === null) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => null, 'status' => 'FAIL', 'note' => 'missing from the restore'];
                $failures[] = "{$name}: missing from the restore";

                continue;
            }

            if ($then['columns'] !== $now['columns']) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => $then['rows'], 'status' => 'FAIL', 'note' => "{$then['columns']} columns restored, {$now['columns']} live"];
                $failures[] = "{$name}: {$then['columns']} columns restored but {$now['columns']} live";

                continue;
            }

            if (in_array($name, self::VOLATILE, true)) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => $then['rows'], 'status' => 'skipped', 'note' => 'changes by itself'];

                continue;
            }

            if ($then['rows'] === $now['rows']) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => $then['rows'], 'status' => 'OK', 'note' => ''];
            } elseif ($then['rows'] === 0 && $now['rows'] > 0) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => 0, 'status' => 'FAIL', 'note' => 'restored empty'];
                $failures[] = "{$name}: restored empty, but {$now['rows']} rows live";
            } elseif ($then['rows'] < $now['rows']) {
                $tables[$name] = ['live' => $now['rows'], 'restored' => $then['rows'], 'status' => 'WARN', 'note' => 'live has grown since the backup'];
                $warnings[] = "{$name}: {$then['rows']} restored, {$now['rows']} live (grown since)";
            } else {
                $tables[$name] = ['live' => $now['rows'], 'restored' => $then['rows'], 'status' => 'WARN', 'note' => 'rows were removed since the backup'];
                $warnings[] = "{$name}: {$then['rows']} restored, {$now['rows']} live (removed since)";
            }
        }

        return ['tables' => $tables, 'failures' => $failures, 'warnings' => $warnings];
    }
}
