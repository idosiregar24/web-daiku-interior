<?php

namespace Database\Seeders\Demo;

use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\SalaryChange;
use App\Models\User;
use App\Services\DisciplineService;
use App\Services\SalaryChangeService;
use Illuminate\Database\Seeder;

/**
 * SDM demo data (Sprint 10 SDM-2/SDM-3) on top of the employees seeded by
 * DemoDataSeeder (Boy, Icha, Ami, ...). Written through the services so the
 * escalation rule, audit trail and notifications behave as in production:
 * a reprimand, an SP1 → SP2 escalation, a cancelled SP, and a pending,
 * an approved and a rejected salary change. Skips itself when SDM data
 * already exists, so a re-run doesn't duplicate.
 */
class DisciplineSalaryDemoSeeder extends Seeder
{
    public function run(DisciplineService $discipline, SalaryChangeService $salary): void
    {
        $hr = User::role('HR')->where('is_active', true)->orderBy('id')->first();
        $ceo = User::role('CEO')->where('is_active', true)->orderBy('id')->first();

        if (! $hr || ! $ceo || DisciplinaryRecord::query()->exists() || SalaryChange::query()->exists()) {
            return;
        }

        $employee = fn (string $name) => Employee::query()->hrEligible()->where('name', $name)->first();

        if ($boy = $employee('Boy')) {
            $discipline->issue($boy, [
                'type' => 'TEGURAN_LISAN',
                'issued_on' => now()->subDays(20)->toDateString(),
                'description' => 'Terlambat menghadiri meeting klien dua kali dalam seminggu.',
            ], $hr);
        }

        if ($ami = $employee('Ami')) {
            $discipline->issue($ami, [
                'type' => 'SP1',
                'issued_on' => now()->subDays(40)->toDateString(),
                'description' => 'RAB proyek dikirim ke klien tanpa pengecekan ulang — selisih harga material.',
                'link' => 'https://drive.google.com/file/d/contoh-sp1-ami',
            ], $hr);

            $discipline->issue($ami, [
                'type' => 'SP2',
                'issued_on' => now()->subDays(10)->toDateString(),
                'description' => 'Kesalahan RAB berulang selama SP1 masih berlaku.',
            ], $hr);
        }

        if ($hesti = $employee('Hesti')) {
            $wrong = $discipline->issue($hesti, [
                'type' => 'SP1',
                'issued_on' => now()->subDays(30)->toDateString(),
                'description' => 'Tidak masuk kerja tanpa keterangan.',
            ], $hr);

            $discipline->void($wrong, 'Ternyata sudah izin sakit dengan surat dokter — salah catat.', $hr);
        }

        if ($ibnu = $employee('Ibnu')) {
            $salary->request($ibnu, [
                'new_salary' => 4_500_000,
                'effective_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'reason' => 'Masa kerja dua tahun dan kualitas gambar kerja konsisten baik.',
            ], $hr);
        }

        if ($icha = $employee('Icha')) {
            $change = $salary->request($icha, [
                'new_salary' => 5_500_000,
                'effective_date' => now()->startOfMonth()->toDateString(),
                'reason' => 'Desain on schedule 95% selama semester terakhir.',
            ], $hr);

            $salary->approve($change, $ceo);
        }

        if ($satria = $employee('Satria')) {
            $change = $salary->request($satria, [
                'new_salary' => 4_000_000,
                'effective_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'reason' => 'Penyesuaian dengan UMK terbaru.',
            ], $hr);

            $salary->reject($change, 'Ditinjau ulang bersama evaluasi semester berikutnya.', $ceo);
        }
    }
}
