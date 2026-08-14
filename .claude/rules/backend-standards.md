# Backend Standards — Laravel 11 / PHP 8.3+

Berlaku untuk semua kode di `app/`, `routes/`, `database/`. Lihat juga
[`database-standards.md`](database-standards.md) untuk migrasi/skema dan
[`security-standards.md`](security-standards.md) untuk auth/validasi/audit.

## 1. Arsitektur: thin controller, logic di Service

Pola wajib untuk semua modul (CRM, Design, Quotation, Projects, Tasks,
Overtime, QA, Finance, Logistics) — sesuai catatan
`.claude/plan/sprint-01.md` ("thin controller pattern"):

```
Controller   → hanya orkestrasi: validasi via Form Request, panggil Service,
               kembalikan Inertia::render() atau redirect.
Form Request → SEMUA validasi input (lihat security-standards.md §2).
Service      → business logic, kalkulasi, orchestration lintas model
               (app/Services/*Service.php). Ini tempat rules dari PRD §6
               (state machine, kalkulasi termin, penalti, dsb) hidup.
Model        → relasi Eloquent, scope query, accessor/mutator ringan.
               Jangan taruh business logic berat di model.
Job/Event    → efek samping asinkron (notifikasi, kalkulasi terjadwal) —
               lihat app/Jobs, app/Events, app/Listeners.
```

Contoh nyata dari PRD/CSV: `LeadController` memanggil `LeadService::changeStatus()`
yang menulis `PipelineLog` dan memvalidasi rule "alasan lost wajib diisi"
(PRD §4.1) — bukan controller yang menulis log secara langsung.

```php
// app/Http/Controllers/CRM/LeadController.php
public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, LeadService $service)
{
    $service->changeStatus($lead, $request->validated(), $request->user());

    return back()->with('success', 'Status lead diperbarui.');
}
```

## 2. Namespace & lokasi per modul

Ikuti struktur yang sudah dibuat di PRD §3.4 — jangan buat controller di luar
sub-namespace modulnya:

```
app/Http/Controllers/{CRM,Design,Quotation,Projects,Tasks,Overtime,QA,Finance,Logistics,Analytics}/
app/Http/Requests/{ModulYangSama}/
app/Services/{Modul}Service.php
app/Models/{Model}.php            (flat, tidak per-modul)
```

## 3. Routing (`routes/web.php`)

- Group per modul dengan prefix + middleware role, contoh:

```php
Route::middleware(['auth', 'role:MARKETING|CEO'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function () {
        Route::resource('leads', LeadController::class);
    });
```

- Nama route: `{modul}.{resource}.{action}` (mis. `crm.leads.index`,
  `finance.termins.markPaid`). Dipakai langsung oleh Ziggy di frontend
  (`route('crm.leads.index')`) — jaga konsisten.
- Update `resources/js/Layouts/AppLayout.tsx` (`NAV_GROUPS`) begitu route
  suatu modul mulai dibuat — item nav yang belum ada `routeName` dirender
  disabled ("Segera").

## 4. Eloquent

- `$fillable` eksplisit di setiap model (bukan `$guarded = []`).
- Query scope untuk filter berulang (`scopeByStatus`, `scopeOverdue`, dst —
  sesuai catatan CSV Sprint 1-2).
- Eager-load relasi yang dipakai di halaman index (`with([...])`) — hindari
  N+1, terutama untuk tabel dengan TanStack Table yang menampilkan data
  relasi (nama PIC, nama proyek, dst).
- ENUM status (LeadStatus, DesignStatus, QuotationStatus, TaskStatus,
  QAStatus, OvertimeStatus — lihat `resources/js/types/index.d.ts` untuk
  daftar lengkap) disimpan sebagai native PHP enum class di `app/Enums/`
  begitu modul terkait mulai dibangun, di-cast via `casts()` pada model.
  Nilai string harus identik dengan union type TypeScript-nya.

## 5. Job & Scheduler

Job terjadwal dari PRD §6.5/§6.4 didaftarkan di `routes/console.php`
(Laravel 11 tidak lagi pakai `app/Console/Kernel.php`):

```php
Schedule::job(new DailyPenaltyJob)->weekdaysOnly()->at('21:00')
    ->timezone('Asia/Jakarta');
```

Semua job antre lewat `QUEUE_CONNECTION` (`database` di lokal, `redis` di
staging/production — lihat `.env` untuk keterangan) dan wajib idempotent:
job yang re-run pada hari yang sama tidak boleh membuat penalti dobel.

## 6. Testing

- Pest PHP (`tests/Feature`, `tests/Unit`) — target coverage ≥70% (PRD §10.3).
- Setiap Service dengan business rule non-trivial (PenaltyService,
  TerminService, OvertimeService, QAForm blocking) wajib unit test.
- Setiap route yang di-gate oleh role wajib punya feature test RBAC
  (lihat `security-standards.md` §4).

## 7. Gaya kode

- PSR-12, `php artisan pint` sebelum commit (Laravel Pint sudah ter-install
  via `laravel/pint` — bagian dari `require-dev`).
- Method publik pendek & deskriptif; jangan singkat nama modul PRD
  (`termin`, bukan `trm`; `overtime`, bukan `ot`).
- String yang mengarah ke user (notifikasi, pesan error) dalam Bahasa
  Indonesia, mengikuti PRD §1.4.
