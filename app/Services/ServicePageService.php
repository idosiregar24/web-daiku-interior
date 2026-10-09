<?php

namespace App\Services;

use App\Models\ServicePage;
use App\Models\User;
use App\Support\CompanyProfile\Placeholder;
use App\Support\CompanyProfile\ServiceCatalog;
use App\Support\CompanyProfile\ServiceEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 20 Sub 06 (K4) — the text of the `/layanan/{slug}` pages. A page
 * that still carries the placeholder intro or body cannot be published:
 * near-identical pages are exactly what pushes a site down in Google.
 */
class ServicePageService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PortfolioPhotoService $photoService,
    ) {}

    /**
     * One row per ServiceCatalog entry, created with the placeholder text
     * (unpublished) when missing. Idempotent — ServicePageSeeder and the
     * Pengaturan list both call it, so a new catalog entry just appears.
     */
    public function syncCatalog(): void
    {
        foreach (ServiceCatalog::all() as $entry) {
            $page = ServicePage::query()->firstOrNew(['project_type' => $entry->type->value]);

            if (! $page->exists) {
                $page->fill([...Placeholder::servicePage($entry), 'slug' => $entry->slug, 'is_published' => false])->save();
            } elseif ($page->slug !== $entry->slug) {
                // The home city changed: the slug follows the catalog, never the UI.
                $page->update(['slug' => $entry->slug]);
            }
        }
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(ServicePage $page, array $data, User $actor): ServicePage
    {
        return DB::transaction(function () use ($page, $data, $actor) {
            $old = $page->only(array_keys($data));
            $page->fill([...$data, 'updated_by' => $actor->id]);

            if ($page->is_published && $this->stillPlaceholder($page)) {
                throw ValidationException::withMessages([
                    'is_published' => 'Ganti teks pembuka dan isi halaman dengan tulisan sendiri sebelum halaman ini diterbitkan.',
                ]);
            }

            $page->save();

            $changed = array_values(array_diff(array_keys($page->getChanges()), ['updated_at', 'updated_by']));

            if ($changed !== []) {
                $this->auditLogService->record('service_page.updated', $page, array_intersect_key($old, array_flip($changed)), $page->only($changed), $actor);
            }

            return $page;
        });
    }

    public function storeHero(ServicePage $page, UploadedFile $file, User $actor): ServicePage
    {
        $stored = $this->photoService->storeImage($file, 'service-pages');
        $previous = $page->hero_image;

        DB::transaction(function () use ($page, $stored, $actor) {
            // Only the large file is used for a hero; the thumbnail goes.
            $this->photoService->deleteFiles($stored['path_thumb']);
            $page->update([
                'hero_image' => $stored['path'],
                'hero_width' => $stored['width'],
                'hero_height' => $stored['height'],
                'updated_by' => $actor->id,
            ]);
            $this->auditLogService->record('service_page.hero_uploaded', $page, null, ['hero_image' => basename($stored['path'])], $actor);
        });

        $this->photoService->deleteFiles($previous);

        return $page;
    }

    public function deleteHero(ServicePage $page, User $actor): ServicePage
    {
        $previous = $page->hero_image;

        if ($previous) {
            DB::transaction(function () use ($page, $actor, $previous) {
                $page->update(['hero_image' => null, 'hero_width' => null, 'hero_height' => null, 'updated_by' => $actor->id]);
                $this->auditLogService->record('service_page.hero_removed', $page, ['hero_image' => basename($previous)], null, $actor);
            });

            $this->photoService->deleteFiles($previous);
        }

        return $page;
    }

    /** True while intro or body is still the seeded placeholder text. */
    public function stillPlaceholder(ServicePage $page): bool
    {
        $entry = $page->entry();

        if (! $entry instanceof ServiceEntry) {
            return false;
        }

        $placeholder = Placeholder::servicePage($entry);

        return $this->same($page->intro, $placeholder['intro']) || $this->same($page->body, $placeholder['body']);
    }

    private function same(?string $a, string $b): bool
    {
        $normalize = fn (?string $text) => preg_replace('/\s+/u', ' ', trim((string) $text));

        return $normalize($a) === $normalize($b);
    }
}
