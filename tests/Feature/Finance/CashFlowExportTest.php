<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Exports\CashFlowExport;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\FundTransferService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-09-15 10:00:00');

    $this->finance = User::factory()->create();
    $this->finance->assignRole('FINANCE');

    $this->bca = BankAccount::factory()->create(['label' => 'BCA', 'opening_balance' => 1_000_000]);
    $this->mandiri = BankAccount::factory()->create(['label' => 'Mandiri', 'opening_balance' => 0]);
    $this->project = Project::factory()->create(['name' => 'Rumah Ibu Sari']);

    $book = fn (BankAccount $account, FinanceTransactionType $type, FinanceCategory $kategori, float $amount, string $date, ?Project $project = null) => FinanceTransaction::factory()->create([
        'bank_account_id' => $account->id,
        'project_id' => $project?->id,
        'type' => $type->value,
        'kategori' => $kategori->value,
        'amount' => $amount,
        'date' => $date,
    ]);

    // Older than the default 6-month window (2026-04-01 onwards).
    $book($this->bca, FinanceTransactionType::Expense, FinanceCategory::Operasional, 700_000, '2026-02-10');
    $book($this->bca, FinanceTransactionType::Income, FinanceCategory::Termin, 5_000_000, '2026-08-05', $this->project);
    $book($this->bca, FinanceTransactionType::Expense, FinanceCategory::BeliBahan, 1_200_000, '2026-09-02', $this->project);
    $book($this->mandiri, FinanceTransactionType::Expense, FinanceCategory::Operasional, 300_000, '2026-09-10');

    app(FundTransferService::class)->transfer([
        'from_bank_account_id' => $this->bca->id,
        'to_bank_account_id' => $this->mandiri->id,
        'amount' => 2_000_000,
        'date' => '2026-09-05',
        'description' => 'Top up operasional',
    ], $this->finance);
});

/** Runs the export route and hands back the CashFlowExport it built. */
function downloadCashFlow($test, User $user, array $query = []): CashFlowExport
{
    Excel::fake();
    $test->actingAs($user)->get(route('finance.transactions.export', $query))->assertOk();

    $captured = null;
    Excel::assertDownloaded('cash-flow-2026-09.xlsx', function (CashFlowExport $export) use (&$captured) {
        $captured = $export;

        return true;
    });

    return $captured;
}

/** @return array<string, object> sheets keyed by title */
function cashFlowSheets(CashFlowExport $export): array
{
    return collect($export->sheets())->keyBy(fn ($sheet) => $sheet->title())->all();
}

