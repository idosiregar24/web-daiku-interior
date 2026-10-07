<?php

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sprint 16 Sub 07 — a lead's contact is a mobile number (`phone`, digits
 * only starting 08) and/or an email; at least one is required.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = User::factory()->create();
    $this->marketing->assignRole('MARKETING');
});

function contactPayload(User $marketing, array $contact): array
{
    return [
        'client_name' => 'Budi Santoso',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
        ...$contact,
    ];
}

test('a formatted number is stored as digits starting 08', function () {
    $this->actingAs($this->marketing)
        ->post(route('crm.leads.store'), contactPayload($this->marketing, ['phone' => '+62 812-3456-7890']))
        ->assertSessionHasNoErrors();

    expect(Lead::first()->phone)->toBe('081234567890')
        ->and(Lead::first()->email)->toBeNull();
});

test('an email alone is enough and is stored lower-cased', function () {
    $this->actingAs($this->marketing)
        ->post(route('crm.leads.store'), contactPayload($this->marketing, ['email' => '  Budi.Santoso@Gmail.com ']))
        ->assertSessionHasNoErrors();

    expect(Lead::first()->email)->toBe('budi.santoso@gmail.com')
        ->and(Lead::first()->phone)->toBeNull();
});

test('invalid contacts are rejected', function (array $contact, string $field) {
    $this->actingAs($this->marketing)
        ->post(route('crm.leads.store'), contactPayload($this->marketing, $contact))
        ->assertSessionHasErrors($field);

    expect(Lead::count())->toBe(0);
})->with([
    'letters in the number' => [['phone' => '0812abcd7890'], 'phone'],
    'not starting with 08' => [['phone' => '0761123456'], 'phone'],
    'too short' => [['phone' => '0812345'], 'phone'],
    'malformed email' => [['email' => 'budi@'], 'email'],
    'neither given' => [['phone' => '', 'email' => ''], 'phone'],
]);

test('updating a lead normalizes the number the same way', function () {
    $lead = Lead::factory()->create(['assigned_to' => $this->marketing->id]);

    $this->actingAs($this->marketing)
        ->put(route('crm.leads.update', $lead), contactPayload($this->marketing, ['phone' => '0812.9999.8888', 'email' => '']))
        ->assertSessionHasNoErrors();

    expect($lead->fresh()->phone)->toBe('081299998888')
        ->and($lead->fresh()->email)->toBeNull();
});

test('lead search finds a client by phone digits in any format', function () {
    Lead::factory()->create(['client_name' => 'Rina', 'phone' => '081277776666']);
    Lead::factory()->create(['client_name' => 'Joko', 'phone' => '081311112222']);

    expect(Lead::search('+62 812-7777')->pluck('client_name')->all())->toBe(['Rina']);
});

test('the contact migration converts old free-text contacts and rolls back', function () {
    $migration = require database_path('migrations/2026_10_07_084302_split_contact_into_phone_and_email_on_leads_table.php');
    $phone = Lead::factory()->create(['phone' => '081211110001', 'email' => null]);
    $email = Lead::factory()->create(['phone' => null, 'email' => 'rina@gmail.com']);
    $handle = Lead::factory()->create(['phone' => null, 'email' => null, 'notes' => 'Suka warna putih']);

    $migration->down();
    expect(Schema::hasColumn('leads', 'contact'))->toBeTrue()
        ->and(DB::table('leads')->where('id', $phone->id)->value('contact'))->toBe('081211110001');

    DB::table('leads')->where('id', $phone->id)->update(['contact' => '+62 812-1111-0001']);
    DB::table('leads')->where('id', $email->id)->update(['contact' => 'Rina@Gmail.com']);
    DB::table('leads')->where('id', $handle->id)->update(['contact' => '@rina.home']);

    $migration->up();
    expect(Schema::hasColumn('leads', 'contact'))->toBeFalse()
        ->and($phone->fresh()->phone)->toBe('081211110001')
        ->and($email->fresh()->email)->toBe('rina@gmail.com')
        ->and($handle->fresh()->phone)->toBeNull()
        ->and($handle->fresh()->notes)->toBe("Kontak lama: @rina.home\nSuka warna putih");
});
