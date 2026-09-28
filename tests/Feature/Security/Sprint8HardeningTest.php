<?php

/**
 * Regression tests for the Sprint 8 security review findings
 * (.claude/plan/sprint-08.md, Fase 3).
 */

use App\Enums\OvertimeStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\OvertimeRequest;
use App\Models\SupplierDebt;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Services\OvertimeService;
use App\Services\StaffLoanService;
use App\Services\StaffPaymentService;
use App\Services\TerminService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function hardeningUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

test('#1 a termin without a receiving bank account cannot be marked paid', function () {
    $termin = Termin::factory()->create(['bank_account_id' => null]);

    expect(fn () => app(TerminService::class)->markPaid($termin, hardeningUser('FINANCE')))
        ->toThrow(ValidationException::class);

    expect(FinanceTransaction::count())->toBe(0)
        ->and($termin->fresh()->status)->not->toBe(TerminStatus::Paid);
});

test('#1 an inactive receiving bank account is rejected too', function () {
    $termin = Termin::factory()->create(['bank_account_id' => BankAccount::factory()->create(['is_active' => false])->id]);

    expect(fn () => app(TerminService::class)->markPaid($termin, hardeningUser('FINANCE')))
        ->toThrow(ValidationException::class);
});

test('#2 termins paid before the partial-payment migration get pelunasan = amount', function () {
    $migration = require database_path('migrations/2026_09_28_083515_add_dp_amount_pelunasan_sisa_piutang_to_termins_table.php');
    $paid = Termin::factory()->create(['status' => TerminStatus::Paid->value, 'amount' => 5_000_000]);
    $open = Termin::factory()->create(['status' => TerminStatus::Scheduled->value, 'amount' => 3_000_000]);

    $migration->down();
    $migration->up();

    expect(DB::table('termins')->where('id', $paid->id)->first())
        ->pelunasan->toEqual(5_000_000)
        ->sisa_piutang->toEqual(0)
        ->and(DB::table('termins')->where('id', $open->id)->value('sisa_piutang'))->toEqual(3_000_000);
});

test('#3 a second Finance approval of the same overtime is rejected and books no second expense', function () {
    $finance = hardeningUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $overtime = OvertimeRequest::factory()->create(['status' => OvertimeStatus::PendingFinance->value]);
    $stale = $overtime->replicate()->setRawAttributes($overtime->getAttributes()); // same in-memory state as a concurrent request

    app(OvertimeService::class)->financeDecision($overtime, 'approve', $finance, null, $bank->id);

    expect(fn () => app(OvertimeService::class)->financeDecision($stale, 'approve', $finance, null, $bank->id))
        ->toThrow(ValidationException::class);

    expect(FinanceTransaction::where('reference_id', $overtime->id)->where('kategori', 'LEMBUR_BONUS')->count())->toBe(1);
});

test('#4 a task moved out of DONE is re-checked under the lock', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 100_000]);
    $stale = Task::find($task->id);
    Task::whereKey($task->id)->update(['status' => TaskStatus::OnProgress->value]);

    expect(fn () => app(StaffPaymentService::class)->pay($stale, BankAccount::factory()->create()->id, hardeningUser('FINANCE')))
        ->toThrow(ValidationException::class);

    expect(FinanceTransaction::count())->toBe(0);
});

test('#6 a supplier debt payment cannot be future-dated', function () {
    $debt = SupplierDebt::factory()->create(['total_amount' => 1_000_000]);

    $this->actingAs(hardeningUser('FINANCE'))->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 100_000,
        'paid_date' => now()->addDay()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors('paid_date');
});

test('#8 renaming a lead source updates the legacy string on its leads', function () {
    $lead = Lead::factory()->create();
    $source = $lead->leadSource;

    $source->update(['name' => 'Instagram Ads']);

    expect($lead->fresh()->source)->toBe('Instagram Ads');
});

test('#8 a lead source still used by leads cannot be deleted', function () {
    $lead = Lead::factory()->create();

    $this->actingAs(hardeningUser('SUPERADMIN'))
        ->delete(route('master-data.lead-sources.destroy', ['lead_source' => $lead->lead_source_id]))
        ->assertSessionHasErrors('name');

    expect(LeadSource::whereKey($lead->lead_source_id)->exists())->toBeTrue();
});

test('the wage preview does not show the same installment twice for one staff member', function () {
    $finance = hardeningUser('FINANCE');
    $staff = hardeningUser('FIELD_STAFF');
    app(StaffLoanService::class)->create([
        'staff_id' => $staff->id,
        'amount' => 300_000,
        'installment_amount' => 200_000,
        'bank_account_id' => BankAccount::factory()->create()->id,
    ], $finance);
    $first = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 500_000]);
    $second = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 500_000]);

    $reserved = [];
    $service = app(StaffPaymentService::class);

    expect($service->preview($first, $reserved)['deduction'])->toBe(200_000.0)
        ->and($service->preview($second, $reserved)['deduction'])->toBe(100_000.0); // only what's left of the loan
});

test('a deactivated field staff member cannot receive a new loan', function () {
    $staff = hardeningUser('FIELD_STAFF', ['is_active' => false]);

    $this->actingAs(hardeningUser('FINANCE'))->post(route('finance.staffLoans.store'), [
        'staff_id' => $staff->id,
        'amount' => 500_000,
        'installment_amount' => 100_000,
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors('staff_id');
});
