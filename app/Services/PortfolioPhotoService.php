<?php

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Sprint 20 Sub 04 (K6) — every public photo of the company profile goes
 * through here: turned upright (EXIF orientation), shrunk to at most
 * 2000 px plus a 600 px thumbnail, re-encoded as WebP q80. Re-encoding
 * drops all metadata, so the GPS position a phone writes into a photo of
 * the client's house never reaches the site. Also used for the service
 * page and home page hero images (storeImage / encode).
 */
class PortfolioPhotoService
{
    public const MAX_SIZE = 2000;

    public const THUMB_WIDTH = 600;

    public const QUALITY = 80;

    public function __construct(private AuditLogService $auditLogService) {}

    /** @param  list<UploadedFile>  $files */
    public function storeMany(PortfolioItem $item, array $files, User $actor): void
    {
        $next = (int) $item->photos()->max('sort_order');

        foreach ($files as $file) {
            $stored = $this->storeImage($file, "portfolio/{$item->id}");

            try {
                DB::transaction(function () use ($item, $stored, $file, &$next, $actor) {
                    $photo = $item->photos()->create([
                        ...$stored,
                        'alt' => null,
                        'sort_order' => ++$next,
                    ]);

                    // The first photo becomes the cover until someone picks another.
                    if (! $item->cover_photo_id) {
                        $item->update(['cover_photo_id' => $photo->id]);
                    }

                    $this->auditLogService->record('portfolio.photo_added', $item, null, ['photo' => $file->getClientOriginalName()], $actor);
                });
            } catch (Throwable $e) {
                $this->deleteFiles($stored['path'], $stored['path_thumb']);

                throw $e;
            }
        }
    }

    /** @param  array{alt?: string|null, caption?: string|null}  $data */
    public function update(PortfolioPhoto $photo, array $data): PortfolioPhoto
    {
        $photo->update($data);

        return $photo;
    }

    public function setCover(PortfolioPhoto $photo, User $actor): void
    {
        DB::transaction(function () use ($photo, $actor) {
            $item = $photo->item;
            $old = $item->cover_photo_id;
            $item->update(['cover_photo_id' => $photo->id]);

            $this->auditLogService->record('portfolio.cover_changed', $item, ['cover_photo_id' => $old], ['cover_photo_id' => $photo->id], $actor);
        });
    }

    /** @param  list<int>  $ids  the item's photo ids in their new order */
    public function reorder(PortfolioItem $item, array $ids): void
    {
        DB::transaction(function () use ($item, $ids) {
            foreach (array_values($ids) as $position => $id) {
                $item->photos()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function delete(PortfolioPhoto $photo, User $actor): void
    {
        $item = $photo->item;

        // A published item must keep a photo (PortfolioService::publish's own rule).
        if ($item->is_published && $item->photos()->count() <= 1) {
            throw ValidationException::withMessages([
                'photos' => 'Foto terakhir tidak bisa dihapus selama portofolio terbit. Tarik dari situs dulu.',
            ]);
        }

        DB::transaction(function () use ($photo, $item, $actor) {
            $photo->delete();

            if ($item->cover_photo_id === $photo->id) {
                $item->update(['cover_photo_id' => $item->photos()->value('id')]);
            }

            $this->auditLogService->record('portfolio.photo_removed', $item, ['photo_id' => $photo->id], null, $actor);
        });

        $this->deleteFiles($photo->path, $photo->path_thumb);
    }

    /**
     * Resize + WebP + thumbnail onto the `public` disk.
     *
     * @return array{path: string, path_thumb: string, width: int, height: int}
     */
    public function storeImage(UploadedFile $file, string $directory, string $disk = PortfolioPhoto::DISK): array
    {
        $image = $this->manager()->read($file->getRealPath());
        $image->scaleDown(self::MAX_SIZE, self::MAX_SIZE);
        $full = (string) $image->toWebp(quality: self::QUALITY, strip: true);
        [$width, $height] = [$image->width(), $image->height()];

        $image->scaleDown(width: self::THUMB_WIDTH);
        $thumb = (string) $image->toWebp(quality: self::QUALITY, strip: true);

        $name = Str::random(24);
        $path = "{$directory}/{$name}.webp";
        $pathThumb = "{$directory}/{$name}-thumb.webp";

        Storage::disk($disk)->put($path, $full, 'public');
        Storage::disk($disk)->put($pathThumb, $thumb, 'public');

        return ['path' => $path, 'path_thumb' => $pathThumb, 'width' => $width, 'height' => $height];
    }

    /** One WebP of at most MAX_SIZE px, as bytes (the home page hero on the private disk). */
    public function encode(UploadedFile $file): string
    {
        $image = $this->manager()->read($file->getRealPath());
        $image->scaleDown(self::MAX_SIZE, self::MAX_SIZE);

        return (string) $image->toWebp(quality: self::QUALITY, strip: true);
    }

    public function deleteFiles(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));

        if ($paths !== []) {
            Storage::disk(PortfolioPhoto::DISK)->delete($paths);
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
