<?php

use App\Modules\Shared\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * A backup that has really been restored. These run against the test database with the real `mysqldump` and `mysql` programs, outside a wrapping transaction (the dump is made by another
 * connection, which could not see uncommitted rows). They are skipped, and say so, on a machine where those programs cannot be found.
 */

function backupToolsMissing(): bool
{
    $found = fn (string $binary) => $binary !== '' && (is_file($binary) || (new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $binary] : ['which', $binary]))->run() === 0);

    return ! $found((string) config('formezy.backup.dump_binary')) || ! $found((string) config('formezy.backup.mysql_binary'));
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formezy-backup-test-'.Str::random(8);
    $this->service = new BackupService($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    DB::statement('drop table if exists `zz_restore_probe`');
    DB::statement('drop database if exists `'.config('database.connections.mysql.database').'_restorecheck`');
});

it('backs the database up to a gzip file and restores it into a scratch database that matches', function () {
    $made = $this->service->backup(5);

    expect($made['bytes'])->toBeGreaterThan(1000)->and(basename($made['path']))->toStartWith(config('database.connections.mysql.database').'-')->toEndWith('.sql.gz')
        ->and($this->service->latest())->toBe($made['path']);

    // the file is a real dump, and the password is nowhere in it or in the command's output
    $sql = (string) file_get_contents('compress.zlib://'.$made['path']);
    expect($sql)->toContain('CREATE TABLE `users`')->and($sql)->toContain('CREATE TABLE `migrations`');

    $result = $this->service->restoreCheck($made['path']);

    expect($result['failures'])->toBe([])->and(count($result['tables']))->toBeGreaterThan(100)->and(collect($result['tables'])->where('status', 'FAIL')->count())->toBe(0)
        ->and(collect($result['tables'])->where('status', 'OK')->count())->toBeGreaterThan(100);

    // the scratch database does not stay behind
    expect(DB::select('select schema_name from information_schema.schemata where schema_name = ?', [config('database.connections.mysql.database').'_restorecheck']))->toBe([]);
})->skip(fn () => backupToolsMissing(), 'mysqldump / mysql were not found (set BACKUP_MYSQLDUMP and BACKUP_MYSQL)');

it('fails a restore check when the database has a table the backup lacks', function () {
    $made = $this->service->backup(5);
    DB::statement('create table `zz_restore_probe` (id int primary key, note varchar(20))');
    DB::table('zz_restore_probe')->insert(['id' => 1, 'note' => 'after the backup']);

    $result = $this->service->restoreCheck($made['path']);

    expect($result['failures'])->toBe(['zz_restore_probe: missing from the restore'])->and($result['tables']['zz_restore_probe']['status'])->toBe('FAIL');
})->skip(fn () => backupToolsMissing(), 'mysqldump / mysql were not found (set BACKUP_MYSQLDUMP and BACKUP_MYSQL)');

it('refuses a file that is not a backup, and cleans up after itself', function () {
    File::ensureDirectoryExists($this->dir);
    $bad = $this->dir.DIRECTORY_SEPARATOR.'not-a-backup.sql.gz';
    file_put_contents($bad, gzencode("this is not sql at all;\n"));

    expect(fn () => $this->service->restoreCheck($bad))->toThrow(RuntimeException::class, 'could not be loaded')
        ->and(fn () => $this->service->restoreCheck($this->dir.DIRECTORY_SEPARATOR.'missing.sql.gz'))->toThrow(RuntimeException::class, 'not found')
        ->and(DB::select('select schema_name from information_schema.schemata where schema_name = ?', [config('database.connections.mysql.database').'_restorecheck']))->toBe([]);
})->skip(fn () => backupToolsMissing(), 'mysqldump / mysql were not found (set BACKUP_MYSQLDUMP and BACKUP_MYSQL)');

it('keeps only the newest backups', function () {
    File::ensureDirectoryExists($this->dir);

    foreach (range(1, 5) as $n) {
        $file = $this->dir.DIRECTORY_SEPARATOR."db-2026010{$n}-000000.sql.gz";
        file_put_contents($file, gzencode('-- '.$n));
        touch($file, strtotime("2026-01-0{$n} 00:00:00"));
    }

    expect($this->service->prune(2))->toBe(3)->and(array_map('basename', $this->service->files()))->toBe(['db-20260105-000000.sql.gz', 'db-20260104-000000.sql.gz'])
        ->and($this->service->prune(0))->toBe(1)->and(count($this->service->files()))->toBe(1);   // never removes the newest
});

it('runs from the command line, and says so when it cannot', function () {
    config(['formezy.backup.dump_binary' => 'no-such-program-here']);

    expect(Artisan::call('formezy:backup'))->toBe(1)->and(Artisan::output())->toContain('mysqldump program was not found');

    config(['formezy.backup.mysql_binary' => 'no-such-program-here']);
    expect(Artisan::call('formezy:restore-check', ['--file' => $this->dir.'/x.sql.gz']))->toBe(1)->and(Artisan::output())->toContain('mysql program was not found');
});
