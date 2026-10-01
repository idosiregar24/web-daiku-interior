<?php

/**
 * Regression tests for the Sprint 9 security review findings
 * (.claude/plan/sprint-09.md, Fase 3).
 */

use App\Exports\CashFlowExport;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\FundTransfer;
use App\Models\User;
use App\Services\FundTransferService;
use App\Services\PayrollService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sprint9User(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function paySecretSalary(User $finance): void
{
    $employee = Employee::factory()->create(['name' => 'Karyawan Rahasia', 'base_salary' => 7_654_321, 'join_date' => '2024-01-01']);

    app(PayrollService::class)->pay($employee, [
        'period' => now()->format('Y-m'),
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ], $finance);
}

/** Every string cell of the "Transaksi" sheet, for leak/formula assertions. */
function exportedTransactionCells(array $filters): array
{
    $path = tempnam(sys_get_temp_dir(), 'daiku').'.xlsx';
    file_put_contents($path, Excel::raw(new CashFlowExport($filters), ExcelFormat::XLSX));
    $sheet = IOFactory::load($path)->getSheetByName('Transaksi');
    @unlink($path);

    $cells = [];
    foreach ($sheet->getRowIterator(1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $cells[] = $cell;
        }
    }

    return $cells;
}

test('#1 a PM cannot read individual salaries through the transaction list', function () {
    paySecretSalary(sprint9User('FINANCE'));

    $this->actingAs(sprint9User('PM'))
        ->get(route('finance.transactions.index', ['kategori' => 'GAJI_KARYAWAN']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 0)
            ->where('summary.expense', 0));
});

test('#1 CEO and FINANCE still see salary transactions', function (string $role) {
    paySecretSalary(sprint9User('FINANCE'));

    $this->actingAs(sprint9User($role))
        ->get(route('finance.transactions.index', ['kategori' => 'GAJI_KARYAWAN']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.description', fn (string $d) => str_contains($d, 'Karyawan Rahasia')));
})->with(['CEO', 'FINANCE']);

test('#1 the PM export leaves salary rows out, the Finance export keeps them', function () {
    paySecretSalary(sprint9User('FINANCE'));

    $values = fn (array $filters) => collect(exportedTransactionCells($filters))->map(fn ($cell) => (string) $cell->getValue());

    expect($values(['hide_salary_payments' => true])->contains(fn ($v) => str_contains($v, 'Karyawan Rahasia')))->toBeFalse()
        ->and($values([])->contains(fn ($v) => str_contains($v, 'Karyawan Rahasia')))->toBeTrue();
});

test('#1 the salary filter cannot be switched off from the query string', function () {
    paySecretSalary(sprint9User('FINANCE'));

    $this->actingAs(sprint9User('PM'))
        ->get(route('finance.transactions.index', ['hide_salary_payments' => 0]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 0));
});

test('#2 formula-looking text is exported as plain text, not as a formula', function () {
    $this->actingAs(sprint9User('FINANCE'))->post(route('finance.transactions.store'), [
        'bank_account_id' => BankAccount::factory()->create()->id,
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 1000,
        'description' => '=HYPERLINK("http://evil.example","x")',
        'date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $description = collect(exportedTransactionCells([]))
        ->first(fn ($cell) => str_contains((string) $cell->getValue(), 'HYPERLINK'));

    expect($description)->not->toBeNull()
        ->and($description->getDataType())->toBe(DataType::TYPE_STRING);
});

test('#5 a fund transfer record cannot be updated or deleted', function () {
    $from = BankAccount::factory()->create();
    $to = BankAccount::factory()->create();

    app(FundTransferService::class)->transfer([
        'from_bank_account_id' => $from->id,
        'to_bank_account_id' => $to->id,
        'amount' => 1_000_000,
        'date' => now()->toDateString(),
        'description' => 'Top up',
    ], sprint9User('FINANCE'));

    $transfer = FundTransfer::firstOrFail();

    expect(fn () => $transfer->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $transfer->delete())->toThrow(LogicException::class);
});
