<?php

use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductionSeeder;

// The demo seeder walks the whole business process through the real
// services — running it here catches any rule change that breaks it
// (e.g. the family fund balance rule once made it overdraw).
test('the full demo seed runs cleanly and populates every module', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::role('CEO')->exists())->toBeTrue()
        ->and(Project::count())->toBeGreaterThan(0)
        ->and(Material::lowStock()->count())->toBe(1)
        // The ledger reconciles with the running stock totals (returns and merges — Sprint 11).
        ->and((float) Material::sum('stock'))->toBe(
            (float) StockMovement::whereIn('type', ['IN', 'RETURN', 'MERGE_IN'])->sum('qty')
                - (float) StockMovement::whereIn('type', ['OUT', 'MERGE_OUT'])->sum('qty'),
        )
        ->and(StockMovement::where('type', 'RETURN')->exists())->toBeTrue()
        // Sprint 11 Sub 3–5: every material source, every open request state, a flagged duplicate.
        ->and(ProjectMaterial::approved()->distinct()->pluck('source')->map->value->sort()->values()->all())->toBe(['CUSTOM', 'GUDANG', 'PEMBELIAN'])
        ->and(ProjectMaterial::where('request_status', 'DIAJUKAN')->exists())->toBeTrue()
        ->and(ProjectMaterial::where('request_status', 'MENUNGGU_PM')->exists())->toBeTrue()
        ->and(Material::where('possible_duplicate', true)->count())->toBe(1)
        ->and(Vendor::count())->toBeGreaterThan(0)
        ->and(AuditLog::count())->toBeGreaterThan(0);
});

test('the production seeder creates only the configured accounts, from env', function () {
    putenv('INITIAL_CEO_EMAIL=owner@daiku.test');
    putenv('INITIAL_CEO_PASSWORD=sangat-rahasia-123');
    putenv('INITIAL_SUPERADMIN_EMAIL=it@daiku.test');
    putenv('INITIAL_SUPERADMIN_PASSWORD=sangat-rahasia-456');

    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class); // idempotent

    expect(User::count())->toBe(2)
        ->and(User::where('email', 'owner@daiku.test')->sole()->hasRole('CEO'))->toBeTrue()
        ->and(User::where('email', 'ceo@daikuinterior.com')->exists())->toBeFalse();

    foreach (['INITIAL_CEO_EMAIL', 'INITIAL_CEO_PASSWORD', 'INITIAL_SUPERADMIN_EMAIL', 'INITIAL_SUPERADMIN_PASSWORD'] as $key) {
        putenv($key);
    }
});

test('the production seeder refuses to run without credentials', function () {
    expect(fn () => $this->seed(ProductionSeeder::class))->toThrow(RuntimeException::class);
});
