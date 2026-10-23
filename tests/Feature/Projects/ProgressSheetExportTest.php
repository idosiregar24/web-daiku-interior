<?php

use App\Enums\ProjectPhase;
use App\Exports\ProgressSheetExport;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/*
 * The Lembar Progres (Detail Proyek › Item RAB) as Excel: the same figures
 * as the page (WorkItemService::progressRecap()), for the same viewers
 * (ProjectPolicy::viewProgressRecap()).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    // Kabinet 30 jt + Meja 10 jt with a wage (bobot 75 / 25), Barang jadi without (never on the sheet).
    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Kabinet' => 30, 'Meja' => 10, 'Barang jadi' => 50] as $description => $nilai) {
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1,
            'unit_price' => $nilai * 1_000_000, 'total_price' => $nilai * 1_000_000, 'sort_order' => $nilai,
        ]);
    }
    $this->project = Project::factory()->create([
        'name' => 'Proyek Sheet', 'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'team_lead_name' => 'Putra',
    ]);
    $this->project->supervisors()->attach($this->qa->id);

    $service = app(WorkItemService::class);
    $service->ensureForProject($this->project);
    $this->project->workItems()->with('quotationItem')->get()
        ->reject(fn (ProjectWorkItem $item) => $item->quotationItem->description === 'Barang jadi')
        ->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 100_000], $this->qa));

    $item = fn (string $description) => $this->project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', $description))->sole()->id;
    // 14 Okt: Kabinet 80 %, Meja 40 % → (30 × 80 + 10 × 40) ÷ 40 = 70; 16 Okt: Kabinet +20 → +15.
    $service->recordProgress($this->project, [['item_id' => $item('Kabinet'), 'percent' => 80], ['item_id' => $item('Meja'), 'percent' => 40]], null, $this->qa);
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Jakarta'));
    $service->recordProgress($this->project, [['item_id' => $item('Kabinet'), 'percent' => 20]], null, $this->qa);
});

afterEach(fn () => Carbon::setTestNow());

function progressSheetUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Runs the route as $user and hands back the rows of the workbook it built. */
function downloadProgressSheet(object $test, User $user): array
{
    Excel::fake();
    $test->actingAs($user)->get(route('projects.progress-sheet.excel', $test->project))->assertOk();

    $rows = null;
    Excel::assertDownloaded('lembar-progres-proyek-sheet-2026-10-16.xlsx', function (ProgressSheetExport $export) use (&$rows) {
        $rows = $export->array();

        return true;
    });

    return $rows;
}

test('the sheet holds the page\'s figures: bobot, % per pengecekan, total, kontribusi, and the Jumlah / kumulatif rows', function () {
    $rows = downloadProgressSheet($this, $this->pm);
    $heading = collect($rows)->search(fn (array $row) => ($row[0] ?? null) === 'No');

    expect($rows[1][0])->toBe('Lembar Progres — Proyek Sheet')
        ->and($rows[4])->toBe(['Progres Berbobot', 85.0])
        ->and($rows[$heading])->toBe([
            'No', 'Uraian', 'Volume', 'Kepala Tim', 'Jadwal', 'Nilai (Rp)', 'Bobot (%)',
            '14 Okt 2026', '16 Okt 2026', 'Total % (cek Pengawas)', 'Kontribusi (%)',
        ]);

    [$meja, $kabinet] = [$rows[$heading + 1], $rows[$heading + 2]];
    // Volume = qty + the factory's unit code.
    expect($meja[2])->toStartWith('1 ')->and($kabinet[2])->toStartWith('1 ');
    $meja[2] = $kabinet[2] = '1';

    // toEqual: a whole % arrives as int (4000 hundredths ÷ 100) — the same cell in Excel.
    expect($meja)->toEqual([1, 'Meja', '1', 'Putra', 'Belum dijadwalkan', 10_000_000, 25, 40, null, 40, 10])
        ->and($kabinet)->toEqual([2, 'Kabinet', '1', 'Putra', 'Belum dijadwalkan', 30_000_000, 75, 80, 20, 100, 75])
        // The item without a wage never reaches the sheet.
        ->and(collect($rows)->pluck(1))->not->toContain('Barang jadi')
        ->and($rows[$heading + 3])->toEqual(['', 'Jumlah', '', '', '', 40_000_000, 100, 70, 15, null, 85])
        ->and($rows[$heading + 4])->toEqual(['', 'Progres kumulatif', '', '', '', null, null, 70, 85, null, null]);
});

test('RBAC: CEO, PM, Finance, the project\'s Pengawas and the Admin Proyek download; nobody else', function () {
    Excel::fake();
    $url = route('projects.progress-sheet.excel', $this->project);

    foreach ([$this->pm, $this->qa, progressSheetUser('CEO'), progressSheetUser('FINANCE'), progressSheetUser('ADMIN_PROYEK')] as $user) {
        $this->actingAs($user)->get($url)->assertOk();
    }

    // Another QA (not this project's Pengawas) passes the role gate but not the policy.
    foreach (['QA', 'MARKETING', 'LOGISTICS', 'ASISTEN_PM', 'FIELD_STAFF'] as $role) {
        $this->actingAs(progressSheetUser($role))->get($url)->assertForbidden();
    }
});

test('a project without RAB items has no Lembar Progres to download', function () {
    $older = Project::factory()->create(['pm_id' => $this->pm->id]);

    $this->actingAs($this->pm)->get(route('projects.progress-sheet.excel', $older))->assertNotFound();
});
