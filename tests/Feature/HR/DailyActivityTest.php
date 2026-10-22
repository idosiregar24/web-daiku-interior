<?php

use App\Enums\DailyActivityReviewStatus;
use App\Enums\ProjectStatus;
use App\Models\AuditLog;
use App\Models\DailyActivity;
use App\Models\DailyActivityReview;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\ActionInboxService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// Sprint 29 Sub 13 — Aktivitas Harian & Periksa Aktivitas.

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // Wednesday — the previous working day is Tuesday 2026-10-20.
    Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'Asia/Jakarta'));
});

afterEach(fn () => Carbon::setTestNow());

function dailyActivityUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** An SDM employee with an account of `$role`. */
function activityEmployee(string $role = 'DESIGNER', array $attributes = []): Employee
{
    return Employee::factory()->create(['user_id' => dailyActivityUser($role)->id, ...$attributes]);
}

function writeActivity(User $user, string $date, string $description = 'Menyusun laporan'): DailyActivity
{
    return DailyActivity::create(['user_id' => $user->id, 'date' => $date, 'description' => $description]);
}

function activityGroup(User $user): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', 'activity-review');
}

// ── Aktivitas Harian (own entries) ──────────────────────────────────────

test('every account except a Tukang opens Aktivitas Harian', function (string $role) {
    $this->actingAs(dailyActivityUser($role))->get(route('daily-activities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/Activities/Index')
            ->where('today', '2026-10-21')
            ->where('isWorkDay', true)
            ->has('activities', 0));
})->with(['CEO', 'MARKETING', 'DESIGNER', 'KEPALA_DESAIN', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS', 'HR', 'ADMIN_PROYEK', 'SUPERADMIN']);

test('a Tukang is refused Aktivitas Harian', function () {
    $tukang = dailyActivityUser('FIELD_STAFF');

    $this->actingAs($tukang)->get(route('daily-activities.index'))->assertForbidden();
    $this->actingAs($tukang)->post(route('daily-activities.store'), ['description' => 'Pasang kabinet'])->assertForbidden();
    expect(DailyActivity::count())->toBe(0);
});

test('an employee writes several entries for today, optionally on a running project', function () {
    $user = dailyActivityUser('MARKETING');
    $project = Project::factory()->create();

    $this->actingAs($user)->post(route('daily-activities.store'), ['description' => "Follow up klien A\nKirim penawaran"])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('daily-activities.store'), ['description' => 'Survey lokasi', 'project_id' => $project->id])
        ->assertSessionHasNoErrors();

    $entries = DailyActivity::orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->user_id)->toBe($user->id)
        ->and($entries[0]->date->toDateString())->toBe('2026-10-21')
        ->and($entries[0]->project_id)->toBeNull()
        ->and($entries[1]->project_id)->toBe($project->id);

    $this->actingAs($user)->get(route('daily-activities.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 2)
            ->where('activities.1.project.name', $project->name)
            ->has('projects', 1));
});

test('a finished project or an empty text is refused', function () {
    $user = dailyActivityUser('MARKETING');
    $done = Project::factory()->create(['status' => ProjectStatus::Completed->value]);

    $this->actingAs($user)->post(route('daily-activities.store'), ['description' => 'Survey', 'project_id' => $done->id])
        ->assertSessionHasErrors('project_id');
    $this->actingAs($user)->post(route('daily-activities.store'), ['description' => ''])
        ->assertSessionHasErrors(['description' => 'Aktivitas wajib diisi.']);

    expect(DailyActivity::count())->toBe(0);
});

test('an entry is changed or removed only by its writer and only on the same day', function () {
    $user = dailyActivityUser('FINANCE');
    $today = writeActivity($user, '2026-10-21', 'Rekap kas');
    $yesterday = writeActivity($user, '2026-10-20', 'Rekap bank');
    $someoneElse = writeActivity(dailyActivityUser('FINANCE'), '2026-10-21', 'Bukan milik saya');

    $this->actingAs($user)->put(route('daily-activities.update', $today), ['description' => 'Rekap kas & bank'])
        ->assertSessionHasNoErrors();
    expect($today->fresh()->description)->toBe('Rekap kas & bank');

    $this->actingAs($user)->put(route('daily-activities.update', $yesterday), ['description' => 'Ubah kemarin'])->assertForbidden();
    $this->actingAs($user)->delete(route('daily-activities.destroy', $yesterday))->assertForbidden();
    $this->actingAs($user)->put(route('daily-activities.update', $someoneElse), ['description' => 'Ubah punya orang'])->assertForbidden();
    $this->actingAs($user)->delete(route('daily-activities.destroy', $someoneElse))->assertForbidden();

    $this->actingAs($user)->delete(route('daily-activities.destroy', $today))->assertSessionHasNoErrors();

    expect(DailyActivity::find($today->id))->toBeNull()
        ->and($yesterday->fresh()->description)->toBe('Rekap bank')
        ->and($someoneElse->fresh())->not->toBeNull();
});

test('the history shows every past working day — empty ones too — with HR\'s newest check', function () {
    $employee = activityEmployee('ESTIMATOR');
    writeActivity($employee->user, '2026-10-20', 'Hitung RAB');
    writeActivity($employee->user, '2026-10-18', 'Lembur hari Minggu'); // a holiday with an entry is shown
    $hr = dailyActivityUser('HR');
    DailyActivityReview::create(['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'SESUAI', 'reviewed_by' => $hr->id]);
    DailyActivityReview::create(['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'PERLU_KLARIFIKASI', 'note' => 'RAB yang mana?', 'reviewed_by' => $hr->id]);

    $this->actingAs($employee->user)->get(route('daily-activities.index'))
        ->assertInertia(fn (Assert $page) => $page
            // 14 days back from 2026-10-20: 12 working days + the Sunday with an entry (the other Sunday is skipped).
            ->has('history', 13)
            ->where('history.0.date', '2026-10-20')
            ->where('history.0.review.status', 'PERLU_KLARIFIKASI')
            ->where('history.0.review.note', 'RAB yang mana?')
            ->where('history.1.date', '2026-10-19')
            ->has('history.1.activities', 0)
            ->where('history.1.review', null)
            ->where('history.3.date', '2026-10-17')
            ->where('history.2.date', '2026-10-18')
            ->where('history.2.is_work_day', false));
});

// ── Periksa Aktivitas: RBAC ─────────────────────────────────────────────

test('HR, the CEO and PMs open Periksa Aktivitas; only HR may decide', function (string $role, bool $canReview) {
    $this->actingAs(dailyActivityUser($role))->get(route('daily-activities.review'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/Activities/Review')
            ->where('date', '2026-10-20')
            ->where('canReview', $canReview));
})->with([['HR', true], ['SUPERADMIN', true], ['CEO', false], ['PM', false]]);

test('every other role is refused Periksa Aktivitas', function (string $role) {
    $employee = activityEmployee();
    $user = dailyActivityUser($role);

    $this->actingAs($user)->get(route('daily-activities.review'))->assertForbidden();
    $this->actingAs($user)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'TIDAK_MENGISI'])->assertForbidden();
    $this->actingAs($user)->post(route('daily-activities.review.bulk'), ['date' => '2026-10-20'])->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS', 'ADMIN_PROYEK', 'FIELD_STAFF']);

test('the CEO and PMs read but cannot check', function (string $role) {
    $employee = activityEmployee();
    writeActivity($employee->user, '2026-10-20');

    $this->actingAs(dailyActivityUser($role))->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'SESUAI'])->assertForbidden();
    $this->actingAs(dailyActivityUser($role))->post(route('daily-activities.review.bulk'), ['date' => '2026-10-20'])->assertForbidden();

    expect(DailyActivityReview::count())->toBe(0);
})->with(['CEO', 'PM']);

// ── Periksa Aktivitas: the sheet ────────────────────────────────────────

test('the sheet lists every SDM employee with an active account — their entries or "Tidak mengisi"', function () {
    $filled = activityEmployee('DESIGNER', ['name' => 'Ani']);
    $empty = activityEmployee('FINANCE', ['name' => 'Budi']);
    writeActivity($filled->user, '2026-10-20', 'Gambar kerja dapur');
    writeActivity($filled->user, '2026-10-21', 'Hari ini — belum diperiksa');

    Employee::factory()->create(['name' => 'Tanpa akun']);
    Employee::factory()->inactive()->create(['user_id' => dailyActivityUser('MARKETING')->id]);
    activityEmployee('LOGISTICS', ['name' => 'Baru', 'join_date' => '2026-10-21']);
    $inactiveAccount = activityEmployee('QA', ['name' => 'Akun nonaktif']);
    $inactiveAccount->user->update(['is_active' => false]);

    $this->actingAs(dailyActivityUser('HR'))->get(route('daily-activities.review'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
            ->where('rows.0.name', 'Ani')
            ->has('rows.0.activities', 1)
            ->where('rows.0.activities.0.description', 'Gambar kerja dapur')
            ->where('rows.1.name', 'Budi')
            ->has('rows.1.activities', 0)
            ->where('rows.1.review', null)
            ->where('summary', ['total' => 2, 'filled' => 1, 'empty' => 1, 'unreviewed' => 2]));

    expect($empty->user_id)->not->toBeNull();
});

test('"kemarin" is the previous working day — Saturday when opened on a Monday', function (string $now, string $expected) {
    Carbon::setTestNow(Carbon::parse($now, 'Asia/Jakarta'));

    $this->actingAs(dailyActivityUser('HR'))->get(route('daily-activities.review'))
        ->assertInertia(fn (Assert $page) => $page->where('date', $expected)->where('isWorkDay', true));
})->with([
    'Senin' => ['2026-10-19 08:00', '2026-10-17'],
    'Minggu' => ['2026-10-18 08:00', '2026-10-17'],
    'Selasa' => ['2026-10-20 08:00', '2026-10-19'],
]);

test('a holiday lists only those who wrote something and owes no "Tidak mengisi"', function () {
    $worked = activityEmployee('PM', ['name' => 'Rajin']);
    $rested = activityEmployee('PM', ['name' => 'Libur']);
    writeActivity($worked->user, '2026-10-18', 'Cek lokasi hari Minggu');
    $hr = dailyActivityUser('HR');

    $this->actingAs($hr)->get(route('daily-activities.review', ['date' => '2026-10-18']))
        ->assertInertia(fn (Assert $page) => $page->where('isWorkDay', false)->has('rows', 1)->where('rows.0.name', 'Rajin'));

    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $rested->user_id, 'date' => '2026-10-18', 'status' => 'TIDAK_MENGISI'])
        ->assertSessionHasErrors(['status' => 'Hari libur — yang tidak mengisi tidak perlu diperiksa.']);
    expect(DailyActivityReview::count())->toBe(0);

    // The bulk action skips the one who rested, too.
    $this->actingAs($hr)->post(route('daily-activities.review.bulk'), ['date' => '2026-10-18'])->assertSessionHasNoErrors();
    expect(DailyActivityReview::sole()->user_id)->toBe($worked->user_id);
});

test('today and future dates cannot be opened or checked', function () {
    $employee = activityEmployee();
    writeActivity($employee->user, '2026-10-21');
    $hr = dailyActivityUser('HR');

    $this->actingAs($hr)->get(route('daily-activities.review', ['date' => '2026-10-21']))
        ->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-20'));

    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-21', 'status' => 'SESUAI'])
        ->assertSessionHasErrors(['date' => 'Aktivitas baru bisa diperiksa setelah harinya lewat.']);
});

// ── Periksa Aktivitas: checking ─────────────────────────────────────────

test('HR marks a filled day Sesuai — audited, and the employee is not notified', function () {
    $employee = activityEmployee();
    writeActivity($employee->user, '2026-10-20');
    $hr = dailyActivityUser('HR');

    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'SESUAI'])
        ->assertSessionHasNoErrors();

    $review = DailyActivityReview::sole();
    expect($review->status)->toBe(DailyActivityReviewStatus::Sesuai)
        ->and($review->date->toDateString())->toBe('2026-10-20')
        ->and($review->reviewed_by)->toBe($hr->id);

    $audit = AuditLog::where('action', 'hr.activity_reviewed')->sole();
    expect($audit->user_id)->toBe($hr->id)
        ->and($audit->new_values['status'])->toBe('SESUAI')
        ->and($audit->new_values['employee_id'])->toBe($employee->id);

    expect(Notification::where('user_id', $employee->user_id)->count())->toBe(0);
});

test('the status must fit the day: Sesuai needs entries, Tidak mengisi an empty day, klarifikasi a note', function () {
    $filled = activityEmployee();
    $empty = activityEmployee();
    writeActivity($filled->user, '2026-10-20');
    $hr = dailyActivityUser('HR');
    $post = fn (Employee $employee, array $data) => $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', ...$data]);

    $post($empty, ['status' => 'SESUAI'])->assertSessionHasErrors('status');
    $post($filled, ['status' => 'TIDAK_MENGISI'])->assertSessionHasErrors('status');
    $post($filled, ['status' => 'PERLU_KLARIFIKASI'])->assertSessionHasErrors(['note' => 'Catatan klarifikasi wajib diisi.']);
    expect(DailyActivityReview::count())->toBe(0);

    $post($empty, ['status' => 'TIDAK_MENGISI'])->assertSessionHasNoErrors();
    expect(DailyActivityReview::sole()->status)->toBe(DailyActivityReviewStatus::TidakMengisi);
});

test('a field-staff account or a non-employee is never checked', function () {
    $tukang = dailyActivityUser('FIELD_STAFF');
    $stranger = dailyActivityUser('MARKETING'); // an account without an employee row

    foreach ([$tukang, $stranger] as $user) {
        $this->actingAs(dailyActivityUser('HR'))->post(route('daily-activities.review.store'), ['user_id' => $user->id, 'date' => '2026-10-20', 'status' => 'TIDAK_MENGISI'])
            ->assertSessionHasErrors('user_id');
    }

    expect(DailyActivityReview::count())->toBe(0);
});

test('Perlu klarifikasi notifies the employee, who lands on Aktivitas Harian', function () {
    $employee = activityEmployee('LOGISTICS');
    writeActivity($employee->user, '2026-10-20', 'Cek gudang');

    $this->actingAs(dailyActivityUser('HR'))->post(route('daily-activities.review.store'), [
        'user_id' => $employee->user_id,
        'date' => '2026-10-20',
        'status' => 'PERLU_KLARIFIKASI',
        'note' => 'Gudang mana yang dicek?',
    ])->assertSessionHasNoErrors();

    $notification = Notification::where('user_id', $employee->user_id)->sole();
    expect($notification->type)->toBe('daily_activity_clarification')
        ->and($notification->message)->toContain('Gudang mana yang dicek?')
        ->and($notification->metadata)->toBe(['date' => '2026-10-20']);

    $this->actingAs($employee->user)->get(route('notifications.open', $notification))
        ->assertRedirect(route('daily-activities.index'));
});

test('checks are append-only — checking again adds a row and the newest counts', function () {
    $employee = activityEmployee();
    writeActivity($employee->user, '2026-10-20');
    $hr = dailyActivityUser('HR');

    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'PERLU_KLARIFIKASI', 'note' => 'Detailnya?']);
    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $employee->user_id, 'date' => '2026-10-20', 'status' => 'SESUAI']);

    expect(DailyActivityReview::count())->toBe(2);

    $this->actingAs($hr)->get(route('daily-activities.review'))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.review.status', 'SESUAI')->where('summary.unreviewed', 0));

    $first = DailyActivityReview::orderBy('id')->first();
    expect(fn () => $first->update(['status' => 'SESUAI']))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class)
        ->and(DailyActivityReview::count())->toBe(2);
});

