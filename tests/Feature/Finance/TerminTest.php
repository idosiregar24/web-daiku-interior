<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\TerminStatus;
use App\Jobs\TerminOverdueJob;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use App\Services\TerminService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the Finance termin index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.termins.index'))->assertOk();
})->with(['CEO', 'FINANCE']);

test('PM cannot view the global Finance termin index but can schedule one on their project', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    $this->actingAs($pm)->get(route('finance.termins.index'))->assertForbidden();

    $project = Project::factory()->create(['pm_id' => $pm->id, 'contract_value' => 100_000_000]);

    $this->actingAs($pm)->post(route('projects.termins.store', ['project' => $project->id]), [
        'percentage' => 30,
    ])->assertRedirect();

    expect(Termin::where('project_id', $project->id)->exists())->toBeTrue();
});

test('the termin index sends a calendar-month slice alongside the paginated list', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $inMonth = Termin::factory()->create(['scheduled_date' => '2026-08-22']);
    // Clearly outside the week-padded grid (August 2026's grid runs
    // 2026-07-27 through 2026-09-06 once padded to full Senin–Minggu weeks).
    $otherMonth = Termin::factory()->create(['scheduled_date' => '2026-09-15']);

    $this->actingAs($finance)
        ->get(route('finance.termins.index', ['month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarMonth', '2026-08')
            ->has('calendarTermins', 1)
            ->where('calendarTermins.0.id', $inMonth->id)
        );

    expect(Termin::find($otherMonth->id))->not->toBeNull(); // sanity: the other-month row still exists, just excluded from the slice.
});

test('TerminService::getNextSaturday matches the PRD pseudocode', function () {
    $service = app(TerminService::class);

    // 2026-08-17 is a Monday.
    expect($service->getNextSaturday(Carbon::parse('2026-08-17'))->toDateString())->toBe('2026-08-22')
        // A Saturday itself rolls to the *next* Saturday (PRD: dayOfWeek===6 ? 7 : ...).
        ->and($service->getNextSaturday(Carbon::parse('2026-08-22'))->toDateString())->toBe('2026-08-29');
});

test('TerminService refuses to schedule termins totalling more than 100%', function () {
    $project = Project::factory()->create();
    Termin::factory()->create(['project_id' => $project->id, 'percentage' => 70]);

    expect(fn () => app(TerminService::class)->create($project, ['percentage' => 40]))
        ->toThrow(ValidationException::class);
});

test('marking a termin paid records a FinanceTransaction income row', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $project = Project::factory()->create();
    $bank = BankAccount::factory()->create();
    $termin = Termin::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => null,
        'termin_number' => 1,
        'amount' => 30_000_000,
        'bank_account_id' => $bank->id,
    ]);

    $this->actingAs($finance)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))->assertRedirect();

    $termin->refresh();
    expect($termin->status)->toBe(TerminStatus::Paid)
        ->and($termin->paid_at)->not->toBeNull()
        ->and((float) $termin->pelunasan)->toBe(30_000_000.0)
        ->and((float) $termin->sisa_piutang)->toBe(0.0);

    // markPaid() = "pelunasan of the whole remainder", so it is booked as
    // TERMIN (DP payments are DOWN_PAYMENT — see recordPayment tests below).
    $transaction = FinanceTransaction::where('reference_id', $termin->id)->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->kategori)->toBe(FinanceCategory::Termin)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and((float) $transaction->amount)->toBe(30_000_000.0);
});

test('markPaid on a partially paid termin only books the remaining sisa piutang', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create(['amount' => 10_000_000]);
    $service = app(TerminService::class);

    $service->recordPayment($termin, terminPaymentData('DP', 4_000_000), $finance);
    $service->markPaid($termin->fresh(), $finance);

    $termin->refresh();
    expect($termin->status)->toBe(TerminStatus::Paid)
        ->and((float) $termin->pelunasan)->toBe(6_000_000.0)
        ->and(FinanceTransaction::where('reference_id', $termin->id)->pluck('amount')->map(fn ($a) => (float) $a)->all())
        ->toBe([4_000_000.0, 6_000_000.0]);
});

