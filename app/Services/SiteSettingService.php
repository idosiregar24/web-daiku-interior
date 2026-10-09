<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Web customization (Pengaturan Situs): identity text, company profile and
 * the uploadable brand assets in SiteSetting::ASSETS. Every change is
 * audited under the `settings.` area — brand changes are visible to every
 * user, so who changed them should be traceable.
 */
class SiteSettingService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PortfolioPhotoService $photoService,
    ) {}

    /** @param  array<string, mixed>  $data  validated text fields */
    public function update(array $data, User $actor): SiteSetting
    {
        return DB::transaction(function () use ($data, $actor) {
            $settings = SiteSetting::current();
            $old = $settings->only(array_keys($data));

            $settings->update($data);

            $changed = array_keys($settings->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at']));

            if ($changed !== []) {
                $this->auditLogService->record(
                    'settings.updated',
                    $settings,
                    array_intersect_key($old, array_flip($changed)),
                    $settings->only($changed),
                    $actor,
                );
            }

            return $settings;
        });
    }

    /** Upload or replace one brand asset; the previous file is removed once the new one is saved. */
    public function storeAsset(string $asset, UploadedFile $file, User $actor): SiteSetting
    {
        $column = SiteSetting::ASSETS[$asset];
        $disk = Storage::disk(SiteSetting::DISK);
        $path = $asset === 'hero_image' ? $this->storeHero($file) : $file->store('branding', SiteSetting::DISK);

        try {
            [$settings, $previous] = DB::transaction(function () use ($asset, $column, $path, $file, $actor) {
                $settings = SiteSetting::current();
                $previous = $settings->{$column};

                $settings->update([$column => $path]);

                $this->auditLogService->record(
                    'settings.asset_uploaded',
                    $settings,
                    [$asset => $previous ? basename($previous) : null],
                    [$asset => $file->getClientOriginalName()],
                    $actor,
                );

                return [$settings, $previous];
            });
        } catch (Throwable $e) {
            // Don't leave an orphaned upload behind when the DB write fails.
            $disk->delete($path);

            throw $e;
        }

        if ($previous) {
            $disk->delete($previous);
        }

        return $settings;
    }

    /**
     * Sprint 20 Sub 05 — a phone photo can be 10 MB; the home page's
     * largest image is resized to WebP (and stripped of its GPS) first.
     */
    private function storeHero(UploadedFile $file): string
    {
        $path = 'branding/'.Str::random(40).'.webp';
        Storage::disk(SiteSetting::DISK)->put($path, $this->photoService->encode($file));

        return $path;
    }

    /** Remove one brand asset — the UI falls back to the default mark/gradient. */
    public function deleteAsset(string $asset, User $actor): SiteSetting
    {
        $column = SiteSetting::ASSETS[$asset];

        [$settings, $previous] = DB::transaction(function () use ($asset, $column, $actor) {
            $settings = SiteSetting::current();
            $previous = $settings->{$column};

            if ($previous) {
                $settings->update([$column => null]);

                $this->auditLogService->record(
                    'settings.asset_removed',
                    $settings,
                    [$asset => basename($previous)],
                    [$asset => null],
                    $actor,
                );
            }

            return [$settings, $previous];
        });

        if ($previous) {
            Storage::disk(SiteSetting::DISK)->delete($previous);
        }

        return $settings;
    }
}
