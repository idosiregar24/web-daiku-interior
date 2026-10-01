<?php

use App\Console\Commands\Support\BackupCipher;

/*
 * Chunked AES-256-GCM used by db:backup / db:backup-decrypt. A tiny chunk
 * size makes every case below span many chunks.
 */

function cipherTestPaths(): array
{
    return [tempnam(sys_get_temp_dir(), 'dkct'), tempnam(sys_get_temp_dir(), 'dkct'), tempnam(sys_get_temp_dir(), 'dkct')];
}

beforeEach(function () {
    $this->key = base64_encode(random_bytes(32));
    $this->cipher = BackupCipher::fromKey($this->key, chunkSize: 16);
    [$this->plain, $this->sealed, $this->opened] = cipherTestPaths();
});

afterEach(function () {
    foreach ([$this->plain, $this->sealed, $this->opened] as $path) {
        @unlink($path);
    }
});

test('encrypts and decrypts across many chunks, including exact chunk multiples and empty input', function (string $data) {
    file_put_contents($this->plain, $data);

    $this->cipher->encryptFile($this->plain, $this->sealed);
    expect(BackupCipher::isEncryptedFile($this->sealed))->toBeTrue()
        ->and(BackupCipher::isEncryptedFile($this->plain))->toBeFalse();

    $this->cipher->decryptFile($this->sealed, $this->opened);
    expect(file_get_contents($this->opened))->toBe($data);
})->with([
    'multi-chunk' => [str_repeat('INSERT INTO `leads` VALUES (1);', 7)],
    'exact multiple of the chunk size' => [str_repeat('x', 64)],
    'empty' => [''],
    'binary' => [random_bytes(1000)],
]);

test('the same key decrypts regardless of chunk size, and the base64: prefix is accepted', function () {
    file_put_contents($this->plain, str_repeat('data', 100));
    $this->cipher->encryptFile($this->plain, $this->sealed);

    BackupCipher::fromKey('base64:'.$this->key)->decryptFile($this->sealed, $this->opened);

    expect(file_get_contents($this->opened))->toBe(str_repeat('data', 100));
});

test('two encryptions of the same data differ (random per-file salt)', function () {
    file_put_contents($this->plain, 'same data');
    $this->cipher->encryptFile($this->plain, $this->sealed);
    $this->cipher->encryptFile($this->plain, $this->opened);

    expect(file_get_contents($this->sealed))->not->toBe(file_get_contents($this->opened));
});

test('rejects a wrong key, a flipped byte, a truncated file and appended data', function (Closure $damage, ?string $otherKey) {
    file_put_contents($this->plain, str_repeat('rahasia perusahaan ', 20));
    $this->cipher->encryptFile($this->plain, $this->sealed);

    file_put_contents($this->sealed, $damage(file_get_contents($this->sealed)));
    $cipher = $otherKey ? BackupCipher::fromKey($otherKey) : $this->cipher;

    expect(fn () => $cipher->decryptFile($this->sealed, $this->opened))->toThrow(RuntimeException::class);
})->with([
    'wrong key' => [fn (string $bytes) => $bytes, base64_encode(str_repeat('w', 32))],
    'flipped ciphertext byte' => [fn (string $bytes) => substr_replace($bytes, chr(ord($bytes[40]) ^ 1), 40, 1), null],
    'flipped header byte' => [fn (string $bytes) => substr_replace($bytes, chr(ord($bytes[10]) ^ 1), 10, 1), null],
    // header (26) + first chunk (4 + 16 + 16) — ends exactly on a chunk boundary
    'truncated at a chunk boundary' => [fn (string $bytes) => substr($bytes, 0, 26 + 36), null],
    'truncated mid-chunk' => [fn (string $bytes) => substr($bytes, 0, -5), null],
    'data appended' => [fn (string $bytes) => $bytes.$bytes, null],
    'not a backup at all' => [fn (string $bytes) => 'CREATE TABLE users;', null],
]);

test('refuses keys that are not base64 of exactly 32 bytes', function (string $key) {
    expect(fn () => BackupCipher::fromKey($key))->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => [base64_encode(str_repeat('a', 16))],
    'not base64' => ['kunci rahasia!!'],
    'empty' => [''],
]);
