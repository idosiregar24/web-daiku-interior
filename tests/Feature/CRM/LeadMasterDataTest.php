<?php

use App\Models\Lead;
use App\Models\LeadCategory;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * `leads.lead_source_id` / `leads.lead_category_id` → Data Master FKs,
 * with the legacy `source` / `category` strings kept in sync.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = User::factory()->create();
    $this->marketing->assignRole('MARKETING');
});

function leadPayload(array $overrides = []): array
{
    return [
        'client_name' => 'Budi Santoso',
        'contact' => '0812-3456-7890',
        'priority' => 'HOT',
        ...$overrides,
    ];
}

test('creating a lead stores the master FKs and syncs the legacy strings', function () {
    $source = LeadSource::create(['name' => 'Pameran']);
    $category = LeadCategory::create(['name' => 'HOTEL']);

    $this->actingAs($this->marketing)->post(route('crm.leads.store'), leadPayload([
        'lead_source_id' => $source->id,
        'lead_category_id' => $category->id,
        'assigned_to' => $this->marketing->id,
    ]))->assertRedirect(route('crm.leads.index'));

    $lead = Lead::sole();
    expect($lead->lead_source_id)->toBe($source->id)
        ->and($lead->source)->toBe('Pameran')
        ->and($lead->lead_category_id)->toBe($category->id)
        ->and($lead->category)->toBe('HOTEL');
});

test('updating a lead re-syncs the legacy strings and can clear the category', function () {
    $lead = Lead::factory()->create(['source' => 'Instagram', 'category' => 'RESIDENTIAL']);
    $newSource = LeadSource::create(['name' => 'Pameran']);

    $this->actingAs($this->marketing)->put(route('crm.leads.update', ['lead' => $lead->id]), leadPayload([
        'lead_source_id' => $newSource->id,
        'lead_category_id' => null,
        'assigned_to' => $lead->assigned_to,
    ]))->assertRedirect();

    $lead->refresh();
    expect($lead->lead_source_id)->toBe($newSource->id)
        ->and($lead->source)->toBe('Pameran')
        ->and($lead->lead_category_id)->toBeNull()
        ->and($lead->category)->toBeNull();
});

test('lead source is required and both FKs must exist in the master tables', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.store'), leadPayload([
        'lead_source_id' => 999_999,
        'lead_category_id' => 999_999,
        'assigned_to' => $this->marketing->id,
    ]))->assertSessionHasErrors(['lead_source_id', 'lead_category_id']);

    // The legacy free string is no longer accepted in place of the FK.
    $this->actingAs($this->marketing)->post(route('crm.leads.store'), leadPayload([
        'source' => 'Instagram',
        'assigned_to' => $this->marketing->id,
    ]))->assertSessionHasErrors(['lead_source_id' => 'Sumber lead wajib dipilih.']);

    expect(Lead::count())->toBe(0);
});

test('lead service tolerates legacy string input by resolving master rows case-insensitively', function () {
    $existing = LeadSource::create(['name' => 'Instagram']);

    $lead = app(LeadService::class)->create([
        'client_name' => 'Siti',
        'contact' => '0812',
        'source' => 'instagram',
        'priority' => 'WARM',
        'category' => 'Villa',
        'assigned_to' => $this->marketing->id,
    ], $this->marketing);

    expect($lead->lead_source_id)->toBe($existing->id)
        ->and($lead->source)->toBe('Instagram')
        ->and(LeadCategory::where('name', 'Villa')->exists())->toBeTrue()
        ->and($lead->lead_category_id)->toBe(LeadCategory::where('name', 'Villa')->value('id'))
        ->and(LeadSource::count())->toBe(1);
});

test('lead index eager-loads master rows and filters by source and category ids', function () {
    $instagram = LeadSource::findOrCreateByName('Instagram');
    $residential = LeadCategory::findOrCreateByName('RESIDENTIAL');
    Lead::factory()->create(['lead_source_id' => $instagram->id, 'lead_category_id' => $residential->id]);
    Lead::factory()->create(['source' => 'Website', 'category' => 'KOMERSIAL']);

    $this->actingAs($this->marketing)
        ->get(route('crm.leads.index', ['lead_source_id' => $instagram->id, 'lead_category_id' => $residential->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.lead_source.name', 'Instagram')
            ->where('leads.data.0.lead_category.name', 'RESIDENTIAL')
            ->where('leads.data.0.source', 'Instagram')
            ->has('leadCategories')
        );
});

test('backfill migration links existing strings to master rows and creates missing ones', function () {
    $migration = require database_path('migrations/2026_09_28_083541_add_lead_source_id_and_lead_category_id_to_leads_table.php');

    $matched = Lead::factory()->create();
    $unmatched = Lead::factory()->create();
    $noCategory = Lead::factory()->create();

    // Roll the FK columns back so the leads look like pre-migration rows.
    $migration->down();

    LeadSource::query()->delete();
    LeadCategory::query()->delete();
    $instagram = LeadSource::create(['name' => 'Instagram']);
    $residential = LeadCategory::create(['name' => 'RESIDENTIAL']);

    DB::table('leads')->where('id', $matched->id)->update(['source' => 'instagram', 'category' => 'Residential']);
    DB::table('leads')->where('id', $unmatched->id)->update(['source' => 'Pameran Rumah', 'category' => 'HOTEL']);
    DB::table('leads')->where('id', $noCategory->id)->update(['source' => 'Instagram', 'category' => null]);

    $migration->up();

    $pameran = LeadSource::where('name', 'Pameran Rumah')->first();
    $hotel = LeadCategory::where('name', 'HOTEL')->first();

    expect($pameran)->not->toBeNull()
        ->and($hotel)->not->toBeNull()
        ->and(LeadSource::count())->toBe(2)
        ->and(LeadCategory::count())->toBe(2);

    $row = fn (Lead $lead) => DB::table('leads')->where('id', $lead->id)->first();

    expect($row($matched)->lead_source_id)->toBe($instagram->id)
        ->and($row($matched)->lead_category_id)->toBe($residential->id)
        ->and($row($unmatched)->lead_source_id)->toBe($pameran->id)
        ->and($row($unmatched)->lead_category_id)->toBe($hotel->id)
        ->and($row($noCategory)->lead_source_id)->toBe($instagram->id)
        ->and($row($noCategory)->lead_category_id)->toBeNull()
        // Legacy strings are left untouched by the backfill.
        ->and($row($matched)->source)->toBe('instagram');
});
