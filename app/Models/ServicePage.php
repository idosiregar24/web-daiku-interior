<?php

namespace App\Models;

use App\Enums\ProjectType;
use App\Support\CompanyProfile\ServiceCatalog;
use App\Support\CompanyProfile\ServiceEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 20 Sub 06 — the editable text of one `/layanan/{slug}` page.
 * `project_type` and `slug` are fixed by ServiceCatalog (never edited);
 * the rest goes through ServicePageService.
 */
class ServicePage extends Model
{
    public const DISK = 'public';

    public const MAX_FAQS = 8;

    protected $fillable = [
        'project_type',
        'slug',
        'title',
        'headline',
        'intro',
        'body',
        'highlights',
        'faqs',
        'meta_description',
        'hero_image',
        'hero_width',
        'hero_height',
        'is_published',
        'updated_by',
    ];

    protected $hidden = ['hero_image'];

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'highlights' => 'array',
            'faqs' => 'array',
            'is_published' => 'boolean',
            'hero_width' => 'integer',
            'hero_height' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function entry(): ?ServiceEntry
    {
        return ServiceCatalog::forType($this->project_type);
    }

    public function heroUrl(): ?string
    {
        return $this->hero_image ? Storage::disk(self::DISK)->url($this->hero_image) : null;
    }

    /**
     * `body` as blocks: a line starting with "## " is a subheading, blank
     * lines separate paragraphs. Rendered escaped — never raw HTML.
     *
     * @return list<array{type: 'heading'|'paragraph', text: string}>
     */
    public function bodyBlocks(): array
    {
        $blocks = [];

        foreach (preg_split('/\R{2,}/', trim((string) $this->body)) as $chunk) {
            foreach (preg_split('/\R/', trim($chunk)) as $index => $line) {
                $line = trim($line);

                if (str_starts_with($line, '## ')) {
                    $blocks[] = ['type' => 'heading', 'text' => trim(substr($line, 3))];
                } elseif ($line !== '') {
                    $last = array_key_last($blocks);
                    // Lines of one paragraph stay one paragraph.
                    if ($index > 0 && $last !== null && $blocks[$last]['type'] === 'paragraph') {
                        $blocks[$last]['text'] .= ' '.$line;
                    } else {
                        $blocks[] = ['type' => 'paragraph', 'text' => $line];
                    }
                }
            }
        }

        return $blocks;
    }
}
