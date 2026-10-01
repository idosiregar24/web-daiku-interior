<?php

use App\Console\Commands\Support\BackupCipher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * `php artisan db:backup` (PRD §3.3, §9.5, §11.4). mysqldump never really
 * runs here: Process::fake() stands in for it and writes the dump into the
 * --result-file the command asked for.
 */

const DB_BACKUP_TEST_SQL = "-- MySQL dump 10.13  Distrib 8.4.3\nCREATE TABLE `users` (`id` bigint unsigned NOT NULL);\nINSERT INTO `users` VALUES (1);\n-- Dump completed on 2026-09-30  0:00:01\n";

const DB_BACKUP_TEST_PASSWORD = 's3cr3t "pa$$#word';

function dbBackupTestKey(): string
{
    return base64_encode(str_repeat('k', 32));
}

/**
 * Fakes `mysqldump --version` and the dump itself; returns an object that
 * records the dump's argv and the credentials file it was given.
 *
 * @param  array{sql?: string, version?: string, exit?: int, stderr?: string}  $options
 */
function fakeMysqldump(array $options = []): object
{
    $state = (object) ['args' => null, 'defaultsPath' => null, 'defaults' => null];

    Process::fake([
        '*--version*' => Process::result(
            $options['version'] ?? 'mysqldump  Ver 8.4.3 for Linux on x86_64 (MySQL Community Server - GPL)'
        ),
        '*' => function (PendingProcess $process) use ($state, $options) {
            $state->args = $process->command;
            $state->defaultsPath = Str::after($process->command[1], '--defaults-extra-file=');
            $state->defaults = file_get_contents($state->defaultsPath);

            $resultFile = Str::after(
                collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file=')),
                '--result-file=',
            );
            file_put_contents($resultFile, $options['sql'] ?? DB_BACKUP_TEST_SQL);

            return Process::result(
                errorOutput: $options['stderr'] ?? '',
                exitCode: $options['exit'] ?? 0,
            );
        },
    ]);

    Process::preventStrayProcesses();

    return $state;
}

function decryptBackupContents(string $encrypted, string $key): string
{
    $source = tempnam(sys_get_temp_dir(), 'dkbt');
    $target = tempnam(sys_get_temp_dir(), 'dkbt');

    try {
        file_put_contents($source, $encrypted);
        BackupCipher::fromKey($key)->decryptFile($source, $target);

        return gzdecode(file_get_contents($target));
    } finally {
        @unlink($source);
        @unlink($target);
    }
}

beforeEach(function () {
    Storage::fake('backups');

    config([
        'database.connections.backup_test' => [
            'driver' => 'mysql',
            'host' => 'mysql.internal',
            'port' => '3306',
            'database' => 'daiku_prod',
            'username' => 'daiku',
            'password' => DB_BACKUP_TEST_PASSWORD,
            'unix_socket' => '',
            'charset' => 'utf8mb4',
        ],
        'backup.connection' => 'backup_test',
        'backup.disk' => 'backups',
        'backup.directory' => '',
        'backup.retention_days' => 30,
        'backup.encryption_key' => dbBackupTestKey(),
        'backup.mysqldump.binary' => 'mysqldump',
    ]);
});

test('db:backup runs mysqldump with the consistency flags and keeps the password off the command line', function () {
    $dump = fakeMysqldump();

    $this->artisan('db:backup')->assertSuccessful();

    $args = $dump->args;

    expect($args[0])->toBe('mysqldump')
        ->and($args[1])->toStartWith('--defaults-extra-file=') // must be the first option
        ->and($args)->toContain(
            '--host=mysql.internal',
            '--port=3306',
            '--user=daiku',
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--hex-blob',
            '--set-gtid-purged=OFF',
        )
        ->and($args)->not->toContain('--skip-ssl-verify-server-cert')
        ->and(end($args))->toBe('daiku_prod')
        ->and(collect($args)->filter(fn ($arg) => str_contains($arg, 's3cr3t'))->all())->toBe([]);

    // The password travels through a temporary option file (escaped for
    // MySQL's option-file syntax) that is deleted afterwards.
    expect($dump->defaults)->toContain('[client]')
        ->and($dump->defaults)->toContain('password="s3cr3t \"pa$$#word"')
        ->and(file_exists($dump->defaultsPath))->toBeFalse();

    Process::assertRanTimes(fn (PendingProcess $process) => in_array('--single-transaction', (array) $process->command, true), 1);
});

test('db:backup stores an encrypted, timestamped file that decrypts back to the dump', function () {
    fakeMysqldump();

    $this->artisan('db:backup')->assertSuccessful();

    $files = Storage::disk('backups')->files();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('/^daiku_prod_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz\.enc$/')
        ->and($files[0])->toContain(now()->format('Y-m-d'));

    $encrypted = Storage::disk('backups')->get($files[0]);

    expect($encrypted)->toStartWith('DKBAK')
        ->and($encrypted)->not->toContain('CREATE TABLE')
        ->and(decryptBackupContents($encrypted, dbBackupTestKey()))->toBe(DB_BACKUP_TEST_SQL);
});

test('db:backup-decrypt restores a stored backup to .sql.gz and refuses a wrong key', function () {
    fakeMysqldump();
    $this->artisan('db:backup')->assertSuccessful();
    $stored = Storage::disk('backups')->files()[0];

    $dir = sys_get_temp_dir().'/dkbt-'.Str::random(8);
    $output = $dir.'/restore.sql.gz';

    try {
        // Wrong key: fails and leaves nothing (not even a partial file) behind.
        config(['backup.encryption_key' => base64_encode(str_repeat('x', 32))]);
        $this->artisan('db:backup-decrypt', ['file' => $stored, '--output' => $output])->assertFailed();
        expect(file_exists($output))->toBeFalse()
            ->and(file_exists($output.'.partial'))->toBeFalse();

        config(['backup.encryption_key' => dbBackupTestKey()]);
        $this->artisan('db:backup-decrypt', ['file' => $stored, '--output' => $output])->assertSuccessful();
        expect(gzdecode(file_get_contents($output)))->toBe(DB_BACKUP_TEST_SQL);
    } finally {
        @unlink($output);
        @rmdir($dir);
    }
});

