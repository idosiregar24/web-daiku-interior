# Sprint 8 — Gap PRD yang belum dibangun (di luar CSV)

> Sumber: audit gap 2026-09-28 terhadap PRD §4.2/§4.7/§4.1 dan
> `daiku_schema.sql`. Task di file ini **tidak ada di
> `Daiku-Task-Schedule.csv`** — CSV tidak pernah menjadwalkan sub-fitur
> Finance di bawah ini, meski PRD mewajibkannya. Semua pekerjaan mengikuti
> `.claude/CLAUDE.md` + `.claude/rules/*` (thin controller → Service,
> Form Request, `role:` middleware sesuai PRD §7.1, audit append-only, tanpa
> route `destroy` untuk data finance, UI Bahasa Indonesia, token Daiku,
> `@/Components` huruf besar, `npm run build` + `php artisan test` hijau).

**Ringkasan status:** 28 selesai · 0 sebagian · 0 belum — dari 28 task (termasuk 1 temuan tambahan di Fase 2: rekening pada transaksi lembur). Sisa: pertanyaan terbuka di bawah.

## Keputusan yang harus dikunci sebelum Fase 1

Semua subagent bergantung pada jawaban ini — jangan mulai paralel sebelum
dikunci:

1. **PK tabel baru: `bigint`** (rekomendasi), bukan ULID seperti
   `daiku_schema.sql`. Semua FK tujuan (`users`, `projects`,
   `bank_accounts`) sudah bigint, jadi ULID di tabel baru tetap harus
   menunjuk ke bigint. Deviasi dicatat di `README.md` → Schema discovery.
2. **Potongan cicilan pinjaman tukang:** tambah kolom
   `staff_loans.installment_amount` (deviasi dari schema). Saat upah
   dibayar, sistem memotong `min(installment_amount, remaining, upah)`.
   Schema tidak punya cara lain untuk mengetahui besar potongan.
3. **Hutang supplier & arus kas:** saat hutang dibuat → hanya dicatat
   sebagai kewajiban (`supplier_debts`). Saat dibayar →
   `FinanceTransaction` OUT kategori `HUTANG_IDEAL` + rekening. PRD menulis
   "muncul sebagai pengeluaran saat dibuat", tapi kalau diartikan
   harfiah, uang yang sama tercatat keluar dua kali di cash flow.
4. **RBAC fitur baru** (tidak punya baris sendiri di PRD §7.1) ikut baris
   *Finance – Transaction*: FINANCE = CRUD tanpa D, CEO/PM = R. Alokasi
   persentase: edit oleh **CEO + FINANCE** (aturan bisnis PRD §4.7).
5. **Alokasi persentase = perhitungan tampilan** (breakdown anggaran di
   tab Finance proyek), **bukan** membuat transaksi otomatis.
6. **Di luar lingkup sprint ini** (tidak ada tabel di schema, butuh desain
   dulu): Gaji Karyawan Tetap, Aset & Cicilan. Juga di luar lingkup
   (butuh infrastruktur/manusia): Docker, deploy CI/CD, UAT.

## Peta eksekusi

```
Fase 0  (utama, berurutan)      ── kunci keputusan + scaffolding file bersama
            │
Fase 1  (PARALEL, 4 subagent + utama)
   ├─ SA-1 Pinjaman Tukang        ─┐
   ├─ SA-2 Hutang Supplier         │  file saling terpisah,
   ├─ SA-3 Termin DP/Pelunasan     │  test SQLite in-memory
   ├─ SA-4 Lead source/category FK │  → tidak saling bentrok
   └─ Utama: Alokasi % + delay_hari + Overtime PENDING_FINANCE
            │
Fase 2  (utama, berurutan)      ── integrasi lintas modul + file bersama
            │
Fase 3  (utama + 1 subagent review) ── build, test penuh, security review, docs
```

### Aturan paralel (mencegah konflik)

