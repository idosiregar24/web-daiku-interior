<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Sprint 23 K12d — payment proof photos of "Tandai Klien Sudah Bayar".
 * Same pipeline as the public photos (PortfolioPhotoService): turned
 * upright, shrunk to at most 2000 px plus a small thumbnail for Finance's
 * queue, re-encoded as WebP — re-encoding drops every bit of metadata
 * (EXIF, GPS), and nothing of the uploaded file (name, bytes) is kept.
 * Unlike the portfolio the files go to the private disk and are only
 * streamed by InvoiceController::paymentProof() behind InvoicePolicy.
 *
 * Encoding (CPU) happens before InvoiceService opens its transaction;
 * writing happens inside it, and the caller removes the written files if
 * that transaction fails (deleteOrphans()).
 */
class InvoicePaymentProofService
{
    public const MAX_SIZE = 2000;

    public const THUMB_WIDTH = 480;

    public const QUALITY = 80;

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{full: string, thumb: string, width: int, height: int}>
     */
    public function encode(array $files): array
    {
        return array_map(function (UploadedFile $file) {
            try {
                $image = $this->manager()->read($file->getRealPath());
            } catch (Throwable) {
                throw ValidationException::withMessages(['payment_photos' => 'Foto bukti bayar tidak bisa dibaca — unggah foto JPG, PNG, atau WEBP lain.']);
            }

            $image->scaleDown(self::MAX_SIZE, self::MAX_SIZE);
            $full = (string) $image->toWebp(quality: self::QUALITY, strip: true);
            [$width, $height] = [$image->width(), $image->height()];

            $image->scaleDown(width: self::THUMB_WIDTH);
            $thumb = (string) $image->toWebp(quality: self::QUALITY, strip: true);

            return ['full' => $full, 'thumb' => $thumb, 'width' => $width, 'height' => $height];
        }, array_values($files));
    }

    /**
     * Writes the encoded photos of submission `$attempt` and records them.
     * Every path written is pushed onto `$written` (even when a later one
     * fails) so the caller can clean up after a rolled-back transaction.
     *
     * @param  list<array{full: string, thumb: string, width: int, height: int}>  $encoded
     * @param  list<string>  $written
     * @return Collection<int, InvoicePaymentProof>
     */
    public function store(Invoice $invoice, array $encoded, int $attempt, User $actor, array &$written): Collection
    {
        $disk = Storage::disk(InvoicePaymentProof::DISK);

        return collect($encoded)->map(function (array $photo) use ($invoice, $attempt, $actor, $disk, &$written) {
            $name = Str::random(40);
            $path = "invoice-proofs/{$invoice->id}/{$name}.webp";
            $pathThumb = "invoice-proofs/{$invoice->id}/{$name}-thumb.webp";

            foreach ([$path => $photo['full'], $pathThumb => $photo['thumb']] as $target => $bytes) {
                if (! $disk->put($target, $bytes)) {
                    throw new \RuntimeException('Foto bukti bayar gagal disimpan.');
                }
                $written[] = $target;
            }

            return InvoicePaymentProof::create([
                'invoice_id' => $invoice->id,
                'attempt' => $attempt,
                'path' => $path,
                'path_thumb' => $pathThumb,
                'width' => $photo['width'],
                'height' => $photo['height'],
                'size' => strlen($photo['full']),
                'uploaded_by' => $actor->id,
            ]);
        });
    }

    /** Files written for rows that were rolled back — never a recorded proof (those are append-only). */
    public function deleteOrphans(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(InvoicePaymentProof::DISK)->delete($paths);
        }
    }

    private function manager(): ImageManager
    {
        // A 50 MP phone photo decodes to ~200 MB of pixels in GD.
        $limit = (string) ini_get('memory_limit');

        if ($limit !== '-1' && ini_parse_quantity($limit) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        return new ImageManager(new Driver);
    }
}