test('"Tandai semua" marks the filled Sesuai, the empty Tidak mengisi, and leaves the checked alone', function () {
    $filled = activityEmployee();
    $empty = activityEmployee();
    $checked = activityEmployee();
    writeActivity($filled->user, '2026-10-20');
    writeActivity($checked->user, '2026-10-20');
    $hr = dailyActivityUser('HR');
    DailyActivityReview::create(['user_id' => $checked->user_id, 'date' => '2026-10-20', 'status' => 'PERLU_KLARIFIKASI', 'note' => 'Kenapa?', 'reviewed_by' => $hr->id]);

    $this->actingAs($hr)->post(route('daily-activities.review.bulk'), ['date' => '2026-10-20'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '1 orang ditandai Sesuai, 1 orang dicatat Tidak mengisi.');

    $latest = fn (Employee $employee) => DailyActivityReview::where('user_id', $employee->user_id)->orderByDesc('id')->first()->status;
    expect($latest($filled))->toBe(DailyActivityReviewStatus::Sesuai)
        ->and($latest($empty))->toBe(DailyActivityReviewStatus::TidakMengisi)
        ->and($latest($checked))->toBe(DailyActivityReviewStatus::PerluKlarifikasi)
        ->and(DailyActivityReview::count())->toBe(3)
        ->and(Notification::count())->toBe(0);
});

// ── Perlu Tindakan ──────────────────────────────────────────────────────

test('HR\'s queue counts yesterday\'s unchecked employees and empties once checked', function () {
    $filled = activityEmployee();
    activityEmployee();
    writeActivity($filled->user, '2026-10-20');
    $hr = dailyActivityUser('HR');

    $group = activityGroup($hr);
    expect($group['count'])->toBe(2)
        ->and($group['label'])->toBe('Aktivitas kemarin belum diperiksa')
        ->and($group['routeName'])->toBe('daily-activities.review')
        ->and(app(ActionInboxService::class)->badges($hr)['daily-activities.review'])->toBe(2);

    $this->actingAs($hr)->post(route('daily-activities.review.store'), ['user_id' => $filled->user_id, 'date' => '2026-10-20', 'status' => 'SESUAI']);
    expect(activityGroup($hr)['count'])->toBe(1);

    $this->actingAs($hr)->post(route('daily-activities.review.bulk'), ['date' => '2026-10-20']);
    expect(activityGroup($hr))->toBeNull();

    // Only HR decides — the CEO and PMs have no such queue.
    expect(activityGroup(dailyActivityUser('CEO')))->toBeNull()
        ->and(activityGroup(dailyActivityUser('PM')))->toBeNull();
});

test('on a Monday the queue is Saturday\'s', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 08:00', 'Asia/Jakarta'));
    activityEmployee();

    $group = activityGroup(dailyActivityUser('HR'));

    expect($group['count'])->toBe(1)
        ->and($group['label'])->toBe('Aktivitas Sabtu, 17 Oktober belum diperiksa')
        ->and($group['href'])->toBe(route('daily-activities.review', ['date' => '2026-10-17']));
});
