<?php

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\User;
use App\Services\DesignService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PRD §4.2 "PIC & Sub-Staff … dengan role masing-masing" — the
 * `design_staff` pivot, written through UpdateDesignRequest's `staff` list.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

function staffDesigner(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('DESIGNER');

    return $user;
}

function briefPayload(Design $design, array $overrides = []): array
{
    return [
        'pic_id' => $design->pic_id,
        'status' => $design->status->value,
        ...$overrides,
    ];
}

test('designer can set the sub-staff team with role notes', function () {
    $pic = staffDesigner();
    $lika = staffDesigner(['name' => 'Lika']);
    $yuna = staffDesigner(['name' => 'Yuna']);
    $design = Design::factory()->create(['pic_id' => $pic->id]);

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'staff' => [
            ['user_id' => $lika->id, 'role_note' => '3D modeling'],
            ['user_id' => $yuna->id, 'role_note' => null],
        ],
    ]))->assertSessionHasNoErrors();

    $staff = $design->staff()->orderBy('users.id')->get();
    expect($staff->pluck('id')->all())->toBe([$lika->id, $yuna->id])
        ->and($staff[0]->pivot->role_note)->toBe('3D modeling')
        ->and($staff[1]->pivot->role_note)->toBeNull();
});

test('saving the list again replaces it — removed members leave, role notes update', function () {
    $pic = staffDesigner();
    $lika = staffDesigner();
    $yuna = staffDesigner();
    $design = Design::factory()->create(['pic_id' => $pic->id]);
    $design->staff()->attach([$lika->id => ['role_note' => 'Render'], $yuna->id => ['role_note' => 'Gambar RAB']]);

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'staff' => [['user_id' => $lika->id, 'role_note' => '3D modeling']],
    ]))->assertSessionHasNoErrors();

    $staff = $design->staff()->get();
    expect($staff->pluck('id')->all())->toBe([$lika->id])
        ->and($staff[0]->pivot->role_note)->toBe('3D modeling');

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, ['staff' => []]))
        ->assertSessionHasNoErrors();

    expect($design->staff()->count())->toBe(0);
});

test('omitting the staff list leaves the team untouched', function () {
    $pic = staffDesigner();
    $lika = staffDesigner();
    $design = Design::factory()->create(['pic_id' => $pic->id]);
    $design->staff()->attach($lika->id, ['role_note' => 'Render']);

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'brief_note' => 'Update brief saja.',
    ]))->assertSessionHasNoErrors();

    expect($design->staff()->pluck('users.id')->all())->toBe([$lika->id]);
});

test('sub-staff must be distinct, active designers other than the PIC', function (Closure $member, string $expectedError) {
    $pic = staffDesigner();
    $design = Design::factory()->create(['pic_id' => $pic->id]);

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'staff' => $member($pic),
    ]))->assertSessionHasErrors($expectedError);

    expect($design->staff()->count())->toBe(0);
})->with([
    'the PIC' => [fn (User $pic) => [['user_id' => $pic->id, 'role_note' => null]], 'staff.0.user_id'],
    'not a designer' => [function () {
        $pm = User::factory()->create();
        $pm->assignRole('PM');

        return [['user_id' => $pm->id, 'role_note' => null]];
    }, 'staff.0.user_id'],
    'an inactive designer' => [fn () => [['user_id' => staffDesigner(['is_active' => false])->id, 'role_note' => null]], 'staff.0.user_id'],
    'a duplicate' => [function () {
        $lika = staffDesigner();

        return [['user_id' => $lika->id, 'role_note' => 'A'], ['user_id' => $lika->id, 'role_note' => 'B']];
    }, 'staff.1.user_id'],
    'a missing user' => [fn () => [['user_id' => null, 'role_note' => 'Render']], 'staff.0.user_id'],
    'a too-long role note' => [fn () => [['user_id' => staffDesigner()->id, 'role_note' => str_repeat('x', 101)]], 'staff.0.role_note'],
]);

test('a member deactivated after joining can stay on the team', function () {
    $pic = staffDesigner();
    $lika = staffDesigner();
    $design = Design::factory()->create(['pic_id' => $pic->id]);
    $design->staff()->attach($lika->id, ['role_note' => 'Render']);
    $lika->update(['is_active' => false]);

    $this->actingAs($pic)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'staff' => [['user_id' => $lika->id, 'role_note' => 'Render']],
    ]))->assertSessionHasNoErrors();

    expect($design->staff()->pluck('users.id')->all())->toBe([$lika->id]);
});

test('promoting a sub-staff member to PIC takes them off the team', function () {
    $pic = staffDesigner();
    $lika = staffDesigner();
    $design = Design::factory()->create(['pic_id' => $pic->id]);
    $design->staff()->attach($lika->id, ['role_note' => 'Render']);

    // Service-level (e.g. a caller that doesn't send the list at all).
    app(DesignService::class)->update($design, ['pic_id' => $lika->id, 'status' => DesignStatus::Brief->value]);

    expect($design->fresh()->pic_id)->toBe($lika->id)
        ->and($design->staff()->count())->toBe(0);
});

test('roles other than DESIGNER cannot change the sub-staff team', function () {
    $pic = staffDesigner();
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $design = Design::factory()->create(['pic_id' => $pic->id]);

    $this->actingAs($marketing)->put(route('design.update', ['design' => $design->id]), briefPayload($design, [
        'staff' => [['user_id' => staffDesigner()->id, 'role_note' => null]],
    ]))->assertForbidden();

    expect($design->staff()->count())->toBe(0);
});

test('design index and detail show the sub-staff', function () {
    $pic = staffDesigner();
    $lika = staffDesigner(['name' => 'Lika']);
    $design = Design::factory()->create(['pic_id' => $pic->id]);
    $design->staff()->attach($lika->id, ['role_note' => '3D modeling']);

    $this->actingAs($pic)->get(route('design.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Design/Index')
            ->where('designs.data.0.staff.0.name', 'Lika')
            ->where('designs.data.0.staff.0.pivot.role_note', '3D modeling'));

    $this->actingAs($pic)->get(route('design.show', ['design' => $design->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Design/Show')
            ->where('design.staff.0.id', $lika->id)
            ->where('design.staff.0.pivot.role_note', '3D modeling')
            ->has('designers.0.is_active'));
});
