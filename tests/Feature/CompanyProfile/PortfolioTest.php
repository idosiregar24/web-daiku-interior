<?php

use App\Enums\ProjectStatus;
use App\Models\City;
use App\Models\Design;
use App\Models\Lead;
use App\Models\PortfolioItem;
use App\Models\PortfolioPhoto;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Sprint 20 Sub 04 — portfolio: managed by CEO + Marketing, photos become
 * WebP without metadata, nothing goes public without the client's consent.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(PortfolioPhoto::DISK);
});

function portfolioUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function portfolioItem(array $attributes = []): PortfolioItem
{
    static $n = 0;
    $n++;

    return PortfolioItem::create([
        'title' => "Kitchen Set Contoh {$n}",
        'slug' => "kitchen-set-contoh-{$n}",
        'project_type' => 'KITCHEN_SET',
        'location_label' => 'Panam',
        'year' => 2025,
        'is_published' => false,
        'client_consent' => false,
        ...$attributes,
    ]);
}

function withPhoto(PortfolioItem $item): PortfolioItem
{
    $photo = $item->photos()->create(['path' => 'portfolio/x.webp', 'path_thumb' => 'portfolio/x-thumb.webp', 'width' => 1600, 'height' => 1200]);
    $item->update(['cover_photo_id' => $photo->id]);

    return $item;
}

/** A JPEG carrying an EXIF block with a GPS marker, like a phone photo. */
function jpegWithExif(): UploadedFile
{
    $image = imagecreatetruecolor(1200, 900);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 180, 150));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $payload = "Exif\0\0".'MM'."\0\x2A\0\0\0\x08\0\0".'GPSLatitude-0.5071 Pekanbaru';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return new UploadedFile($path, 'rumah-klien.jpg', 'image/jpeg', null, true);
}

test('CEO, Marketing and SuperAdmin manage the portfolio', function (string $role) {
    $user = portfolioUser($role);

    $this->actingAs($user)->get(route('settings.portfolio.index'))->assertOk();

    $this->actingAs($user)->post(route('settings.portfolio.store'), [
        'title' => 'Kitchen Set Putih',
        'project_type' => 'KITCHEN_SET',
        'city_id' => City::idFor('Pekanbaru'),
        'location_label' => 'Panam',
        'year' => 2025,
    ])->assertRedirect();

    $item = PortfolioItem::firstWhere('title', 'Kitchen Set Putih');
    expect($item->slug)->toBe('kitchen-set-putih-pekanbaru')
        ->and($item->is_published)->toBeFalse()
        ->and($item->created_by)->toBe($user->id);

    $this->actingAs($user)->get(route('settings.portfolio.edit', $item))->assertOk();
})->with(['CEO', 'MARKETING', 'SUPERADMIN']);

test('other roles cannot reach the portfolio settings', function (string $role) {
    $user = portfolioUser($role);
    $item = portfolioItem();

    $this->actingAs($user)->get(route('settings.portfolio.index'))->assertForbidden();
    $this->actingAs($user)->post(route('settings.portfolio.store'), ['title' => 'X', 'project_type' => 'CAFE'])->assertForbidden();
    $this->actingAs($user)->patch(route('settings.portfolio.publish', $item))->assertForbidden();
})->with(['PM', 'FINANCE', 'FIELD_STAFF', 'DESIGNER']);

test('publishing needs the client consent and a photo', function () {
    $ceo = portfolioUser('CEO');
    $item = portfolioItem();

    $this->actingAs($ceo)->patch(route('settings.portfolio.publish', $item))->assertSessionHasErrors('client_consent');

    $item->update(['client_consent' => true]);
    $this->actingAs($ceo)->patch(route('settings.portfolio.publish', $item))->assertSessionHasErrors('photos');

    withPhoto($item);
    $this->actingAs($ceo)->patch(route('settings.portfolio.publish', $item))->assertSessionHasNoErrors();

    expect($item->fresh()->is_published)->toBeTrue()
        ->and($item->fresh()->published_at)->not->toBeNull();
});

test('taking consent back takes the item off the site', function () {
    $item = withPhoto(portfolioItem(['client_consent' => true, 'is_published' => true, 'published_at' => now()]));

    $this->actingAs(portfolioUser('MARKETING'))->put(route('settings.portfolio.update', $item), [
        'title' => $item->title,
        'project_type' => 'KITCHEN_SET',
        'client_consent' => false,
    ])->assertSessionHasNoErrors();

    expect($item->fresh()->is_published)->toBeFalse();
});

test('the slug is frozen once the item has been published', function () {
    $item = withPhoto(portfolioItem(['client_consent' => true]));
    $ceo = portfolioUser('CEO');
    $this->actingAs($ceo)->patch(route('settings.portfolio.publish', $item));
    $slug = $item->fresh()->slug;

    $this->actingAs($ceo)->put(route('settings.portfolio.update', $item), [
        'title' => 'Judul Baru Sekali',
        'project_type' => 'KITCHEN_SET',
        'client_consent' => true,
    ]);

    expect($item->fresh()->slug)->toBe($slug);
});

