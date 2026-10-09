<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 20 Sub 04 — portfolio items (⚙ Pengaturan → Portofolio, CEO +
 * Marketing). The one rule that matters: nothing goes public without the
 * client's consent and at least one photo. The slug is made from title +
 * city and frozen at the first publish, so a shared link never breaks.
 */
class PortfolioService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PortfolioPhotoService $photoService,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(array $data, User $actor): PortfolioItem
    {
        return DB::transaction(function () use ($data, $actor) {
            $item = new PortfolioItem([
                ...$data,
                'created_by' => $actor->id,
                'sort_order' => (int) PortfolioItem::query()->max('sort_order') + 1,
            ]);
            $item->slug = $this->uniqueSlug($item);
            $item->save();

            $this->auditLogService->record('portfolio.created', $item, null, $item->only(['title', 'project_type', 'project_id']), $actor);

            return $item;
        });
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(PortfolioItem $item, array $data, User $actor): PortfolioItem
    {
        // An emptied "Urutan" keeps the current position (the column is NOT NULL).
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return DB::transaction(function () use ($item, $data, $actor) {
            $old = $item->only(array_keys($data));
            $item->fill($data);

            // Taking consent back takes the item off the site at once.
            if ($item->is_published && ! $item->client_consent) {
                $item->is_published = false;
            }

            if ($item->published_at === null && $item->isDirty(['title', 'city_id'])) {
                $item->slug = $this->uniqueSlug($item);
            }

            $item->save();

            $changed = array_values(array_diff(array_keys($item->getChanges()), ['updated_at']));

            if ($changed !== []) {
                $this->auditLogService->record('portfolio.updated', $item, array_intersect_key($old, array_flip($changed)), $item->only($changed), $actor);
            }

            return $item;
        });
    }

    public function publish(PortfolioItem $item, User $actor): PortfolioItem
    {
        if (! $item->client_consent) {
            throw ValidationException::withMessages([
                'client_consent' => 'Portofolio hanya bisa diterbitkan bila klien sudah setuju proyeknya ditampilkan.',
            ]);
        }

        if (! $item->photos()->exists()) {
            throw ValidationException::withMessages([
                'photos' => 'Unggah minimal satu foto sebelum portofolio diterbitkan.',
            ]);
        }

        return DB::transaction(function () use ($item, $actor) {
            $item->update(['is_published' => true, 'published_at' => $item->published_at ?? now()]);
            $this->auditLogService->record('portfolio.published', $item, ['is_published' => false], ['is_published' => true], $actor);

            return $item;
        });
    }

    public function unpublish(PortfolioItem $item, User $actor): PortfolioItem
    {
        return DB::transaction(function () use ($item, $actor) {
            $item->update(['is_published' => false]);
            $this->auditLogService->record('portfolio.unpublished', $item, ['is_published' => true], ['is_published' => false], $actor);

            return $item;
        });
    }

    public function delete(PortfolioItem $item, User $actor): void
    {
        $photos = $item->photos()->get(['path', 'path_thumb']);

        DB::transaction(function () use ($item, $actor) {
            $this->auditLogService->record('portfolio.deleted', $item, $item->only(['title', 'slug']), null, $actor);
            $item->delete();
        });

        foreach ($photos as $photo) {
            $this->photoService->deleteFiles($photo->path, $photo->path_thumb);
        }
    }

    /**
     * "Jadikan Portofolio" from a finished project: a draft with only the
     * title, type and city. Never the client's name, address or the
     * contract value — those stay in the system.
     */
    public function createFromProject(Project $project, User $actor): PortfolioItem
    {
        if ($project->status !== ProjectStatus::Completed) {
            throw ValidationException::withMessages([
                'project' => 'Hanya proyek yang sudah selesai yang bisa dijadikan portofolio.',
            ]);
        }

        $existing = PortfolioItem::query()->where('project_id', $project->id)->first();

        if ($existing) {
            return $existing;
        }

        $project->loadMissing('lead.design');

        return $this->create([
            'title' => Str::limit($project->name, 150, ''),
            'project_type' => ProjectType::tryFrom((string) $project->lead?->design?->jenis_project) ?? ProjectType::Lainnya,
            'city_id' => $project->lead?->city_id,
            'year' => ($project->end_date ?? now())->year,
            'project_id' => $project->id,
            'is_published' => false,
            'client_consent' => false,
        ], $actor);
    }

    /** "kitchen-set-putih-pekanbaru", then "-2", "-3"… */
    private function uniqueSlug(PortfolioItem $item): string
    {
        $base = Str::slug(Str::limit($item->title, 120, '').' '.($item->city_id ? $item->city()->value('name') : '')) ?: 'portofolio';
        $slug = $base;

        for ($n = 2; PortfolioItem::query()->where('slug', $slug)->whereKeyNot($item->id)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
