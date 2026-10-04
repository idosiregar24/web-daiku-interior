<?php

use App\Services\MaterialCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 §5.5 — a catalog item gets a structured identity instead of
     * one free-text name (Lapis 1): category (master), base name, spec,
     * brand, unit; `name` becomes the display name assembled from them and
     * `code` is generated per category (KYP-0012). `match_key` (Lapis 2) is
     * UNIQUE, so the same item can't be saved twice even concurrently.
     * Merging (Lapis 6) leaves the merged item inactive with
     * `merged_into_id` — never deleted, its old stock history stays readable.
     *
     * Legacy rows: the old free-text category becomes a master category
     * (case-insensitive; empty → "Lain-lain"), the old name becomes the
     * base name, codes are numbered in id order. Match keys are then built
     * by MaterialCatalogService::rebuildMatchKeys() — deliberately the
     * runtime service, because a key computed any other way would never
     * match the keys new items get. Rows whose key collides are NOT a
     * migration failure: the oldest keeps the key, the rest are flagged
     * `possible_duplicate` for the Cek Duplikat page.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('material_category_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->nullable()->unique()->after('material_category_id');
            $table->string('base_name', 150)->nullable()->after('name');
            $table->string('spec', 150)->nullable()->after('base_name');
            $table->string('brand', 100)->nullable()->after('spec');
            $table->string('match_key', 64)->nullable()->unique()->after('brand');
            // Flagged duplicates keep the key they collided on, to find their pair.
            $table->string('duplicate_key', 64)->nullable()->index()->after('match_key');
            $table->boolean('possible_duplicate')->default(false)->after('duplicate_key');
            $table->boolean('is_active')->default(true)->after('possible_duplicate');
            $table->foreignId('merged_into_id')->nullable()->after('is_active')->constrained('materials')->nullOnDelete();
        });

        $categoryIds = [];
        foreach (DB::table('materials')->orderBy('id')->get(['id', 'name', 'category']) as $material) {
            $label = trim((string) $material->category) ?: 'Lain-lain';
            $key = mb_strtolower($label);
            $categoryIds[$key] ??= $this->categoryIdFor($label);

            DB::table('materials')->where('id', $material->id)->update([
                'material_category_id' => $categoryIds[$key],
                'base_name' => $material->name,
            ]);
        }

        foreach (DB::table('material_categories')->get(['id', 'code_prefix']) as $category) {
            $number = 0;
            foreach (DB::table('materials')->where('material_category_id', $category->id)->orderBy('id')->pluck('id') as $id) {
                DB::table('materials')->where('id', $id)->update(['code' => sprintf('%s-%04d', $category->code_prefix, ++$number)]);
            }
        }

        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });

        app(MaterialCatalogService::class)->rebuildMatchKeys();
    }

    /** The category name goes back to the free-text column; the master rows stay. */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('category')->nullable()->after('min_stock');
            $table->index('category');
        });

        foreach (DB::table('material_categories')->get(['id', 'name']) as $category) {
            DB::table('materials')->where('material_category_id', $category->id)->update(['category' => $category->name]);
        }

        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropConstrainedForeignId('material_category_id');
            $table->dropUnique(['code']);
            $table->dropUnique(['match_key']);
            $table->dropIndex(['duplicate_key']);
            $table->dropColumn(['code', 'base_name', 'spec', 'brand', 'match_key', 'duplicate_key', 'possible_duplicate', 'is_active']);
        });
    }

    private function categoryIdFor(string $name): int
    {
        $id = DB::table('material_categories')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $base = $this->prefixFrom($name);
        $prefix = $base;
        for ($i = 2; DB::table('material_categories')->where('code_prefix', $prefix)->exists(); $i++) {
            $prefix = mb_substr($base, 0, 2).$i;
        }

        return (int) DB::table('material_categories')->insertGetId([
            'name' => mb_substr($name, 0, 50),
            'code_prefix' => $prefix,
            'is_active' => true,
            'sort_order' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** "Kayu & Panel" → KYP, "Hardware" → HRD: initials, topped up with the first word's consonants. */
    private function prefixFrom(string $name): string
    {
        $words = preg_split('/[^\pL]+/u', mb_strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['X'];
        $prefix = implode('', array_map(fn ($word) => mb_substr($word, 0, 1), $words));

        $consonants = preg_replace('/[AIUEO]/u', '', mb_substr($words[0], 1)) ?? '';
        $prefix .= $consonants.mb_substr($words[0], 1);

        return str_pad(mb_substr($prefix, 0, 3), 3, 'X');
    }
};