test('without filters the export covers the last 6 months in four sheets', function () {
    $export = downloadCashFlow($this, $this->finance);
    $sheets = cashFlowSheets($export);

    expect($export->filters())->toBe(['from' => '2026-04-01'])
        ->and(array_keys($sheets))->toBe(['Transaksi', 'Per Bulan', 'Per Proyek', 'Per Rekening'])
        // The February row is outside the window; both transfer legs are detail rows.
        ->and($sheets['Transaksi']->collection())->toHaveCount(5);

    $monthly = $sheets['Per Bulan']->array();
    expect(array_column(array_slice($monthly, 0, 6), 0))->toBe(
        collect(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'])
            ->map(fn (string $month) => Carbon::createFromFormat('!Y-m', $month)->translatedFormat('F Y'))
            ->all(),
    )
        ->and($monthly[4])->toMatchArray([1 => 5_000_000.0, 2 => 0.0])
        // September: the transfer is left out company-wide.
        ->and($monthly[5])->toMatchArray([1 => 0.0, 2 => 1_500_000.0, 3 => -1_500_000.0])
        ->and($monthly[6])->toBe(['Total', 5_000_000.0, 1_500_000.0, 3_500_000.0])
        ->and(end($monthly)[0])->toContain('Pindah Dana');

    $projects = $sheets['Per Proyek']->array();
    expect($projects[0])->toBe(['Rumah Ibu Sari', 5_000_000.0, 1_200_000.0, 3_800_000.0])
        ->and($projects[1])->toBe(['Tanpa Proyek', 0.0, 300_000.0, -300_000.0])
        ->and($projects[2])->toBe(['Total', 5_000_000.0, 1_500_000.0, 3_500_000.0]);

    $accounts = $sheets['Per Rekening'];
    expect($accounts->headings())->toContain('Saldo Awal Periode', 'Saldo Akhir Periode');

    $rows = $accounts->array();
    // Saldo awal periode = 1.000.000 − the February expense; both transfer legs count per account.
    expect(array_slice($rows[0], 3))->toBe([300_000.0, 5_000_000.0, 3_200_000.0, 2_100_000.0])
        ->and(array_slice($rows[1], 3))->toBe([0.0, 2_000_000.0, 300_000.0, 1_700_000.0])
        // Keseluruhan: balances summed, masuk/keluar without the transfer.
        ->and($rows[2])->toBe(['Keseluruhan', '', '', 300_000.0, 5_000_000.0, 1_500_000.0, 3_800_000.0]);
});

test('the export follows the account and date filters, counting that account\'s transfer leg', function () {
    $export = downloadCashFlow($this, $this->finance, [
        'bank_account_id' => $this->bca->id,
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]);
    $sheets = cashFlowSheets($export);

    expect($sheets['Transaksi']->collection()->pluck('bank_account_id')->unique()->all())->toBe([$this->bca->id])
        ->and($sheets['Transaksi']->collection())->toHaveCount(2);

    $monthly = $sheets['Per Bulan']->array();
    expect($monthly)->toHaveCount(2)
        ->and($monthly[0])->toMatchArray([1 => 0.0, 2 => 3_200_000.0])
        ->and($monthly[1])->toBe(['Total', 0.0, 3_200_000.0, -3_200_000.0]);

    // One account: its statement line, no Keseluruhan.
    expect($sheets['Per Rekening']->array())->toBe([
        ['BCA', $this->bca->bank_name, $this->bca->account_no, 5_300_000.0, 0.0, 3_200_000.0, 2_100_000.0],
    ]);
});

test('with a project or type filter the account sheet drops the balance columns', function () {
    $sheets = cashFlowSheets(downloadCashFlow($this, $this->finance, ['project_id' => $this->project->id]));

    expect($sheets['Transaksi']->collection()->pluck('project_id')->unique()->all())->toBe([$this->project->id])
        ->and($sheets['Per Rekening']->headings())->toBe(['Rekening', 'Bank', 'No. Rekening', 'Pemasukan', 'Pengeluaran', 'Selisih'])
        ->and($sheets['Per Rekening']->array())->toBe([
            ['BCA', $this->bca->bank_name, $this->bca->account_no, 5_000_000.0, 1_200_000.0, 3_800_000.0],
        ]);

    $expenses = cashFlowSheets(downloadCashFlow($this, $this->finance, ['type' => 'PENGELUARAN', 'kategori' => 'BELI_BAHAN']));
    expect($expenses['Transaksi']->collection()->pluck('kategori')->unique()->all())->toBe([FinanceCategory::BeliBahan]);
});

test('the export is readable by CEO, PM and Finance only', function (string $role, int $status) {
    Excel::fake();
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.transactions.export'))->assertStatus($status);
})->with([
    ['CEO', 200],
    ['PM', 200],
    ['FINANCE', 200],
    ['MARKETING', 403],
    ['LOGISTICS', 403],
    ['FIELD_STAFF', 403],
]);

test('malformed export filters are rejected in Bahasa Indonesia', function () {
    Excel::fake();

    $this->actingAs($this->finance)
        ->from(route('finance.transactions.index'))
        ->get(route('finance.transactions.export', ['from' => '2026-09-10', 'to' => '2026-09-01', 'kategori' => 'BUKAN']))
        ->assertSessionHasErrors([
            'to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'kategori' => 'Kategori tidak valid.',
        ]);
});
