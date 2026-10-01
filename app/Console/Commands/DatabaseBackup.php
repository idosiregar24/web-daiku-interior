<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\BackupCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * PRD §3.3 "mysqldump cron harian", §9.5 "Backup database terenkripsi,
 * disimpan di lokasi terpisah", §11.4 "setiap tengah malam, retensi 30
 * hari" — scheduled in routes/console.php, configured in config/backup.php.
 *
 * mysqldump → gzip → AES-256-GCM (BackupCipher) → BACKUP_DISK, then prune
 * backups older than BACKUP_RETENTION_DAYS. Any failure exits non-zero and
 * is logged; old backups are only pruned after a new one is stored.
 */
class DatabaseBackup extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Backup database MySQL (mysqldump + gzip + enkripsi AES-256-GCM) ke disk backup, lalu hapus backup lama';

    /** First line newer mariadb-dump versions emit; the MySQL client can't parse it. */
    private const MARIADB_SANDBOX_LINE = '/*M!999999\- enable the sandbox mode */';

    public function handle(): int
    {
        $tempFiles = [];

        try {
            $connection = $this->connectionConfig();
            $cipher = $this->cipher();
            $diskName = (string) config('backup.disk');
            $disk = Storage::disk($diskName);

            $filename = sprintf(
                '%s_%s.sql.gz%s',
                $connection['database'],
                now()->format('Y-m-d_His'),
                $cipher ? '.enc' : '',
            );

            $sqlFile = $tempFiles[] = $this->tempFile();
            $gzFile = $tempFiles[] = $this->tempFile();

            $this->dump($connection, $sqlFile, $tempFiles);
            $this->compress($sqlFile, $gzFile);

            $upload = $gzFile;

            if ($cipher) {
                $upload = $tempFiles[] = $this->tempFile();
                $cipher->encryptFile($gzFile, $upload);
            }

            $path = $this->store($disk, $upload, $filename);
            $size = filesize($upload);
        } catch (Throwable $e) {
            return $this->reportFailure('Database backup failed', $e);
        } finally {
            foreach ($tempFiles as $file) {
                @unlink($file);
            }
        }

        Log::info('Database backup stored', [
            'disk' => $diskName,
            'path' => $path,
            'bytes' => $size,
            'encrypted' => $cipher !== null,
        ]);
        $this->components->info(sprintf(
            'Backup tersimpan di disk [%s]: %s (%s)',
            $diskName, $path, Number::fileSize($size, precision: 1),
        ));

        try {
            $deleted = $this->prune($disk, $connection['database']);
        } catch (Throwable $e) {
            return $this->reportFailure('Database backup stored, but pruning old backups failed', $e);
        }

        if ($deleted !== []) {
            Log::info('Old database backups pruned', ['disk' => $diskName, 'deleted' => $deleted]);
            $this->components->info(sprintf(
                '%d backup lebih lama dari %d hari dihapus.',
                count($deleted), (int) config('backup.retention_days'),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, host: string, port: string, socket: string, database: string, username: string, password: string, charset: string}
     */
    private function connectionConfig(): array
    {
        $name = config('backup.connection') ?: config('database.default');
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            throw new RuntimeException("Koneksi database [{$name}] tidak ditemukan.");
        }

        // Same DB_URL handling as Laravel's DatabaseManager.
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(sprintf(
                'db:backup hanya mendukung MySQL/MariaDB; koneksi [%s] memakai driver [%s].',
                $name, $config['driver'] ?? '?',
            ));
        }

        if (blank($config['database'] ?? null)) {
            throw new RuntimeException("Nama database untuk koneksi [{$name}] kosong.");
        }

        // Read/write split: dump from the primary.
        $host = $config['write']['host'] ?? $config['host'] ?? '127.0.0.1';

        return [
            'name' => (string) $name,
            'host' => (string) Arr::first(Arr::wrap($host)),
            'port' => (string) ($config['write']['port'] ?? $config['port'] ?? '3306'),
            'socket' => (string) ($config['unix_socket'] ?? ''),
            'database' => (string) $config['database'],
            'username' => (string) ($config['write']['username'] ?? $config['username'] ?? ''),
            'password' => (string) ($config['write']['password'] ?? $config['password'] ?? ''),
            'charset' => (string) ($config['charset'] ?? 'utf8mb4'),
        ];
    }

    private function cipher(): ?BackupCipher
    {
        $key = (string) config('backup.encryption_key');

        if ($key === '') {
            Log::warning('BACKUP_ENCRYPTION_KEY is empty — database backup is stored UNENCRYPTED.');
            $this->components->warn('BACKUP_ENCRYPTION_KEY kosong — backup disimpan TANPA enkripsi.');

            return null;
        }

        return BackupCipher::fromKey($key);
    }

    /**
     * @param  array<string, string>  $connection
     * @param  list<string>  $tempFiles
     */
    private function dump(array $connection, string $sqlFile, array &$tempFiles): void
    {
        $binary = (string) config('backup.mysqldump.binary');

        // The password goes into a 0600 option file, never argv (visible in
        // `ps`) — and --defaults-extra-file must be the very first option.
        $defaultsFile = $tempFiles[] = $this->tempFile();
        $this->writeDefaultsFile($defaultsFile, $connection['password']);

        $command = [
            $binary,
            '--defaults-extra-file='.$defaultsFile,
            ...($connection['socket'] !== ''
                ? ['--socket='.$connection['socket']]
                : ['--host='.$connection['host'], '--port='.$connection['port']]),
            '--user='.$connection['username'],
            '--default-character-set='.$connection['charset'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--hex-blob',
            ...$this->flavorOptions($binary),
            '--result-file='.$sqlFile,
            $connection['database'],
        ];

        $result = Process::timeout((int) config('backup.mysqldump.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException(sprintf(
                'mysqldump gagal (exit code %s): %s',
                $result->exitCode() ?? '?',
                Str::limit(trim($result->errorOutput() ?: $result->output()), 1000) ?: 'tanpa pesan error',
            ));
        }

        clearstatcache(true, $sqlFile);

        if (! is_file($sqlFile) || filesize($sqlFile) === 0) {
            throw new RuntimeException('mysqldump selesai tanpa error tetapi hasil dump kosong.');
        }

        // Both MySQL's and MariaDB's clients end a complete dump with this
        // comment; its absence means the output was cut short.
        if (! str_contains($this->tail($sqlFile, 512), '-- Dump completed')) {
            throw new RuntimeException('Hasil dump tidak lengkap (penanda "-- Dump completed" tidak ditemukan).');
        }
    }

    /**
     * Debian/Ubuntu images (incl. the php:8.4-fpm Docker image) ship
     * MariaDB's client, not Oracle's. Each needs different flags against a
     * MySQL 8 server, so detect which one BACKUP_MYSQLDUMP_PATH points to.
     *
     * @return list<string>
     */
    private function flavorOptions(string $binary): array
    {
        $version = Process::timeout(30)->run([$binary, '--version']);

        if ($version->failed()) {
            throw new RuntimeException(sprintf(
                'mysqldump tidak bisa dijalankan (%s) — cek BACKUP_MYSQLDUMP_PATH. %s',
                $binary, Str::limit(trim($version->errorOutput()), 300),
            ));
        }

        if (Str::contains($version->output(), 'mariadb', ignoreCase: true)) {
            // MariaDB ≥ 11.4 clients verify the server certificate by
            // default; MySQL 8's auto-generated one is self-signed. The link
            // stays TLS-encrypted, only the CA check is skipped (no-op on
            // older clients). Its extra "sandbox mode" first line is
            // stripped in compress().
            return ['--skip-ssl-verify-server-cert'];
        }

        // Oracle's client: no GTID_PURGED statement (restores into any
        // server, and no FLUSH/RELOAD privilege needed).
        return ['--set-gtid-purged=OFF'];
    }

    private function writeDefaultsFile(string $path, string $password): void
    {
        $contents = "[client]\n";

        if ($password !== '') {
            $contents .= 'password="'.addcslashes($password, "\\\"\n\r\t")."\"\n";
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Tidak bisa menulis file kredensial sementara untuk mysqldump.');
        }

        @chmod($path, 0600);
    }

    /** gzip the dump, dropping MariaDB's sandbox line so `mysql` can restore it. */
    private function compress(string $sqlFile, string $gzFile): void
    {
        $in = fopen($sqlFile, 'rb');
        $out = gzopen($gzFile, 'wb6');

        if ($in === false || $out === false) {
            throw new RuntimeException('Tidak bisa membuka file sementara untuk kompresi.');
        }

        try {
            $first = fgets($in, 8192);

            if ($first !== false && ! str_starts_with($first, self::MARIADB_SANDBOX_LINE)) {
                $this->gzWrite($out, $first);
            }

            while (! feof($in)) {
                $buffer = fread($in, 1048576);

                if ($buffer === false) {
                    throw new RuntimeException('Gagal membaca hasil dump.');
                }

                $this->gzWrite($out, $buffer);
            }
        } finally {
            fclose($in);

            if (! gzclose($out)) {
                throw new RuntimeException('Kompresi gzip gagal (disk penuh?).');
            }
        }
    }

    /** @param resource $gz */
    private function gzWrite($gz, string $data): void
    {
        if ($data !== '' && gzwrite($gz, $data) !== strlen($data)) {
            throw new RuntimeException('Kompresi gzip gagal (disk penuh?).');
        }
    }

    private function store(Filesystem $disk, string $file, string $filename): string
    {
        $directory = (string) config('backup.directory');
        $path = ltrim($directory.'/'.$filename, '/');
        $stream = fopen($file, 'rb');

        try {
            $written = $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false || $disk->size($path) !== filesize($file)) {
            throw new RuntimeException('Backup gagal disimpan utuh ke disk ['.config('backup.disk')."]: {$path}");
        }

        return $path;
    }

    /**
     * Deletes this database's backups older than the retention window.
     * Only files matching our own naming scheme are considered.
     *
     * @return list<string>
     */
    private function prune(Filesystem $disk, string $database): array
    {
        $retention = (int) config('backup.retention_days');

        if ($retention <= 0) {
            return [];
        }

        $cutoff = now()->subDays($retention);
        $pattern = '/^'.preg_quote($database, '/').'_(\d{4}-\d{2}-\d{2}_\d{6})\.sql\.gz(?:\.enc)?$/';
        $deleted = [];

        foreach ($disk->files((string) config('backup.directory')) as $file) {
            if (! preg_match($pattern, basename($file), $match)) {
                continue;
            }

            try {
                $takenAt = Carbon::createFromFormat('Y-m-d_His', $match[1]);
            } catch (Throwable) {
                continue;
            }

            if ($takenAt instanceof Carbon && $takenAt->lt($cutoff)) {
                $disk->delete($file);
                $deleted[] = $file;
            }
        }

        return $deleted;
    }

    private function tail(string $file, int $bytes): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            fseek($handle, -min($bytes, (int) filesize($file)), SEEK_END);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dkbk');

        if ($path === false) {
            throw new RuntimeException('Tidak bisa membuat file sementara di '.sys_get_temp_dir());
        }

        return $path;
    }

    private function reportFailure(string $message, Throwable $e): int
    {
        Log::error($message.': '.$e->getMessage(), ['exception' => $e]);
        $this->components->error('Backup database GAGAL: '.$e->getMessage());

        return self::FAILURE;
    }
}
