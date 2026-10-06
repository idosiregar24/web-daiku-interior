<?php

use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationReference;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 14 Sub 01 — a RAB request carries reference links and photos for
 * the Estimator; photos stay on the private disk and never reach the client.
 */

beforeEach(function () {
    Storage::fake(QuotationReference::DISK);
    $this->seed(RoleSeeder::class);
    $this->marketing = referenceUser('MARKETING');
    $this->estimator = referenceUser('ESTIMATOR');
    referenceUser('ESTIMATOR'); // a second one, so notifyRoles has more than one recipient
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id]);
});

function referenceUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function requestRabWithReferences(object $test, array $overrides = [])
{
    return $test->actingAs($test->marketing)->post(route('crm.leads.submitRequest', $test->lead), [
        'type' => 'RAB_DESAIN',
        'note' => 'Desain dapur 3×4 m, gaya japandi, budget ±40 juta.',
        'reference_links' => ['https://www.pinterest.com/pin/123', 'https://maps.app.goo.gl/abc'],
        'reference_photos' => [UploadedFile::fake()->image('dapur.jpg', 800, 600), UploadedFile::fake()->image('contoh.png', 400, 400)],
        ...$overrides,
    ]);
}

test('Marketing sends links and photos with a RAB request; they land on the private disk', function () {
    requestRabWithReferences($this)->assertSessionHasNoErrors();

    $quotation = Quotation::where('lead_id', $this->lead->id)->sole();
    $photos = $quotation->references()->where('kind', QuotationReference::KIND_PHOTO)->get();

    expect($quotation->status)->toBe(QuotationStatus::Diminta)
        ->and($quotation->references()->where('kind', QuotationReference::KIND_LINK)->pluck('url')->all())
        ->toBe(['https://www.pinterest.com/pin/123', 'https://maps.app.goo.gl/abc'])
        ->and($photos)->toHaveCount(2)
        ->and($photos->first()->original_name)->toBe('dapur.jpg')
        ->and($photos->first()->path)->toStartWith("quotation-references/{$quotation->id}/");

    Storage::disk(QuotationReference::DISK)->assertExists($photos->pluck('path')->all());

    expect(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_requested')->value('message'))
        ->toEndWith('(+2 foto, 2 link)');
});

test('the Estimator sees the references on the quotation and can open the photos', function () {
    requestRabWithReferences($this);
    $quotation = Quotation::where('lead_id', $this->lead->id)->sole();
    $photo = $quotation->references()->where('kind', QuotationReference::KIND_PHOTO)->first();

    $this->actingAs($this->estimator)
        ->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quotation.references', 4)
            ->where('quotation.references.2.photo_url', route('quotations.references.show', [$quotation, $photo]))
            ->missing('quotation.references.2.path'));

    $this->actingAs($this->estimator)
        ->get(route('quotations.references.show', [$quotation, $photo]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a RAB request without references still works', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => 'RAB_SURVEY',
        'note' => 'Survey lokasi Bangkinang.',
    ])->assertSessionHasNoErrors();

    expect(QuotationReference::count())->toBe(0);
});

test('unsafe or excessive references are rejected', function (array $overrides, string $errorKey) {
    requestRabWithReferences($this, $overrides)->assertSessionHasErrors($errorKey);

    expect(Quotation::count())->toBe(0)->and(QuotationReference::count())->toBe(0);
})->with([
    'javascript link' => [['reference_links' => ['javascript:alert(1)']], 'reference_links.0'],
    'ftp link' => [['reference_links' => ['ftp://example.com/file']], 'reference_links.0'],
    'six links' => [['reference_links' => array_map(fn ($i) => "https://example.com/{$i}", range(1, 6))], 'reference_links'],
    'nine photos' => [['reference_photos' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 9))], 'reference_photos'],
    'not an image' => [['reference_photos' => [UploadedFile::fake()->create('rab.pdf', 100, 'application/pdf')]], 'reference_photos.0'],
    'too large' => [['reference_photos' => [UploadedFile::fake()->image('besar.jpg')->size(6000)]], 'reference_photos.0'],
]);

test('a photo is only served to roles that may open the quotation, and only under its own quotation', function () {
    requestRabWithReferences($this);
    $quotation = Quotation::where('lead_id', $this->lead->id)->sole();
    $photo = $quotation->references()->where('kind', QuotationReference::KIND_PHOTO)->first();
    $link = $quotation->references()->where('kind', QuotationReference::KIND_LINK)->first();
    $other = Quotation::factory()->create();

    $this->actingAs(referenceUser('FIELD_STAFF'))->get(route('quotations.references.show', [$quotation, $photo]))->assertForbidden();
    $this->actingAs(referenceUser('LOGISTICS'))->get(route('quotations.references.show', [$quotation, $photo]))->assertForbidden();
    $this->actingAs($this->estimator)->get(route('quotations.references.show', [$other, $photo]))->assertNotFound();
    $this->actingAs($this->estimator)->get(route('quotations.references.show', [$quotation, $link]))->assertNotFound();

    auth()->logout();
    $this->get(route('quotations.references.show', [$quotation, $photo]))->assertRedirect(route('login'));
});

test('references never reach the client link', function () {
    requestRabWithReferences($this);
    $quotation = Quotation::where('lead_id', $this->lead->id)->sole();
    $quotation->update(['status' => QuotationStatus::SentToClient->value, 'total_amount' => 5_000_000]);

    $this->get(route('public.quotation.show', shareLinkFor($quotation)->token))
        ->assertOk()
        ->assertDontSee('pinterest.com', false)
        ->assertDontSee('quotation-references', false)
        ->assertDontSee('/references/', false);
});

test('the PM asking for a RAB Tambahan can attach references too', function () {
    $pm = referenceUser('PM');
    $rabFix = Quotation::factory()->approved()->create(['lead_id' => $this->lead->id]);
    $this->lead->update(['status' => LeadStatus::Closing->value]);
    $project = Project::factory()->create(['lead_id' => $this->lead->id, 'quotation_id' => $rabFix->id, 'pm_id' => $pm->id]);

    $this->actingAs($pm)->post(route('projects.addenda.store', $project), [
        'note' => 'Tambah plafon drop ceiling ruang tamu.',
        'reference_links' => ['https://drive.google.com/x'],
        'reference_photos' => [UploadedFile::fake()->image('plafon.jpg')],
    ])->assertSessionHasNoErrors();

    $addendum = $project->addenda()->sole();

    expect($addendum->references()->pluck('kind')->all())->toBe(['LINK', 'PHOTO']);
});
