<?php

namespace App\Services;

use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Sprint 20 Sub 05 — testimonials of the company profile (CEO + Marketing). */
class TestimonialService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /** @param  array<string, mixed>  $data  validated */
    public function create(array $data, User $actor): Testimonial
    {
        return DB::transaction(function () use ($data, $actor) {
            $testimonial = Testimonial::create([
                ...$data,
                'created_by' => $actor->id,
                'sort_order' => $data['sort_order'] ?? ((int) Testimonial::query()->max('sort_order') + 1),
            ]);

            $this->auditLogService->record('testimonial.created', $testimonial, null, $testimonial->only(['client_label', 'is_published']), $actor);

            return $testimonial;
        });
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(Testimonial $testimonial, array $data, User $actor): Testimonial
    {
        // An emptied "Urutan" keeps the current position (the column is NOT NULL).
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return DB::transaction(function () use ($testimonial, $data, $actor) {
            $old = $testimonial->only(array_keys($data));
            $testimonial->update($data);
            $changed = array_values(array_diff(array_keys($testimonial->getChanges()), ['updated_at']));

            if ($changed !== []) {
                $this->auditLogService->record('testimonial.updated', $testimonial, array_intersect_key($old, array_flip($changed)), $testimonial->only($changed), $actor);
            }

            return $testimonial;
        });
    }

    public function delete(Testimonial $testimonial, User $actor): void
    {
        DB::transaction(function () use ($testimonial, $actor) {
            $this->auditLogService->record('testimonial.deleted', $testimonial, $testimonial->only(['client_label', 'quote']), null, $actor);
            $testimonial->delete();
        });
    }
}