test('Finance can record a DP then a pelunasan until the termin is PAID', function () {
    $finance = terminFinanceUser();
    $bank = BankAccount::factory()->create();
    $termin = Termin::factory()->create(['amount' => 10_000_000, 'termin_number' => 2]);

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('DP', 3_000_000, $bank->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $termin->refresh();
    expect((float) $termin->dp_amount)->toBe(3_000_000.0)
        ->and((float) $termin->sisa_piutang)->toBe(7_000_000.0)
        ->and($termin->status)->toBe(TerminStatus::Scheduled)
        ->and($termin->isPartiallyPaid())->toBeTrue()
        ->and($termin->paid_at)->toBeNull();

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('PELUNASAN', 7_000_000, $bank->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $termin->refresh();
    expect($termin->status)->toBe(TerminStatus::Paid)
        ->and($termin->paid_at)->not->toBeNull()
        ->and((float) $termin->pelunasan)->toBe(7_000_000.0)
        ->and((float) $termin->sisa_piutang)->toBe(0.0)
        ->and($termin->isPartiallyPaid())->toBeFalse();

    $transactions = FinanceTransaction::where('reference_id', $termin->id)->orderBy('id')->get();
    expect($transactions)->toHaveCount(2)
        ->and($transactions->pluck('kategori')->all())->toBe([FinanceCategory::DownPayment, FinanceCategory::Termin])
        ->and($transactions->pluck('type')->all())->toBe([FinanceTransactionType::Income, FinanceTransactionType::Income])
        ->and($transactions->pluck('bank_account_id')->unique()->all())->toBe([$bank->id])
        ->and($transactions->first()->date->toDateString())->toBe(now()->toDateString());

    expect(AuditLog::where('action', 'finance.termin_payment')->where('model_id', $termin->id)->count())->toBe(2)
        ->and(AuditLog::where('action', 'finance.termin_paid')->count())->toBe(1);
});

test('a payment larger than the sisa piutang is rejected', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create(['amount' => 5_000_000]);

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('DP', 5_000_000.01))
        ->assertSessionHasErrors('amount');

    expect((float) $termin->fresh()->dp_amount)->toBe(0.0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('a DP cannot be recorded once pelunasan has been received', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create(['amount' => 5_000_000]);
    app(TerminService::class)->recordPayment($termin, terminPaymentData('PELUNASAN', 1_000_000), $finance);

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('DP', 1_000_000))
        ->assertSessionHasErrors('type');

    expect((float) $termin->fresh()->dp_amount)->toBe(0.0)
        ->and(FinanceTransaction::count())->toBe(1);
});

test('a fully paid termin refuses further payments', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create(['amount' => 5_000_000]);
    app(TerminService::class)->markPaid($termin, $finance);

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('PELUNASAN', 1))
        ->assertSessionHasErrors('status');

    expect(FinanceTransaction::count())->toBe(1);
});

test('recordPayment validates the request shape in Bahasa Indonesia', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create();

    $this->actingAs($finance)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), [
            'type' => 'CICILAN',
            'amount' => 0,
            'paid_date' => now()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors([
            'type' => 'Jenis pembayaran harus DP atau Pelunasan.',
            'amount' => 'Nominal pembayaran harus lebih dari 0.',
            'bank_account_id' => 'Rekening penerima wajib dipilih.',
            'paid_date' => 'Tanggal pembayaran tidak boleh di masa depan.',
        ]);
});

test('CEO, PM and QA cannot record a termin payment', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $termin = Termin::factory()->create();

    $this->actingAs($user)
        ->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), terminPaymentData('DP', 1_000))
        ->assertForbidden();

    expect(FinanceTransaction::count())->toBe(0);
})->with(['CEO', 'PM', 'QA']);

