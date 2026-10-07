<?php

use App\Models\City;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 16 Sub 08 — Master Kota: a lead's city is a `cities` row picked
 * from a dropdown, maintained by the SUPERADMIN in Data Master.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole('SUPERADMIN');
    $this->marketing = User::factory()->create();
    $this->marketing->assignRole('MARKETING');
});

test('superadmin can add, rename and delete an unused city', function () {
    $this->actingAs($this->superadmin)
        ->post(route('master-data.cities.store'), ['name' => '  Batu   Bara ', 'province' => 'Sumatera Utara'])
        ->assertRedirect();

    $city = City::firstWhere('name', 'Batu Bara');
    expect($city)->not->toBeNull()->and($city->province)->toBe('Sumatera Utara');

    $this->actingAs($this->superadmin)
        ->put(route('master-data.cities.update', $city), ['name' => 'Batubara', 'province' => ''])
        ->assertRedirect();
    expect($city->fresh()->name)->toBe('Batubara')->and($city->fresh()->province)->toBeNull();

    $this->actingAs($this->superadmin)->delete(route('master-data.cities.destroy', $city))->assertRedirect();
    expect(City::count())->toBe(0);
});

test('a duplicate city name is rejected', function () {
    City::create(['name' => 'Pekanbaru', 'province' => 'Riau']);

    $this->actingAs($this->superadmin)
        ->post(route('master-data.cities.store'), ['name' => 'Pekanbaru'])
        ->assertSessionHasErrors('name');
});

test('a city used by a lead cannot be deleted', function () {
    $city = City::create(['name' => 'Dumai', 'province' => 'Riau']);
    Lead::factory()->create(['city_id' => $city->id]);

    $this->actingAs($this->superadmin)
        ->delete(route('master-data.cities.destroy', $city))
        ->assertSessionHasErrors('name');

    expect(City::whereKey($city->id)->exists())->toBeTrue();
});

test('other roles cannot manage cities', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $city = City::create(['name' => 'Siak', 'province' => 'Riau']);

    $this->actingAs($user)->post(route('master-data.cities.store'), ['name' => 'Kampar'])->assertForbidden();
    $this->actingAs($user)->put(route('master-data.cities.update', $city), ['name' => 'Siak Baru'])->assertForbidden();
    $this->actingAs($user)->delete(route('master-data.cities.destroy', $city))->assertForbidden();
})->with(['CEO', 'MARKETING', 'PM']);

test('a lead stores its city as a master id and rejects unknown ids', function () {
    $city = City::create(['name' => 'Kampar', 'province' => 'Riau']);
    $payload = [
        'client_name' => 'Budi',
        'phone' => '081234567890',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'WARM',
        'assigned_to' => $this->marketing->id,
    ];

    $this->actingAs($this->marketing)
        ->post(route('crm.leads.store'), [...$payload, 'city_id' => 999])
        ->assertSessionHasErrors('city_id');

    $this->actingAs($this->marketing)
        ->post(route('crm.leads.store'), [...$payload, 'city_id' => $city->id])
        ->assertSessionHasNoErrors();

    expect(Lead::first()->city_id)->toBe($city->id);
});

test('the lead form gets the city list, home city first', function () {
    $this->seed(CitySeeder::class);

    $this->actingAs($this->marketing)->get(route('crm.leads.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cities', count(City::DEFAULTS))
            ->where('cities.0.name', 'Pekanbaru'));
});

test('a lead outside the home city defaults its survey to outside Pekanbaru', function (string $cityName, bool $outside) {
    $lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'city_id' => City::idFor($cityName)]);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $lead))
        ->assertInertia(fn (Assert $page) => $page->where('lead.is_outside_home_city', $outside));
})->with([['Dumai', true], ['Pekanbaru', false]]);

test('the city migration maps old free-text cities onto the master and rolls back', function () {
    $migration = require database_path('migrations/2026_10_07_084919_create_cities_and_move_lead_city_to_city_id.php');
    $a = Lead::factory()->create();
    $b = Lead::factory()->create();
    $c = Lead::factory()->create();
    $d = Lead::factory()->create();

    $migration->down();
    expect(Schema::hasTable('cities'))->toBeFalse()->and(Schema::hasColumn('leads', 'city'))->toBeTrue();

    DB::table('leads')->where('id', $a->id)->update(['city' => 'PKU']);
    DB::table('leads')->where('id', $b->id)->update(['city' => 'pekanbaru']);
    DB::table('leads')->where('id', $c->id)->update(['city' => 'Bangkinang']);
    DB::table('leads')->where('id', $d->id)->update(['city' => 'batu bara']);

    $migration->up();
    $pekanbaru = City::firstWhere('name', 'Pekanbaru');

    expect(Schema::hasColumn('leads', 'city'))->toBeFalse()
        ->and($a->fresh()->city_id)->toBe($pekanbaru->id)
        ->and($b->fresh()->city_id)->toBe($pekanbaru->id)
        ->and($c->fresh()->city->name)->toBe('Kampar')
        ->and($d->fresh()->city->name)->toBe('Batu Bara')
        ->and($d->fresh()->city->province)->toBeNull();

    $migration->down();
    expect(DB::table('leads')->where('id', $a->id)->value('city'))->toBe('Pekanbaru');
    $migration->up();
});
