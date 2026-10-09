<?php

namespace Database\Seeders;

use App\Services\ServicePageService;
use Illuminate\Database\Seeder;

/**
 * Sprint 20 Sub 06 — one unpublished service page per ServiceCatalog entry,
 * with placeholder text. Idempotent (production too): existing pages and
 * their edited text are never touched.
 */
class ServicePageSeeder extends Seeder
{
    public function run(ServicePageService $service): void
    {
        $service->syncCatalog();
    }
}
