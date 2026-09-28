<?php

use App\Enums\FinanceTransactionType;
use App\Enums\OvertimeStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\OvertimeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the overtime index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('overtime.index'))->assertOk();
})->with(['CEO', 'PM', 'FINANCE', 'FIELD_STAFF']);

test('roles without access are forbidden from the overtime index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('overtime.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS']);

test('field staff can submit an overtime request, with the total computed server-side', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $project = Project::factory()->create();

    $this->actingAs($staff)->post(route('overtime.store'), [
        'project_id' => $project->id,
        'hours' => 3,
        'rate_per_hour' => 30000,
        'work_date' => now()->toDateString(),
        'reason' => 'Kejar deadline instalasi',
    ])->assertRedirect();

    $overtime = OvertimeRequest::where('staff_id', $staff->id)->first();
    expect($overtime)->not->toBeNull()
        ->and((float) $overtime->total_amount)->toBe(90000.0)
        ->and($overtime->status)->toBe(OvertimeStatus::Pending);
});

test('overtime service refuses a work_date in the future', function () {
    $staff = User::factory()->create();

    expect(fn () => app(OvertimeService::class)->create([
        'project_id' => Project::factory()->create()->id,
        'hours' => 2,
        'rate_per_hour' => 25000,
        'work_date' => now()->addDay()->toDateString(),
        'reason' => 'Test',
    ], $staff))->toThrow(ValidationException::class);
});

test('roles other than FIELD_STAFF cannot submit an overtime request', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create();

    $this->actingAs($pm)->post(route('overtime.store'), [
        'project_id' => $project->id,
        'hours' => 2,
        'rate_per_hour' => 25000,
        'work_date' => now()->toDateString(),
        'reason' => 'Test',
    ])->assertForbidden();
});

test('PM can approve a pending overtime request, advancing it to PENDING_FINANCE', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending->value]);

    $this->actingAs($pm)->post(route('overtime.pmApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
    ])->assertRedirect();

    $overtime->refresh();
    expect($overtime->status)->toBe(OvertimeStatus::PendingFinance)
        ->and($overtime->pm_approved_by)->toBe($pm->id)
        ->and($overtime->pm_approved_at)->not->toBeNull();
});

test('PM rejecting requires a note', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending->value]);

    $this->actingAs($pm)->post(route('overtime.pmReject', ['overtime_request' => $overtime->id]), [
        'decision' => 'reject',
    ])->assertSessionHasErrors('note');

    $this->actingAs($pm)->post(route('overtime.pmReject', ['overtime_request' => $overtime->id]), [
        'decision' => 'reject',
        'note' => 'Tidak ada bukti lembur.',
    ])->assertRedirect();

    expect($overtime->fresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and($overtime->fresh()->reject_note)->toBe('Tidak ada bukti lembur.');
});

test('Finance cannot decide before PM has approved', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending->value]);

    $this->actingAs($finance)->post(route('overtime.financeApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors('status');
});

test('Finance approving after PM marks it APPROVED_FINANCE and records a FinanceTransaction expense', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $overtime = OvertimeRequest::factory()->create([
        'status' => OvertimeStatus::PendingFinance->value,
        'total_amount' => 90000,
    ]);

    $this->actingAs($finance)->post(route('overtime.financeApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertRedirect();

    $overtime->refresh();
    expect($overtime->status)->toBe(OvertimeStatus::ApprovedFinance)
        ->and($overtime->finance_approved_by)->toBe($finance->id);

    $transaction = FinanceTransaction::where('reference_id', $overtime->id)->where('kategori', 'LEMBUR_BONUS')->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and((float) $transaction->amount)->toBe(90000.0)
        ->and($transaction->created_by)->toBe($finance->id)
        ->and($transaction->bank_account_id)->not->toBeNull();
});

test('Finance approval without a bank account is rejected and writes nothing', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::PendingFinance->value]);

    $this->actingAs($finance)->post(route('overtime.financeApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
    ])->assertSessionHasErrors('bank_account_id');

    // Rejecting releases no money, so it needs no account.
    $this->actingAs($finance)->post(route('overtime.financeReject', ['overtime_request' => $overtime->id]), [
        'decision' => 'reject',
        'note' => 'Tidak sesuai jadwal.',
    ])->assertSessionHasNoErrors();

    expect(FinanceTransaction::where('reference_id', $overtime->id)->exists())->toBeFalse();
});

test('Finance rejecting after PM approval does not create a FinanceTransaction', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::PendingFinance->value]);

    $this->actingAs($finance)->post(route('overtime.financeReject', ['overtime_request' => $overtime->id]), [
        'decision' => 'reject',
        'note' => 'Anggaran lembur bulan ini sudah habis.',
    ])->assertRedirect();

    expect($overtime->fresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and(FinanceTransaction::where('reference_id', $overtime->id)->exists())->toBeFalse();
});

test('roles other than PM cannot make PM overtime decisions', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending->value]);

    $this->actingAs($finance)->post(route('overtime.pmApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
    ])->assertForbidden();
});

test('roles other than FINANCE cannot make finance overtime decisions', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::PendingFinance->value]);

    $this->actingAs($pm)->post(route('overtime.financeApprove', ['overtime_request' => $overtime->id]), [
        'decision' => 'approve',
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertForbidden();
});

test('the Sprint 8 migration renames legacy APPROVED_PM rows to PENDING_FINANCE and back', function () {
    $migration = require database_path('migrations/2026_09_28_083658_rename_approved_pm_to_pending_finance_in_overtime_requests.php');
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending->value]);
    DB::table('overtime_requests')->where('id', $overtime->id)->update(['status' => 'APPROVED_PM']);

    $migration->up();
    expect(DB::table('overtime_requests')->where('id', $overtime->id)->value('status'))->toBe('PENDING_FINANCE');

    $migration->down();
    expect(DB::table('overtime_requests')->where('id', $overtime->id)->value('status'))->toBe('APPROVED_PM');
});
