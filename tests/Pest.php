<?php

use App\Models\MaterialCategory;
use App\Models\Quotation;
use App\Models\QuotationShareLink;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Master Satuan id by code (Sprint 11) — created on first use, like UnitSeeder would. */
function unitId(string $code): int
{
    return Unit::firstOrCreate(['code' => $code], ['name' => ucfirst($code)])->id;
}

/** Master Vendor id by name (Sprint 11) — created on first use. */
function vendorId(string $name): int
{
    return Vendor::firstOrCreate(['name' => $name], ['type' => Vendor::TYPE_MATERIAL])->id;
}

/** Material category id by code prefix (Sprint 11 Sub 5) — created on first use. */
function categoryId(string $prefix = 'KYP', ?string $name = null): int
{
    return MaterialCategory::firstOrCreate(['code_prefix' => $prefix], ['name' => $name ?? "Kategori {$prefix}"])->id;
}

/**
 * Sprint 12 #8 — QuotationService::review() with every item marked: ✔
 * unless its description is a key of `$wrong` (→ ✘ with that note).
 * Decision "return" when anything is ✘ or a note is given, else "approve".
 *
 * @param  array<string, string>  $wrong
 */
function reviewQuotation(Quotation $quotation, User $reviewer, array $wrong = [], ?string $note = null): Quotation
{
    return app(QuotationService::class)->review($quotation, [
        'decision' => $wrong === [] && $note === null ? 'approve' : 'return',
        'note' => $note,
        'items' => $quotation->items()->get()->map(fn ($item) => [
            'item_id' => $item->id,
            'verdict' => isset($wrong[$item->description]) ? 'SALAH' : 'OK',
            'note' => $wrong[$item->description] ?? null,
        ])->all(),
    ], $reviewer);
}

/** Sprint 12 #13 — a client link for the quotation's current version (as sendToClient() makes it). */
function shareLinkFor(Quotation $quotation, ?User $sender = null): QuotationShareLink
{
    return $quotation->shareLinks()->create([
        'version' => $quotation->version,
        'token' => Str::random(QuotationShareLink::TOKEN_LENGTH),
        'sent_by' => ($sender ?? User::factory()->create())->id,
    ]);
}
