<?php

namespace App\Modules\Shared\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backs the database up and proves a backup can be restored.
 *
 *  - **backup()** streams `mysqldump` (one consistent snapshot: `--single-transaction`, with routines and triggers) into a gzip file under `storage/app/backups`, and keeps only the newest N.
 *    The password goes to the tool in its environment, never on a command line, and is never written to a log or a message.
 *  - **restoreCheck()** loads a backup into a scratch database next to the live one (`<name>_restorecheck`, dropped again whatever happens) and compares it with the live database table by
 *    table (RestoreComparison). A backup nobody has restored is a hope, not a backup.
 *
 * The tools are found through `formezy.backup.dump_binary` / `mysql_binary` (env BACKUP_MYSQLDUMP / BACKUP_MYSQL).
 */
final class BackupService
{
    /** @param  string|null  $directory  where backups are kept (default: storage/app/backups; a test gives its own) */
    public function __construct(private readonly ?string $directory = null) {}

    public function directory(): string
    {
        return $this->directory ?? storage_path('app/backups');
    }

    /** @return array{path: string, bytes: int} */
    public function backup(int $keep = 14): array
    {
        $this->ensureTool((string) config('formezy.backup.dump_binary'), 'mysqldump');
        $dir = $this->directory();
        is_dir($dir) || mkdir($dir, 0775, true);

        $db = $this->settings();
        $path = $dir.DIRECTORY_SEPARATOR.$db['database'].'-'.now()->format('Ymd-His').'.sql.gz';
        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            throw new RuntimeException('The backup file could not be created.');
        }

        $process = new Process([
            (string) config('formezy.backup.dump_binary'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], '--single-transaction', '--quick', '--routines', '--triggers',
            '--no-tablespaces', '--default-character-set=utf8mb4', '--hex-blob', $db['database'],
        ], null, ['MYSQL_PWD' => $db['password']], null, 3600);

        try {
            $process->run(function (string $type, string $chunk) use ($handle) {
                if ($type === Process::OUT) {
                    gzwrite($handle, $chunk);
                }
            });
        } finally {
            gzclose($handle);
        }

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException('The database dump failed: '.$this->safe($process->getErrorOutput(), $db['password']));
        }

        $this->prune($keep);

        return ['path' => $path, 'bytes' => (int) filesize($path)];
    }

    /** Remove all but the newest $keep backups; returns how many were removed. */
    public function prune(int $keep): int
    {
        $files = $this->files();
        $old = array_slice($files, max(1, $keep));

        foreach ($old as $file) {
            @unlink($file);
        }

        return count($old);
    }

    /** @return list<string> backup files, newest first */
    public function files(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    public function latest(): ?string
    {
        return $this->files()[0] ?? null;
    }

    /**
     * Restore the file into a scratch database and compare it with the live one. The scratch database is always removed.
     *
     * @return array{tables: array<string, array{live: int, restored: int|null, status: string, note: string}>, failures: list<string>, warnings: list<string>}
     */
    public function restoreCheck(string $file): array
    {
        $this->ensureTool((string) config('formezy.backup.mysql_binary'), 'mysql');

        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('That backup file was not found.');
        }

        $db = $this->settings();
        $scratch = $db['database'].'_restorecheck';

        if (preg_match('/^[A-Za-z0-9_]{1,60}$/', $scratch) !== 1) {
            throw new RuntimeException('The database name cannot be used for a scratch copy.');
        }

        DB::statement("drop database if exists `{$scratch}`");
        DB::statement("create database `{$scratch}` character set utf8mb4 collate utf8mb4_unicode_ci");

        try {
            $input = fopen('compress.zlib://'.$file, 'rb');

            if ($input === false) {
                throw new RuntimeException('That backup file could not be read.');
            }

            $process = new Process([(string) config('formezy.backup.mysql_binary'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], '--default-character-set=utf8mb4', $scratch],
                null, ['MYSQL_PWD' => $db['password']], $input, 3600);
            $process->run();
            fclose($input);

            if (! $process->isSuccessful()) {
                throw new RuntimeException('The backup could not be loaded: '.$this->safe($process->getErrorOutput(), $db['password']));
            }

            return RestoreComparison::compare($this->tableFigures($db['database']), $this->tableFigures($scratch));
        } finally {
            DB::statement("drop database if exists `{$scratch}`");
        }
    }

    /** @return array<string, array{rows: int, columns: int}> */
    private function tableFigures(string $schema): array
    {
        $out = [];
        $columns = collect(DB::select('select table_name as t, count(*) as c from information_schema.columns where table_schema = ? group by table_name', [$schema]))->pluck('c', 't');

        foreach (DB::select("select table_name as t from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'", [$schema]) as $row) {
            $table = (string) $row->t;

            if (str_contains($table, '`')) {
                continue;
            }

            $out[$table] = ['rows' => (int) DB::selectOne("select count(*) as n from `{$schema}`.`{$table}`")->n, 'columns' => (int) ($columns[$table] ?? 0)];
        }

        return $out;
    }

    /** @return array{host: string, port: string, username: string, password: string, database: string} */
    private function settings(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($c['host'] ?? '127.0.0.1'), 'port' => (string) ($c['port'] ?? '3306'), 'username' => (string) ($c['username'] ?? ''),
            'password' => (string) ($c['password'] ?? ''), 'database' => (string) ($c['database'] ?? ''),
        ];
    }

    private function ensureTool(string $binary, string $name): void
    {
        $found = $binary !== '' && (is_file($binary) || (new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $binary] : ['which', $binary]))->run() === 0);

        if (! $found) {
            throw new RuntimeException("The {$name} program was not found. Set BACKUP_".strtoupper($name).' in .env to its full path.');
        }
    }

    /** What a tool said, without the password and without very long output. */
    private function safe(string $text, string $password): string
    {
        $text = $password === '' ? $text : str_replace($password, '[password]', $text);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 300);
    }
}
