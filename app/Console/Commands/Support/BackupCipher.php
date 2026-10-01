<?php

namespace App\Console\Commands\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Streaming AES-256-GCM for database backup files (PRD §9.5 "Backup
 * database terenkripsi"), used by `db:backup` and `db:backup-decrypt`.
 *
 * PHP's openssl extension has no incremental GCM API, and a whole dump
 * does not fit the CLI memory_limit, so the file is sealed in chunks:
 *
 *   header  = "DKBAK" | version (1 byte) | salt (16) | chunk size (uint32 BE)
 *   chunk_i = ciphertext length (uint32 BE) | ciphertext | GCM tag (16)
 *
 * The per-file key is HKDF-SHA256(master key, salt), so the 96-bit nonce
 * can simply be the chunk counter (unique per key). The AAD is the header
 * plus a "last chunk" flag: editing the header, reordering, dropping,
 * truncating or appending chunks all fail authentication on decrypt.
 */
final class BackupCipher
{
    public const DEFAULT_CHUNK_SIZE = 1048576; // 1 MiB of plaintext per chunk

    private const MAGIC = 'DKBAK';

    private const VERSION = 1;

    private const SALT_BYTES = 16;

    private const TAG_BYTES = 16;

    private const HEADER_BYTES = 5 + 1 + self::SALT_BYTES + 4;

    private const MAX_CHUNK_SIZE = 67108864; // 64 MiB — rejects corrupt headers

    private const CIPHER = 'aes-256-gcm';

    private const HKDF_INFO = 'daiku-db-backup-v1';

    private function __construct(
        private readonly string $masterKey,
        private readonly int $chunkSize,
    ) {}

    /**
     * @param  string  $key  base64 of 32 bytes, optionally prefixed "base64:"
     */
    public static function fromKey(string $key, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        $key = trim($key);
        $raw = base64_decode(str_starts_with($key, 'base64:') ? substr($key, 7) : $key, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new InvalidArgumentException(
                'BACKUP_ENCRYPTION_KEY harus base64 dari tepat 32 byte (buat dengan: openssl rand -base64 32).'
            );
        }

        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException('Ukuran chunk enkripsi tidak valid.');
        }

        return new self($raw, $chunkSize);
    }

    /** True when the file starts with this format's magic bytes. */
    public static function isEncryptedFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
    }

    public function encryptFile(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $header = self::MAGIC.chr(self::VERSION).$salt.pack('N', $this->chunkSize);
            $key = $this->deriveKey($salt);

            $this->write($out, $header);

            // Read one chunk ahead so the last chunk can be flagged as final.
            // An empty input still yields one (empty) final chunk.
            $index = 0;
            $chunk = $this->read($in, $this->chunkSize);

            do {
                $next = $this->read($in, $this->chunkSize);
                $final = $next === '';

                $tag = '';
                $ciphertext = openssl_encrypt(
                    $chunk, self::CIPHER, $key, OPENSSL_RAW_DATA,
                    $this->nonce($index), $tag, $this->aad($header, $final), self::TAG_BYTES,
                );

                if ($ciphertext === false) {
                    throw new RuntimeException('Enkripsi backup gagal (openssl).');
                }

                $this->write($out, pack('N', strlen($ciphertext)).$ciphertext.$tag);

                $chunk = $next;
                $index++;
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Writes the plaintext to $destination chunk by chunk, each one only
     * after its tag verified. On failure the caller must discard the
     * (partial) destination file.
     */
    public function decryptFile(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $header = $this->read($in, self::HEADER_BYTES);

            if (strlen($header) !== self::HEADER_BYTES || ! str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('File ini bukan backup terenkripsi Daiku (header tidak dikenali).');
            }

            if (ord($header[5]) !== self::VERSION) {
                throw new RuntimeException('Versi format backup tidak didukung: '.ord($header[5]).'.');
            }

            $salt = substr($header, 6, self::SALT_BYTES);
            $chunkSize = unpack('N', substr($header, 6 + self::SALT_BYTES, 4))[1];

            if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
                throw new RuntimeException('Header backup rusak (ukuran chunk tidak valid).');
            }

            $key = $this->deriveKey($salt);
            $lengthBytes = $this->read($in, 4);
            $index = 0;

            while (true) {
                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('File backup terpotong (chunk tidak lengkap).');
                }

                $length = unpack('N', $lengthBytes)[1];

                if ($length > $chunkSize) {
                    throw new RuntimeException('File backup rusak (panjang chunk tidak valid).');
                }

                $ciphertext = $this->read($in, $length);
                $tag = $this->read($in, self::TAG_BYTES);

                if (strlen($ciphertext) !== $length || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('File backup terpotong (chunk tidak lengkap).');
                }

                // Look ahead: end of file means this must be the final chunk.
                $lengthBytes = $this->read($in, 4);
                $final = $lengthBytes === '';

                $plaintext = openssl_decrypt(
                    $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA,
                    $this->nonce($index), $tag, $this->aad($header, $final),
                );

                if ($plaintext === false) {
                    throw new RuntimeException(
                        'Dekripsi gagal: kunci BACKUP_ENCRYPTION_KEY salah, atau file backup rusak/terpotong.'
                    );
                }

                $this->write($out, $plaintext);

                if ($final) {
                    return;
                }

                $index++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function deriveKey(string $salt): string
    {
        return hash_hkdf('sha256', $this->masterKey, 32, self::HKDF_INFO, $salt);
    }

    private function nonce(int $index): string
    {
        if ($index > 0xFFFFFFFF) {
            throw new RuntimeException('File backup terlalu besar untuk format ini.');
        }

        return str_repeat("\0", 8).pack('N', $index);
    }

    private function aad(string $header, bool $final): string
    {
        return $header.($final ? "\x01" : "\x00");
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Tidak bisa membuka file: {$path}");
        }

        return $handle;
    }

    /**
     * Reads up to $length bytes, looping over short reads; returns fewer
     * only at end of file.
     *
     * @param  resource  $handle
     */
    private function read($handle, int $length): string
    {
        $data = '';

        while ($length > 0 && strlen($data) < $length && ! feof($handle)) {
            $part = fread($handle, $length - strlen($data));

            if ($part === false) {
                throw new RuntimeException('Gagal membaca file backup.');
            }

            if ($part === '') {
                break;
            }

            $data .= $part;
        }

        return $data;
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if ($data !== '' && fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Gagal menulis file backup (disk penuh?).');
        }
    }
}
