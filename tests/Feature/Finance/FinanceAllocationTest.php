<?php

use App\Models\AuditLog;
use App\Models\FinanceAllocationConfig;
use App\Models\FinanceTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceAllocationService;
use Database\Seeders\FinanceAllocationConfigSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function allocationUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('CEO and Finance can view the allocation config page', function (string $role) {
    $this->seed(FinanceAllocationConfigSeeder::class);

    $this->actingAs(allocationUser($role))
        ->get(route('finance.allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Allocations/Index')
            ->has('allocations', 9)
            ->where('activeTotal', 21));
})->with(['CEO', 'FINANCE']);

test('roles other than CEO and Finance cannot view or change allocations', function (string $role) {
    $user = allocationUser($role);
    $config = FinanceAllocationConfig::factory()->create();

    $this->actingAs($user)->get(route('finance.allocations.index'))->assertForbidden();
    $this->actingAs($user)->post(route('finance.allocations.store'), [
        'label' => 'Baru', 'percentage' => 1, 'kategori' => 'OPERASIONAL',
    ])->assertForbidden();
    $this->actingAs($user)->put(route('finance.allocations.update', ['allocation' => $config->id]), [
        'label' => $config->label, 'percentage' => 5, 'kategori' => 'OPERASIONAL',
    ])->assertForbidden();
})->with(['PM', 'MARKETING', 'FIELD_STAFF', 'LOGISTICS']);

test('Finance can add and edit an allocation, and each change is audited', function () {
    $finance = allocationUser('FINANCE');

    $this->actingAs($finance)->post(route('finance.allocations.store'), [
        'label' => 'Marketing', 'percentage' => 2.5, 'kategori' => 'OPERASIONAL',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $config = FinanceAllocationConfig::where('label', 'Marketing')->firstOrFail();

    $this->actingAs($finance)->put(route('finance.allocations.update', ['allocation' => $config->id]), [
        'label' => 'Marketing', 'percentage' => 3, 'kategori' => 'OPERASIONAL', 'is_active' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($config->fresh())
        ->percentage->toBe('3.00')
        ->is_active->toBeFalse()
        ->and(AuditLog::where('model_type', 'FinanceAllocationConfig')->pluck('action')->all())
        ->toBe(['finance.allocation_created', 'finance.allocation_updated']);
});

test('active allocations cannot total more than 100 percent', function () {
    $finance = allocationUser('FINANCE');
    FinanceAllocationConfig::factory()->create(['label' => 'Besar', 'percentage' => 95]);

    $this->actingAs($finance)->post(route('finance.allocations.store'), [
        'label' => 'Kelebihan', 'percentage' => 6, 'kategori' => 'OPERASIONAL',
    ])->assertSessionHasErrors('percentage');

    expect(FinanceAllocationConfig::where('label', 'Kelebihan')->exists())->toBeFalse();

    // An inactive row doesn't count toward the limit.
    $this->actingAs($finance)->post(route('finance.allocations.store'), [
        'label' => 'Cadangan', 'percentage' => 50, 'kategori' => 'OPERASIONAL', 'is_active' => false,
    ])->assertSessionHasNoErrors();
});

test('validation rejects a duplicate label, a non-positive percentage and an unknown category', function () {
    $finance = allocationUser('FINANCE');
    FinanceAllocationConfig::factory()->create(['label' => 'Gaji']);

    $this->actingAs($finance)->post(route('finance.allocations.store'), [
        'label' => 'Gaji', 'percentage' => 0, 'kategori' => 'BUKAN_KATEGORI',
    ])->assertSessionHasErrors(['label', 'percentage', 'kategori']);
});

test('breakdownFor splits a project contract value by the active percentages without writing transactions', function () {
    $this->seed(FinanceAllocationConfigSeeder::class);
    FinanceAllocationConfig::where('label', 'Bonus')->update(['is_active' => false]);
    $project = Project::factory()->create(['contract_value' => 200_000_000]);

    $lines = app(FinanceAllocationService::class)->breakdownFor($project);

    expect($lines)->toHaveCount(8)
        ->and($lines[0])->toMatchArray(['label' => 'Gaji', 'kategori' => 'GAJI_KARYAWAN', 'percentage' => 12.0, 'amount' => 24_000_000.0])
        ->and(collect($lines)->pluck('label'))->not->toContain('Bonus')
        ->and(FinanceTransaction::count())->toBe(0);
});

test('the default seeder is idempotent and never overwrites an edited percentage', function () {
    $this->seed(FinanceAllocationConfigSeeder::class);
    FinanceAllocationConfig::where('label', 'Gaji')->update(['percentage' => 10]);

    $this->seed(FinanceAllocationConfigSeeder::class);

    expect(FinanceAllocationConfig::count())->toBe(9)
        ->and(FinanceAllocationConfig::where('label', 'Gaji')->value('percentage'))->toBe('10.00');
});