test('uploaded photos become a WebP and a 600 px thumbnail, without EXIF', function () {
    $item = portfolioItem();

    $this->actingAs(portfolioUser('MARKETING'))
        ->post(route('settings.portfolio.photos.store', $item), ['photos' => [jpegWithExif()]])
        ->assertSessionHasNoErrors();

    $photo = $item->photos()->first();
    $disk = Storage::disk(PortfolioPhoto::DISK);

    expect($photo->path)->toEndWith('.webp')
        ->and($item->fresh()->cover_photo_id)->toBe($photo->id)
        ->and([$photo->width, $photo->height])->toBe([1200, 900]);

    $full = $disk->get($photo->path);
    $thumb = $disk->get($photo->path_thumb);

    expect(getimagesizefromstring($full)['mime'])->toBe('image/webp')
        ->and(getimagesizefromstring($thumb)[0])->toBe(600)
        ->and($full)->not->toContain('Exif')
        ->and($full)->not->toContain('GPSLatitude');
});

test('a photo from another portfolio item is out of reach', function () {
    $item = portfolioItem();
    $other = withPhoto(portfolioItem());

    $this->actingAs(portfolioUser('CEO'))
        ->delete(route('settings.portfolio.photos.destroy', [$item, $other->photos()->first()]))
        ->assertNotFound();
});

test('deleting the cover photo makes the next photo the cover', function () {
    $item = withPhoto(portfolioItem());
    $second = $item->photos()->create(['path' => 'portfolio/y.webp', 'path_thumb' => 'portfolio/y-thumb.webp', 'width' => 800, 'height' => 600, 'sort_order' => 2]);

    $this->actingAs(portfolioUser('CEO'))
        ->delete(route('settings.portfolio.photos.destroy', [$item, $item->cover_photo_id]))
        ->assertSessionHasNoErrors();

    expect($item->fresh()->cover_photo_id)->toBe($second->id);
});

test('the public pages show published items only', function () {
    $published = withPhoto(portfolioItem(['title' => 'Cafe Terbit', 'project_type' => 'CAFE', 'is_published' => true, 'client_consent' => true, 'published_at' => now()]));
    $draft = withPhoto(portfolioItem(['title' => 'Kamar Draf', 'project_type' => 'KAMAR_SET']));

    $this->get(route('site.portfolio.index'))->assertOk()->assertSee('Cafe Terbit')->assertDontSee('Kamar Draf');
    $this->get(route('site.portfolio.show', $published->slug))->assertOk()->assertSee('Cafe Terbit')->assertSee('CreativeWork');
    $this->get(route('site.portfolio.show', $draft->slug))->assertNotFound();
    $this->get('/')->assertSee('Cafe Terbit')->assertDontSee('Kamar Draf');

    $this->get('/sitemap.xml')
        ->assertSee(route('site.portfolio.show', $published->slug), false)
        ->assertDontSee(route('site.portfolio.show', $draft->slug), false);
});

test('the portfolio list filters by service, with TOKO and RETAIL_TOKO together', function () {
    withPhoto(portfolioItem(['title' => 'Toko Baju', 'project_type' => 'TOKO', 'is_published' => true, 'client_consent' => true]));
    withPhoto(portfolioItem(['title' => 'Retail Sepatu', 'project_type' => 'RETAIL_TOKO', 'is_published' => true, 'client_consent' => true]));
    withPhoto(portfolioItem(['title' => 'Cafe Kopi', 'project_type' => 'CAFE', 'is_published' => true, 'client_consent' => true]));

    $this->get(route('site.portfolio.index', ['jenis' => 'toko']))
        ->assertOk()
        ->assertSee('Toko Baju')
        ->assertSee('Retail Sepatu')
        ->assertDontSee('Cafe Kopi');
});

test('Jadikan Portofolio copies only title, type and city from a finished project', function () {
    $lead = Lead::factory()->create(['client_name' => 'Bapak Rahasia', 'address' => 'Jl. Rumah Klien No. 9', 'city_id' => City::idFor('Pekanbaru')]);
    Design::factory()->create(['lead_id' => $lead->id, 'jenis_project' => 'CAFE']);
    $project = Project::factory()->create(['lead_id' => $lead->id, 'name' => 'Cafe Senja', 'status' => ProjectStatus::Completed->value]);

    $this->actingAs(portfolioUser('MARKETING'))
        ->post(route('settings.portfolio.fromProject', $project))
        ->assertRedirect();

    $item = PortfolioItem::firstWhere('project_id', $project->id);

    expect($item->title)->toBe('Cafe Senja')
        ->and($item->project_type->value)->toBe('CAFE')
        ->and($item->city_id)->toBe($lead->city_id)
        ->and($item->is_published)->toBeFalse()
        ->and(json_encode($item->toArray()))->not->toContain('Rahasia')
        ->and(json_encode($item->toArray()))->not->toContain('Rumah Klien');
});

test('a project that is not finished cannot become a portfolio item', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active->value]);

    $this->actingAs(portfolioUser('CEO'))
        ->post(route('settings.portfolio.fromProject', $project))
        ->assertSessionHasErrors('project');

    expect(PortfolioItem::count())->toBe(0);
});

test('the last photo of a published item cannot be deleted', function () {
    $item = withPhoto(portfolioItem(['client_consent' => true, 'is_published' => true]));

    $this->actingAs(portfolioUser('CEO'))
        ->delete(route('settings.portfolio.photos.destroy', [$item, $item->cover_photo_id]))
        ->assertSessionHasErrors('photos');

    expect($item->photos()->count())->toBe(1);
});

test('an emptied sort order keeps the current position', function () {
    $item = portfolioItem(['sort_order' => 4]);

    $this->actingAs(portfolioUser('MARKETING'))->put(route('settings.portfolio.update', $item), [
        'title' => $item->title,
        'project_type' => 'KITCHEN_SET',
        'sort_order' => null,
    ])->assertSessionHasNoErrors();

    expect($item->fresh()->sort_order)->toBe(4);
});