test('a partially paid termin past its date still goes OVERDUE, a fully paid one never does', function () {
    $finance = terminFinanceUser();
    $service = app(TerminService::class);

    $partial = Termin::factory()->create(['amount' => 10_000_000, 'scheduled_date' => now()->subDays(3)]);
    $service->recordPayment($partial, terminPaymentData('DP', 2_000_000), $finance);

    $paid = Termin::factory()->create(['amount' => 10_000_000, 'scheduled_date' => now()->subDays(3)]);
    $service->recordPayment($paid, terminPaymentData('DP', 2_000_000), $finance);
    $service->recordPayment($paid->fresh(), terminPaymentData('PELUNASAN', 8_000_000), $finance);

    dispatch_sync(new TerminOverdueJob);

    expect($partial->fresh()->status)->toBe(TerminStatus::Overdue)
        ->and($paid->fresh()->status)->toBe(TerminStatus::Paid);

    // And the OVERDUE partial termin stays payable.
    $service->recordPayment($partial->fresh(), terminPaymentData('PELUNASAN', 8_000_000), $finance);
    expect($partial->fresh()->status)->toBe(TerminStatus::Paid);
});

test('the Finance termin index sends active bank accounts to FINANCE only', function () {
    BankAccount::factory()->create(['is_active' => true]);
    BankAccount::factory()->create(['is_active' => false]);

    $this->actingAs(terminFinanceUser())
        ->get(route('finance.termins.index'))
        ->assertInertia(fn (Assert $page) => $page->has('bankAccounts', 1));

    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $this->actingAs($ceo)
        ->get(route('finance.termins.index'))
        ->assertInertia(fn (Assert $page) => $page->has('bankAccounts', 0));
});

test('the termin PDF shows DP, pelunasan and sisa tagihan', function () {
    $finance = terminFinanceUser();
    $termin = Termin::factory()->create(['amount' => 10_000_000]);
    app(TerminService::class)->recordPayment($termin, terminPaymentData('DP', 2_500_000), $finance);

    $this->actingAs($finance)->get(route('finance.termins.pdf', ['termin' => $termin->id]))->assertOk();
});

function terminFinanceUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('FINANCE');

    return $user;
}

function terminPaymentData(string $type, float $amount, ?int $bankAccountId = null): array
{
    return [
        'type' => $type,
        'amount' => $amount,
        'bank_account_id' => $bankAccountId ?? BankAccount::factory()->create()->id,
        'paid_date' => now()->toDateString(),
    ];
}

test('CEO and PM cannot mark a termin paid', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $termin = Termin::factory()->create();

    $this->actingAs($user)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))->assertForbidden();
})->with(['CEO', 'PM']);

test('a termin tied to a milestone stays locked until that milestone is COMPLETED', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::QaWaiting->value]);
    $termin = Termin::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id]);

    $this->actingAs($finance)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))
        ->assertSessionHasErrors('status');

    expect($termin->fresh()->status)->toBe(TerminStatus::Scheduled);
});

test('a DP can be received before the linked milestone is COMPLETED, but pelunasan cannot', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::InProgress->value]);
    $termin = Termin::factory()->create([
        'project_id' => $milestone->project_id,
        'milestone_id' => $milestone->id,
        'amount' => 10_000_000,
    ]);

    $this->actingAs($finance)->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), [
        'type' => 'DP',
        'amount' => 3_000_000,
        'bank_account_id' => $termin->bank_account_id,
        'paid_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($finance)->post(route('finance.termins.recordPayment', ['termin' => $termin->id]), [
        'type' => 'PELUNASAN',
        'amount' => 7_000_000,
        'bank_account_id' => $termin->bank_account_id,
        'paid_date' => now()->toDateString(),
    ])->assertSessionHasErrors('status');

    expect($termin->fresh())
        ->dp_amount->toBe('3000000.00')
        ->pelunasan->toBe('0.00')
        ->status->toBe(TerminStatus::Scheduled);
});
