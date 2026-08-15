<?php

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\LeadCategory;
use App\Models\LeadSource;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole('SUPERADMIN');
});

test('non-superadmin roles are forbidden from master data', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('master-data.index'))->assertForbidden();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('superadmin can view master data index', function () {
    $this->actingAs($this->superadmin)->get(route('master-data.index'))->assertOk();
});

test('superadmin can create, update and delete a branch', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.branches.store'), [
        'name' => 'Cabang Jakarta',
        'code' => 'JKT01',
        'address' => 'Jl. Sudirman',
    ])->assertRedirect();

    $branch = Branch::firstWhere('code', 'JKT01');
    expect($branch)->not->toBeNull();

    $this->actingAs($this->superadmin)->put(route('master-data.branches.update', $branch), [
        'name' => 'Cabang Jakarta Pusat',
        'code' => 'JKT01',
        'address' => 'Jl. Sudirman No. 1',
    ])->assertRedirect();

    expect($branch->fresh()->name)->toBe('Cabang Jakarta Pusat');

    $this->actingAs($this->superadmin)->delete(route('master-data.branches.destroy', $branch))->assertRedirect();
    expect(Branch::find($branch->id))->toBeNull();
});

test('branch code must be unique', function () {
    Branch::factory()->create(['code' => 'JKT01']);

    $this->actingAs($this->superadmin)->post(route('master-data.branches.store'), [
        'name' => 'Cabang Lain',
        'code' => 'JKT01',
    ])->assertSessionHasErrors('code');
});

test('superadmin can create a lead source', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.lead-sources.store'), [
        'name' => 'YouTube',
    ])->assertRedirect();

    expect(LeadSource::where('name', 'YouTube')->exists())->toBeTrue();
});

test('superadmin can create a lead category', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.lead-categories.store'), [
        'name' => 'HORECA',
    ])->assertRedirect();

    expect(LeadCategory::where('name', 'HORECA')->exists())->toBeTrue();
});

test('superadmin can create a bank account', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.bank-accounts.store'), [
        'bank_name' => 'BCA',
        'account_no' => '1234567890',
        'label' => 'BCA 7890',
        'balance' => 1_000_000,
        'is_active' => true,
    ])->assertRedirect();

    expect(BankAccount::where('label', 'BCA 7890')->exists())->toBeTrue();
});

test('bank account label must be unique', function () {
    BankAccount::factory()->create(['label' => 'BCA 5835']);

    $this->actingAs($this->superadmin)->post(route('master-data.bank-accounts.store'), [
        'bank_name' => 'BCA',
        'account_no' => '5835',
        'label' => 'BCA 5835',
        'balance' => 0,
    ])->assertSessionHasErrors('label');
});
