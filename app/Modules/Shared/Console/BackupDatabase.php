<?php

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/** Back the database up to storage/app/backups (gzip), keeping the newest few. With --verify, restore it into a scratch database straight away and compare. */
class BackupDatabase extends Command
{
    protected $signature = 'formezy:backup {--verify : Restore the new backup into a scratch database and compare it with the live one} {--keep= : How many backups to keep (default: formezy.backup.keep)}';

    protected $description = 'Back the database up (and optionally prove the backup restores)';

    public function handle(BackupService $backups): int
    {
        try {
            $made = $backups->backup((int) ($this->option('keep') ?: config('formezy.backup.keep', 14)));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Backup written: '.basename($made['path']).' ('.number_format($made['bytes'] / 1024, 1).' KB)');

        return $this->option('verify') ? $this->call('formezy:restore-check', ['--file' => $made['path']]) : self::SUCCESS;
    }
}