- **File bersama hanya disentuh agen utama**: `routes/web.php`,
  `routes/console.php`, `Layouts/AppLayout.tsx` (`NAV_GROUPS`),
  `resources/js/types/index.d.ts`, `app/Enums/FinanceCategory.php`,
  `DemoDataSeeder`/`DatabaseSeeder`, `app/Services/FinanceTransactionService.php`,
  file di `.claude/plan/`. Semua ini diisi di Fase 0 (kerangka) atau
  Fase 2 (integrasi). Subagent yang butuh perubahan di file-file ini cukup
  melaporkan potongan kodenya di laporan akhir.
- Subagent hanya menulis file milik modulnya sendiri (migration, model,
  service, request, controller, policy, page, test, factory, seeder modul).
- Subagent **tidak** menjalankan `migrate` ke MySQL lokal. Yang dijalankan
  cukup `php artisan test --filter=<Modul>` (SQLite in-memory) dan
  `vendor/bin/pint` pada file miliknya.
- Tidak pakai worktree: file tiap subagent terpisah, jadi merge branch
  hanya menambah langkah tanpa manfaat.

---

## Fase 0 — Persiapan (utama, berurutan)

- [x] **[Plan]** Kunci keputusan 1–6 di atas bersama user (disetujui 2026-09-28: 1–5 ya)
- [x] **[Setup]** Baca PRD §4.7, §7.1, §9.4 + tabel terkait di `daiku_schema.sql` (sudah dilakukan saat audit; ulangi per subagent di prompt-nya)
- [x] **[Setup]** Scaffolding file bersama: route final di `routes/web.php` (`finance.staffLoans.*`, `finance.supplierDebts.*`, `finance.allocations.*`, `finance.termins.recordPayment` — camelCase mengikuti `finance.staffPayments.*` yang sudah ada), tipe TS (`StaffLoan(Payment)`, `SupplierDebt(Payment)`, `FinanceAllocationConfig/Line`, field Termin & Lead baru), key `StatusChip` baru (`BERJALAN`/`LUNAS`/`JATUH_TEMPO`/`PARTIAL`/`PENDING_FINANCE`), `FinanceTransactionService::create()` kini meneruskan `reference_id`. `NAV_GROUPS` sengaja belum disentuh — diisi di Fase 2 setelah controller-nya ada (golden rule #8)
  - 🐞 **Bug lama ditemukan & diperbaiki:** Laravel menserialisasi relasi sebagai snake_case (`bank_account`), tapi tipe TS & halaman memakai `bankAccount` → kolom "Rekening" di `Finance/Termins/Index.tsx` dan `Finance/Transactions/Index.tsx` **selalu tampil "—"**. Semua key relasi camelCase di `types/index.d.ts` diubah ke snake_case.

## Fase 1 — Pembangunan modul (PARALEL)

### SA-1 · Pinjaman Tukang — subagent `general-purpose`
*Alasan pakai subagent: modul lengkap end-to-end (±12 file), terisolasi, cocok resep `laravel-inertia-module`.*

- [x] **[Finance]** Migration `staff_loans` (+ `installment_amount`, `remaining` stored/generated) & `staff_loan_payments` (append-only, tanpa `updated_at`) + model + factory
  - Kolom FK pembayaran `staff_loan_id` (bukan `loan_id` schema — ikut database-standards §2) + `task_id` nullable (diisi saat cicilan dipotong dari upah) + `created_by`.
- [x] **[Finance]** `StaffLoanService`: `create()` (tulis `FinanceTransaction` OUT kategori `PINJAMAN` + rekening), `recordPayment()` (tolak kalau melebihi sisa), `deductFromWage(User, float): float` (dipakai Fase 2); semua lewat `AuditLogService::record()` dalam transaksi DB
- [x] **[Finance]** Form Request + `StaffLoanController` (index/create/store/show/storePayment — **tanpa** edit/destroy) + halaman `Finance/StaffLoans/{Index,Create,Show}.tsx` (DataTable, RHF+Zod, StatusChip LUNAS/BERJALAN)
- [x] **[Test]** Unit test service (potongan dibatasi sisa & upah, pinjaman lunas, audit tercatat) + RBAC test (FINANCE 200, PM read-only, FIELD_STAFF 403)

### SA-2 · Hutang Supplier — subagent `general-purpose`
*Alasan pakai subagent: strukturnya mirip SA-1 tapi file-nya terpisah total, jadi bisa jalan bersamaan.*

- [x] **[Finance]** Migration `supplier_debts` (FK `project_id` nullable, `due_date`, `remaining` generated) & `supplier_debt_payments` (append-only) + model + factory
- [x] **[Finance]** `SupplierDebtService`: `create()` (tanpa arus kas — keputusan #3), `recordPayment()` → `FinanceTransaction` OUT `HUTANG_IDEAL` + `bank_account_id` wajib, tolak kalau melebihi sisa, audit
- [x] **[Finance]** Form Request + `SupplierDebtController` (tanpa edit/destroy) + halaman `Finance/SupplierDebts/{Index,Create,Show}.tsx` (filter jatuh tempo, penanda hutang lewat jatuh tempo)
- [x] **[Test]** Unit + RBAC test

### SA-3 · Termin DP/Pelunasan/Sisa Piutang — subagent `general-purpose`
*Alasan pakai subagent: mengubah modul yang sudah jalan dan test-nya banyak, jadi perlu membaca konteks tersendiri. Terbatas di file Termin saja.*

- [x] **[Finance]** Migration `add_dp_amount_pelunasan_sisa_piutang_to_termins_table` (reversibel, `sisa_piutang` generated/stored)
  - MySQL: `STORED` sesuai schema. SQLite (test) tidak mengizinkan `ALTER TABLE ... STORED`, jadi di sana `VIRTUAL` — ekspresi sama, migration bercabang per driver.
- [x] **[Finance]** `TerminService`: dukung pembayaran parsial (DP lalu pelunasan), status `PAID` otomatis saat `sisa_piutang = 0`; `markPaid()` lama tetap kompatibel
  - `markPaid()` kini = "pelunasan seluruh sisa", memanggil `recordPayment()` (satu jalur kode). Status "Dibayar Sebagian" diturunkan dari nominal (chip `PARTIAL`), **bukan** status DB baru — schema hanya punya 4 status.
- [x] **[Finance]** UI `Finance/Termins/*`: kolom DP, Pelunasan, Sisa Piutang + form pembayaran parsial
- [x] **[Test]** Update test Termin yang ada + test pembayaran parsial

### SA-4 · Lead Source/Category → FK Data Master — subagent `general-purpose`
*Alasan pakai subagent: menyentuh modul CRM yang sudah jalan plus migrasi data string → FK. Modulnya terisolasi dari Finance.*

- [x] **[CRM]** Migration: tambah `lead_source_id`/`lead_category_id` (nullable FK), backfill dari string lama berdasarkan nama, kolom string lama **dipertahankan** sampai verifikasi (`down()` reversibel)
  - Backfill case-insensitive; string tanpa padanan dibuatkan baris Data Master baru (tidak ada data hilang). Diuji up→down→up di MySQL (database scratch terpisah).
- [x] **[CRM]** Update `Lead` model, Form Request (`exists:`), `LeadService`, form Create/Edit (Select dari Data Master), filter index, `LeadFactory`
  - `LeadService` menjaga kolom string lama tetap sinkron dengan nama Data Master, jadi dashboard/analytics yang membaca string tidak perlu diubah.
- [x] **[Test]** Test CRM yang ada tetap hijau + test backfill

### Utama (dikerjakan sendiri saat subagent berjalan — pekerjaan kecil)
*Alasan tidak pakai subagent: masing-masing kecil (2–5 file) dan menyentuh file bersama (`console.php`, types). Menulis prompt subagent justru lebih mahal dari mengerjakannya langsung.*

- [x] **[Finance]** `finance_allocation_configs` migration + seeder 9 baris default PRD (Gaji 12%, Operasional 2%, dst) + `FinanceAllocationService::breakdownFor(Project)` + halaman config (CEO+FINANCE) + test
  - Total alokasi aktif dijaga ≤ 100%. Baris dinonaktifkan (`is_active`), tidak dihapus. Seeder juga dipanggil `ProductionSeeder` (konfigurasi nyata, bukan data demo) dan idempoten — tidak menimpa persentase yang sudah diubah.
- [x] **[Design]** `DesignDelayJob` harian: hitung `designs.delay_hari` dari `deadline`, idempotent, didaftarkan di `routes/console.php` + test
  - Akumulasi inkremental via kolom baru `designs.delay_counted_on` — satu-satunya cara agar hari HOLD_CLIENT/REVISI_CLIENT tidak ikut dihitung (PRD §4.2) tanpa riwayat status. DONE_PRODUKSI membekukan delay; deadline yang dimundurkan ke masa depan me-reset delay.
- [x] **[Overtime]** Tambah state `PENDING_FINANCE` sesuai schema (enum PHP + TS union + `OvertimeService` + StatusChip) — data lama tetap valid + test alur
  - Diimplementasikan sebagai **rename** `APPROVED_PM` → `PENDING_FINANCE`, bukan state kelima: PRD §6.6 tidak punya aksi yang memindahkan APPROVED_PM → PENDING_FINANCE, jadi keduanya tidak bisa sama-sama jadi state. Data lama dipindah oleh migration reversibel (`2026_09_28_083658_...`).

## Fase 2 — Integrasi (utama, berurutan — butuh hasil Fase 1)

- [x] **[Finance]** `payStaffForTask()` memanggil `StaffLoanService::deductFromWage()`: transaksi upah = upah bersih, potongan tercatat sebagai `staff_loan_payment`, semuanya dalam satu transaksi DB. Sekalian perbaiki gap: transaksi upah belum mengisi `bank_account_id` (PRD §4.7 mewajibkan) → tambah pilihan rekening di halaman Upah Tukang
  - **Berubah dari rencana:** upah dicatat **bruto** (GAJI_KARYAWAN = biaya tenaga kerja sebenarnya) + setiap cicilan jadi **PEMASUKAN PINJAMAN** di rekening yang sama → arus kas bersih = yang benar-benar ditransfer. Simetris dengan pencairan pinjaman (PENGELUARAN PINJAMAN) dan pembayaran tunai (kini juga PEMASUKAN PINJAMAN + rekening wajib — sebelumnya tidak tercatat di cash flow sama sekali).
  - Logika dipindah ke `StaffPaymentService` baru (menghindari dependensi melingkar `FinanceTransactionService` ↔ `StaffLoanService`). Baris task dikunci (`lockForUpdate`) agar dua klik "Bayar" bersamaan tidak membayar dua kali. Aksi audit `finance.staff_paid` dipertahankan.
  - Halaman Upah Tukang: kolom Potongan Pinjaman & Dibayar Bersih (pratinjau `StaffLoanService::previewDeduction()` — rumus yang sama dengan potongan sungguhan) + dialog pilih rekening.
- [x] **[Overtime]** *(temuan saat Fase 1)* Transaksi LEMBUR_BONUS saat Finance approve juga tidak menyimpan rekening → kini wajib dipilih di dialog approve (reject tidak butuh rekening). Setelah perubahan ini **tidak ada lagi jalur yang menulis transaksi tanpa rekening** (diverifikasi: 10/10 transaksi demo punya rekening).
- [x] **[Projects]** Tab Finance di detail proyek menampilkan breakdown alokasi % + hutang supplier milik proyek
  - Plus kolom DP/Pelunasan/Sisa Piutang + chip "Dibayar Sebagian" pada tabel termin proyek.
- [x] **[Setup]** Gabungkan potongan kode dari laporan subagent ke file bersama: rute, `NAV_GROUPS` (isi `routeName`), tipe TS, `FinanceCategory` (kalau perlu), `DemoDataSeeder` (contoh pinjaman, hutang, termin parsial)
  - Menu baru: Pinjaman Tukang, Hutang Supplier (CEO/PM/FINANCE), Alokasi Persentase (CEO/FINANCE). `FinanceCategory` tidak perlu diubah. `Lead.category` di TS kini `string | null` (nama Data Master bebas).

## Fase 3 — Verifikasi & dokumentasi

- [x] **[Test]** `php artisan migrate:fresh --seed` di MySQL lokal + `php artisan test` penuh + `npm run build` + `vendor/bin/pint --test` — semua hijau
  - 575 test lulus setelah perbaikan review (sebelum sprint: 482). Build & Pint bersih.
- [x] **[Security]** Review keamanan diff (wajib — menyentuh finance, security-standards §7) — **subagent** `general-purpose` read-only dengan konteks bersih, supaya review tidak bias oleh kode yang ditulis sendiri
  - Hasil: **tidak ada celah otorisasi** (semua rute baru, props Inertia, dan locking pembayaran terverifikasi). 3 temuan Medium + 5 Low **diperbaiki**, masing-masing dengan test regresi di `tests/Feature/Security/Sprint8HardeningTest.php`:
    - (M) "Tandai Dibayar" termin bisa menulis transaksi tanpa rekening → `TerminService::recordPayment()` kini menolak rekening kosong/nonaktif; tombol hanya muncul bila termin punya rekening.
    - (M) Termin yang sudah PAID sebelum migration tampil "sisa piutang = nominal penuh" → migration mem-backfill `pelunasan = amount`.
    - (M) Approve lembur oleh Finance bisa tercatat 2× saat klik ganda → status dicek ulang di bawah `lockForUpdate` (PM & Finance).
    - (L) Cek DONE/rate upah dipindah setelah lock; total alokasi dikunci sebelum dijumlah; tanggal bayar hutang tidak boleh di masa depan; FK tabel finance baru `restrictOnDelete` (append-only); rename sumber/kategori lead menyinkronkan string lama + hapus ditolak selama masih dipakai; pratinjau potongan upah tidak menghitung cicilan yang sama dua kali; pinjaman baru hanya untuk tukang aktif.
- [x] **[Docs]** Update `README.md` (tabel sprint, Schema discovery: tabel yang kini sudah ada + deviasi bigint/`installment_amount`/keputusan #3) + centang file ini

## Keputusan pasca-sprint (2026-09-28)

- [x] **DP termin sebelum milestone selesai → boleh.** DP = uang muka, diterima sebelum pekerjaan; gerbang PRD §6.3 ("termin unlocked setelah milestone lolos QA") kini hanya berlaku untuk **pelunasan** (`TerminService::recordPayment()`, termasuk `markPaid()`). Test: *"a DP can be received before the linked milestone is COMPLETED, but pelunasan cannot"*.
- [x] **Zona waktu → WIB.** `.env`/`.env.example` ternyata sudah `APP_TIMEZONE=Asia/Jakarta` (temuan review hanya berlaku untuk fallback); fallback di `config/app.php` kini juga `Asia/Jakarta`, jadi server tanpa variabel itu tidak diam-diam jatuh ke UTC.
- [x] **Kategori sistem dikunci dari transaksi manual.** `FinanceCategory::systemManaged()` = PINJAMAN, HUTANG_IDEAL, DOWN_PAYMENT, TERMIN — hanya ditulis oleh menu Pinjaman Tukang / Hutang Supplier / Termin yang sekaligus menjaga saldonya. `StoreFinanceTransactionRequest` menolaknya dan dropdown "Catat Transaksi" tidak menampilkannya. GAJI_KARYAWAN tetap manual (satu-satunya cara mencatat gaji karyawan tetap sampai modulnya ada).

## Pertanyaan terbuka (sisa)

1. **Kolom string lama `leads.source`/`leads.category`** bisa di-drop setelah backfill production diverifikasi (`lead_source_id IS NULL` = 0) dan dashboard CRM `bySource` dipindah ke `lead_source_id`.
2. **Saldo `bank_accounts.balance`** masih diisi manual — tidak ada transaksi (lama maupun baru) yang meng-update-nya.
3. Kategori ledger pembayaran termin #1 kini mengikuti jenis bayar (DP → DOWN_PAYMENT, pelunasan → TERMIN), bukan lagi nomor termin — laporan per kategori data lama vs baru akan terbelah.
4. `TerminCalendar.tsx` masih memakai tombol lama "Tandai Dibayar" (lunas penuh, hanya bila termin punya rekening); belum ada dialog pembayaran parsial di tampilan kalender.
