<?php

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/** Prove a backup restores: load it into a scratch database, compare it with the live database table by table, drop the scratch copy. Exit code 1 if anything is wrong. */
class RestoreCheck extends Command
{
    protected $signature = 'formezy:restore-check {--file= : A backup file (default: the newest)}';

    protected $description = 'Restore a backup into a scratch database and compare it with the live one';

    public function handle(BackupService $backups): int
    {
        $file = (string) ($this->option('file') ?: $backups->latest());

        if ($file === '') {
            $this->components->error('There is no backup to check. Run formezy:backup first.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restoreCheck($file);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $counts = collect($result['tables'])->countBy('status');
        $this->line('Checked '.basename($file).': '.count($result['tables']).' tables — '.collect(['OK', 'WARN', 'FAIL', 'skipped'])->map(fn ($s) => ($counts[$s] ?? 0).' '.$s)->implode(', '));

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($result['failures'] as $failure) {
            $this->components->error($failure);
        }

        if ($result['failures'] !== []) {
            $this->components->error('RESTORE CHECK FAILED');

            return self::FAILURE;
        }

        $this->components->info('RESTORE CHECK PASSED');

        return self::SUCCESS;
    }
}
