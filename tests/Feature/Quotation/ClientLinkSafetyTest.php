<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Quotation;
use App\Models\QuotationShareLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 17 Sub 01 — the client's link: the public page ships only the
 * public Ziggy routes (not the internal route map), and staff are warned
 * while APP_URL is a local address a client's phone can't open (K1).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function clientLinkSafetyToken(): string
{
    $quotation = Quotation::factory()->sentToClient()->create();
    $token = Str::random(QuotationShareLink::TOKEN_LENGTH);
    QuotationShareLink::create([
        'quotation_id' => $quotation->id,
        'version' => $quotation->version,
        'token' => $token,
        'sent_by' => $quotation->created_by,
    ]);

    return $token;
}

// ── Ziggy publik ─────────────────────────────────────────────────────────

test('the public offer page ships only the public routes, not the internal route map', function () {
    $html = $this->get(route('public.quotation.show', clientLinkSafetyToken()))
        ->assertOk()
        ->getContent();

    // The approve form and the PDF link still resolve on the client.
    expect($html)->toContain('public.quotation.approve')
        ->toContain('public.quotation.pdf')
        ->not->toContain('"horizon.')
        ->not->toContain('"finance.')
        ->not->toContain('"master-data.')
        ->not->toContain('"quotations.')
        ->not->toContain('"dashboard"');
});

test('staff pages still get every route', function () {
    $user = User::factory()->create();
    $user->assignRole('MARKETING');

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('"quotations.show"')
        ->toContain('"finance.');
});

// ── Peringatan APP_URL lokal (K1) ────────────────────────────────────────

test('appUrlIsLocal recognises local development hosts', function (string $url, bool $local) {
    config(['app.url' => $url]);

    expect(HandleInertiaRequests::appUrlIsLocal())->toBe($local);
})->with([
    ['http://web-daiku-interior.test', true],
    ['http://daiku.local', true],
    ['http://localhost:8010', true],
    ['http://127.0.0.1:8010', true],
    ['https://erp.daikuinterior.com', false],
    ['https://staging.daikuinterior.com', false],
    ['http://192.168.1.20:8010', false],
]);

test('the shared appUrlIsLocal prop follows APP_URL', function (string $url, bool $local) {
    config(['app.url' => $url]);
    $user = User::factory()->create();
    $user->assignRole('MARKETING');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('appUrlIsLocal', $local));
})->with([
    'Laragon .test' => ['http://web-daiku-interior.test', true],
    'domain publik' => ['https://erp.daikuinterior.com', false],
]);
