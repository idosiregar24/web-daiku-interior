<?php

namespace App\Models;

use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 20 Sub 04 — one finished job on the public company profile.
 * Written only through PortfolioService (slug, consent rule, audit);
 * photos through PortfolioPhotoService.
 */
class PortfolioItem extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'project_type',
        'city_id',
        'location_label',
        'year',
        'summary',
        'description',
        'cover_photo_id',
        'is_published',
        'client_consent',
        'project_id',
        'sort_order',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'year' => 'integer',
            'cover_photo_id' => 'integer',
            'is_published' => 'boolean',
            'client_consent' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PortfolioPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(PortfolioPhoto::class, 'cover_photo_id');
    }

    /** What the public pages may show: published, newest arrangement first. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id');
    }

    /** @param  list<ProjectType>  $types */
    public function scopeOfTypes(Builder $query, array $types): Builder
    {
        return $query->whereIn('project_type', array_map(fn (ProjectType $type) => $type->value, $types));
    }

    /** The cover, or the first photo when none was picked. */
    public function coverPhoto(): ?PortfolioPhoto
    {
        return $this->cover ?? $this->photos->first();
    }

    /** "Panam, Pekanbaru" — district and city, whichever are known. */
    public function placeLabel(): ?string
    {
        $parts = array_filter([$this->location_label, $this->city?->name]);

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }
}