test('db:backup detects a MariaDB client, skips its CA check and strips its sandbox line', function () {
    $dump = fakeMysqldump([
        'version' => 'mariadb-dump from 11.8.3-MariaDB, client 10.19 for debian-linux-gnu (x86_64)',
        'sql' => "/*M!999999\\- enable the sandbox mode */ \n".DB_BACKUP_TEST_SQL,
    ]);

    $this->artisan('db:backup')->assertSuccessful();

    expect($dump->args)->toContain('--skip-ssl-verify-server-cert')
        ->and($dump->args)->not->toContain('--set-gtid-purged=OFF');

    $stored = Storage::disk('backups')->get(Storage::disk('backups')->files()[0]);
    expect(decryptBackupContents($stored, dbBackupTestKey()))->toBe(DB_BACKUP_TEST_SQL);
});

test('db:backup connects through the unix socket when one is configured', function () {
    config(['database.connections.backup_test.unix_socket' => '/var/run/mysqld/mysqld.sock']);
    $dump = fakeMysqldump();

    $this->artisan('db:backup')->assertSuccessful();

    expect($dump->args)->toContain('--socket=/var/run/mysqld/mysqld.sock')
        ->and(collect($dump->args)->contains(fn ($arg) => str_starts_with($arg, '--host=')))->toBeFalse();
});

test('db:backup stores a plain gzip and warns when no encryption key is set', function () {
    config(['backup.encryption_key' => null]);
    Log::spy();
    fakeMysqldump();

    $this->artisan('db:backup')->assertSuccessful();

    $files = Storage::disk('backups')->files();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('/^daiku_prod_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/')
        ->and(gzdecode(Storage::disk('backups')->get($files[0])))->toBe(DB_BACKUP_TEST_SQL);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'UNENCRYPTED'))->once();
});

test('db:backup refuses a malformed encryption key before dumping anything', function () {
    config(['backup.encryption_key' => 'bukan-kunci-yang-valid']);
    fakeMysqldump();

    $this->artisan('db:backup')->assertFailed();

    Process::assertNothingRan();
    expect(Storage::disk('backups')->files())->toBe([]);
});

test('db:backup prunes only its own backups older than the retention window', function () {
    $disk = Storage::disk('backups');
    $name = fn (int $daysAgo, string $suffix = '.sql.gz.enc') => 'daiku_prod_'.now()->subDays($daysAgo)->format('Y-m-d_His').$suffix;

    $disk->put($old = $name(40), 'old');
    $disk->put($oldPlain = $name(31, '.sql.gz'), 'old plain');
    $disk->put($recent = $name(29), 'recent');
    $disk->put($yesterday = $name(1), 'yesterday');
    $disk->put($otherDatabase = 'daiku_staging_'.now()->subDays(90)->format('Y-m-d_His').'.sql.gz.enc', 'other db');
    $disk->put($unrelated = 'catatan.txt', 'not a backup');

    fakeMysqldump();
    $this->artisan('db:backup')->assertSuccessful();

    $files = $disk->files();

    expect($files)->not->toContain($old)
        ->and($files)->not->toContain($oldPlain)
        ->and($files)->toContain($recent, $yesterday, $otherDatabase, $unrelated)
        ->and($files)->toHaveCount(5); // 4 kept + today's
});

test('db:backup keeps everything when retention is disabled', function () {
    config(['backup.retention_days' => 0]);
    $old = 'daiku_prod_'.now()->subDays(400)->format('Y-m-d_His').'.sql.gz.enc';
    Storage::disk('backups')->put($old, 'old');

    fakeMysqldump();
    $this->artisan('db:backup')->assertSuccessful();

    expect(Storage::disk('backups')->files())->toContain($old)->toHaveCount(2);
});

test('db:backup fails loudly when mysqldump fails, and does not prune old backups', function () {
    Log::spy();
    $old = 'daiku_prod_'.now()->subDays(40)->format('Y-m-d_His').'.sql.gz.enc';
    Storage::disk('backups')->put($old, 'old');

    fakeMysqldump([
        'sql' => '',
        'exit' => 2,
        'stderr' => "mysqldump: Got error: 1045: Access denied for user 'daiku'@'10.0.0.5' (using password: YES)",
    ]);

    $this->artisan('db:backup')
        ->expectsOutputToContain('GAGAL')
        ->assertFailed();

    expect(Storage::disk('backups')->files())->toBe([$old]);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'exit code 2'))->once();
});

test('db:backup fails when mysqldump exits cleanly but the dump is empty or incomplete', function (string $sql) {
    fakeMysqldump(['sql' => $sql]);

    $this->artisan('db:backup')->assertFailed();

    expect(Storage::disk('backups')->files())->toBe([]);
})->with([
    'empty' => [''],
    'cut short' => ["-- MySQL dump 10.13\nCREATE TABLE `users` (`id` bigint);\nINSERT INTO `users` VALUES (1),"],
]);

test('db:backup only supports MySQL connections', function () {
    config(['backup.connection' => 'sqlite']);
    Log::spy();
    fakeMysqldump();

    $this->artisan('db:backup')
        ->expectsOutputToContain('GAGAL')
        ->assertFailed();

    Process::assertNothingRan();
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'hanya mendukung MySQL'))->once();
});
