<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\BackupCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a `db:backup` file (.sql.gz.enc) back into a plain .sql.gz for
 * restoring — full procedure in README "Backup & Restore".
 */
class DatabaseBackupDecrypt extends Command
{
    protected $signature = 'db:backup-decrypt
        {file : File backup .sql.gz.enc — path lokal, atau path di disk backup (BACKUP_DISK)}
        {--output= : Path file .sql.gz hasil (default: storage/app/restore/<nama file tanpa .enc>)}';

    protected $description = 'Dekripsi file backup database (AES-256-GCM) menjadi .sql.gz untuk restore';

    public function handle(): int
    {
        $downloaded = null;
        $partial = null;

        try {
            $key = (string) config('backup.encryption_key');

            if ($key === '') {
                throw new RuntimeException('BACKUP_ENCRYPTION_KEY kosong — kunci yang dipakai saat backup dibuat diperlukan untuk dekripsi.');
            }

            $cipher = BackupCipher::fromKey($key);
            $file = (string) $this->argument('file');
            $source = $this->resolveSource($file, $downloaded);

            if (! BackupCipher::isEncryptedFile($source)) {
                throw new RuntimeException('File ini tidak terenkripsi (bukan format .enc db:backup) — langsung restore dengan gunzip.');
            }

            $output = $this->option('output')
                ?: storage_path('app/restore/'.Str::beforeLast(basename($file), '.enc'));

            if (file_exists($output)) {
                throw new RuntimeException("File tujuan sudah ada: {$output} (hapus dulu atau pakai --output).");
            }

            File::ensureDirectoryExists(dirname($output), 0700);

            // Decrypt to a sibling temp file and rename only on success, so
            // a wrong key or a corrupt file never leaves a half-written dump.
            $partial = $output.'.partial';
            $cipher->decryptFile($source, $partial);

            if (! rename($partial, $output)) {
                throw new RuntimeException("Tidak bisa menulis {$output}.");
            }

            $partial = null;
        } catch (Throwable $e) {
            $this->components->error('Dekripsi backup GAGAL: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            foreach ([$partial, $downloaded] as $temp) {
                if ($temp !== null) {
                    @unlink($temp);
                }
            }
        }

        $this->components->info(sprintf(
            'Backup didekripsi: %s (%s)', $output, Number::fileSize((int) filesize($output), precision: 1),
        ));
        $this->line('  Restore: gunzip -c '.escapeshellarg($output).' | mysql -u <user> -p <database>');
        $this->line('  Hapus file hasil dekripsi setelah restore selesai (berisi seluruh data tanpa enkripsi).');

        return self::SUCCESS;
    }

    /**
     * A local path wins; otherwise the file is fetched from the backup disk
     * into a temp file (works for remote disks too).
     */
    private function resolveSource(string $file, ?string &$downloaded): string
    {
        if (is_file($file)) {
            return $file;
        }

        $diskName = (string) config('backup.disk');
        $disk = Storage::disk($diskName);

        if (! $disk->exists($file)) {
            throw new RuntimeException("File backup tidak ditemukan, baik sebagai path lokal maupun di disk [{$diskName}]: {$file}");
        }

        $downloaded = tempnam(sys_get_temp_dir(), 'dkbk') ?: throw new RuntimeException('Tidak bisa membuat file sementara.');
        $in = $disk->readStream($file);
        $out = fopen($downloaded, 'wb');

        try {
            if (! is_resource($in) || $out === false || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException("Gagal mengambil {$file} dari disk [{$diskName}].");
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }

            if (is_resource($out)) {
                fclose($out);
            }
        }

        return $downloaded;
    }
}
