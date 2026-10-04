# Sprint 10 — Modul SDM / HR (di luar PRD & CSV)

> Status: **keputusan dikunci 2026-10-04, belum ada kode.** Absensi
> (SDM-7) masih menunggu pilihan alat absen dari user.
>
> Aturan kerja tetap sama: `.claude/CLAUDE.md` + `.claude/rules/*` (thin
> controller → Service, Form Request, `role:` + Policy, audit append-only,
> tanpa route `destroy` untuk data gaji/penilaian, UI Bahasa Indonesia,
> token Daiku, `@/Components` huruf besar).

Permintaan user (2026-10-03): divisi SDM butuh modul untuk melihat
**Kedisiplinan · Gaji · KPI · Evaluasi** karyawan tetap.

---

## 1. Keputusan (dikunci 2026-10-04)

1. **Absensi lewat mesin absen fisik via API** (K1). User sedang memilih
   alat yang mendukung integrasi. Sampai alat dipilih, kedisiplinan =
   catatan teguran/SP yang diinput SDM. Struktur tabel absensi disiapkan
   generik (lihat §4 SDM-7) supaya merek alat apa pun cukup ditambah
   adapter-nya saja.
2. **Role baru `HR`** (K2), di luar PRD §2, seperti SUPERADMIN. CEO bisa
   membaca semua data. **Rencana modul berikutnya (bukan sprint ini):**
   SUPERADMIN bisa mengatur modul mana yang boleh diakses tiap user (akses
   per user, bukan hanya per role). Konsekuensinya untuk sprint ini: semua
   route SDM dikelompokkan di satu grup `hr.*` dengan satu middleware
   modul. Dengan begitu nanti gerbangnya cukup diganti dari `role:` ke
   pengecekan akses modul, tanpa membongkar route.
3. **Gaji pokok: SDM mengajukan, CEO menyetujui** (K3). SDM mengelola data
   karyawan; perubahan `base_salary` hanya lewat `salary_changes` yang
   di-approve CEO. Finance tetap satu-satunya yang membayar (tombol
   "Bayar" di Penggajian tidak pindah).
4. **Cakupan = karyawan tetap saja** (K4): arsitek, desainer, estimator,
   marketing, admin, finance, dst. — isi tabel `employees` yang sudah ada.
   **Tukang (FIELD_STAFF) tidak masuk modul SDM**; penalti, form harian,
   dan upah per task tetap di modul Tasks/Finance seperti sekarang.
   Tidak perlu kolom `employment_type`.
5. **Periode: KPI bulanan, evaluasi per semester** (K5) — Semester 1
   (Jan–Jun) dan Semester 2 (Jul–Des). Evaluasi semester memakai rata-rata
   6 snapshot KPI bulanan yang sudah ditutup.
6. **Karyawan bisa melihat KPI & evaluasi miliknya sendiri** (K6),
   read-only, hanya yang sudah final (periode KPI ditutup / evaluasi
   APPROVED). Syaratnya karyawan punya akun (`employees.user_id`).
7. **Penilai evaluasi = SDM saja** (K7). Tidak perlu relasi atasan
   (`supervisor_id`); PM tidak ikut menilai.
8. **Tidak ada pengaruh otomatis ke gaji** (K8). Grade evaluasi dan SP
   hanya menjadi rekomendasi. Kalau ada bonus/potongan, Finance mengisinya
   manual di tunjangan/potongan saat bayar gaji (konsisten dengan
   keputusan Sprint 9 #10).
9. **Indikator KPI = draf §3.3 sebagai default** (K9); SDM bisa mengubah
   template, target, dan bobot lewat UI.

10. **Jabatan terstruktur, tidak diketik bebas** (dikunci 2026-10-04).
    Struktur dua tingkat di Data Master: **Divisi → Jabatan**
    (`divisions` → `positions`, mis. Desain → Desainer / Arsitek /
    Drafter; Presales → Marketing / Estimator). Form karyawan memilih
    jabatan dari dropdown (dikelompokkan per divisi), tidak ada input teks
    jabatan di mana pun. Nama jabatan unik per divisi; jabatan/divisi
    dinonaktifkan, tidak dihapus, bila sudah dipakai karyawan atau
    template KPI. Kolom teks `employees.position` di-backfill ke
    `position_id` lalu dihapus. Template KPI dan semua filter/rekap SDM
    (per divisi, per jabatan) memakai struktur ini.
11. **Tukang punya akun, tapi dikecualikan dari modul SDM** (dikunci
    2026-10-04). Aturan pengecualiannya, ditegakkan di server (bukan
    hanya disembunyikan di UI):
    - User dengan role `FIELD_STAFF` **tidak bisa ditautkan** ke baris
      `employees` (validasi di Form Request + Service saat buat/ubah
      karyawan; dropdown akun hanya menampilkan user non-tukang yang
      belum tertaut).
    - Semua daftar, rekap, KPI, evaluasi, dan pilihan karyawan di SDM
      hanya mengambil `employees` — tukang otomatis tidak pernah muncul.
      Satu scope `Employee::scopeHrEligible()` dipakai di semua query SDM.
    - Menu "Milik Saya" (KPI/evaluasi/SP/riwayat gaji sendiri) tidak
      tampil untuk tukang dan route-nya 403 untuk mereka.
    - Bila user yang sudah tertaut ke karyawan kemudian diberi role
      `FIELD_STAFF`, perubahan role itu ditolak sampai tautannya dilepas
      (mencegah data campur diam-diam).
    - Data tukang (penalti, form harian, upah per task, lembur) tetap di
      modul Tasks/Finance, tidak berubah.
    - **Dampak ke akun tukang = tidak ada.** Task, form harian, penalti,
      lembur, dan pinjaman tukang semuanya menunjuk ke `users.id`
      (`assignee_id`/`staff_id`), bukan ke `employees` — modul SDM tidak
      menyentuh tabel/route itu. Validasi "akun tukang tidak bisa
      ditautkan ke karyawan" juga sudah ada sejak Sprint 9
      (`StoreEmployeeRequest`); sprint ini hanya menambah sisi
      sebaliknya (cegah role FIELD_STAFF diberikan ke akun yang sudah
      tertaut) dan scope query SDM.

12. **SP bertingkat wajib** (dikunci 2026-10-04): SP2 hanya selama SP1
    berlaku, SP3 hanya selama SP2 berlaku; lompat tingkat ditolak.
    Aturan rinci di §3.1.

### Keputusan turunan (usulan saya — koreksi jika tidak sesuai)

- **Indikator otomatis butuh akun.** Indikator OTOMATIS dihitung dari
  aktivitas user di sistem (desain, quotation, lead, proyek), jadi hanya
  jalan kalau karyawan punya `user_id`. Karyawan tanpa akun (mis. admin
  kantor) memakai indikator MANUAL saja; UI template memberi peringatan
  bila jabatan berisi indikator otomatis tapi karyawannya tidak punya akun.

---

## 2. Data yang sudah ada (dipakai ulang, tidak dibangun ulang)

| Kebutuhan | Sumber |
|---|---|
| Daftar karyawan tetap | `employees` (Sprint 9 SA-6) — nama, jabatan, `user_id` opsional, gaji pokok, rekening, tgl gabung, aktif |
| Riwayat gaji dibayar | `salary_payments` (append-only, 1 per karyawan per bulan) + `PayrollService` |
| Pinjaman karyawan | `staff_loans` + cicilan (bila karyawan punya akun) |
| Lembur | `overtime_requests` (bila karyawan tetap juga mengajukan lembur) |
| KPI otomatis | `DivisionDashboardService` — `designKpis()` (on schedule vs delay per PIC), turnaround quotation, monitor proyek; data lead & QA |

---

## 3. Ruang lingkup per bagian

### 3.1 Kedisiplinan

- **Catatan teguran & SP** (`disciplinary_records`): jenis
  (TEGURAN_LISAN / SP1 / SP2 / SP3 / CATATAN), tanggal, uraian, masa
  berlaku (default SP = 6 bulan), link dokumen (link, bukan upload),
  dicatat oleh. Append-only: koreksi = entri pembatalan (`voids_id`),
  bukan edit/hapus.
- **SP bertingkat — diblokir, bukan sekadar peringatan** (keputusan #12,
  dikunci 2026-10-04). Ditegakkan di `DisciplineService` di dalam
  transaksi (baris karyawan di-lock), bukan hanya di UI:
  - SP1 boleh terbit kapan saja selama tidak ada SP yang masih berlaku.
  - SP2 hanya boleh terbit bila SP1 karyawan itu **masih berlaku** pada
    tanggal terbit (belum lewat `valid_until`, tidak dibatalkan).
  - SP3 hanya boleh terbit bila SP2 masih berlaku. SP3 adalah tingkat
    terakhir — langkah sesudahnya (PHK/nonaktif) di luar sistem; SDM
    menonaktifkan karyawan secara manual.
  - SP yang melompati tingkat atau mengulang tingkat yang sama selagi
    masih berlaku **ditolak** dengan pesan yang menyebut SP mana yang
    seharusnya diterbitkan. Bila semua SP sudah lewat masa berlaku,
    tingkat kembali ke SP1.
  - SP yang dibatalkan (entri `voids_id`) dianggap tidak pernah berlaku
    untuk perhitungan tingkat.
  - Teguran lisan dan catatan tidak terikat tingkat.
  - Form SP menampilkan "Tingkat berikutnya: SPx" dan hanya mengaktifkan
    pilihan itu.
- Halaman rekap: karyawan dengan SP aktif, riwayat per karyawan, filter
  periode/divisi/jabatan, export Excel.
- Setelah absensi tersambung (SDM-7): rekap hadir/terlambat/alpa/izin per
  bulan tampil di halaman yang sama dan bisa jadi indikator KPI.

### 3.2 Gaji

- **Perubahan gaji pokok** (`salary_changes`): SDM ajukan (lama → baru,
  tanggal berlaku, alasan) → CEO approve/tolak (alasan tolak wajib) →
  saat approve, `employees.base_salary` ikut diperbarui dalam transaksi
  yang sama. Satu pengajuan PENDING per karyawan. Diaudit
  (`hr.salary_change_requested/approved/rejected`).
- `base_salary` tidak bisa lagi diubah langsung dari form edit karyawan
  (Finance maupun HR) — hanya nilai awal saat karyawan dibuat.
- **Rekap per karyawan** (read-only): riwayat gaji dibayar per bulan,
  total setahun, tunjangan/potongan, riwayat perubahan gaji pokok, sisa
  pinjaman.
- **Rekap beban gaji** per bulan & per jabatan.

### 3.3 KPI (bulanan)

- **Template per jabatan** (`kpi_templates` + `kpi_indicators`): nama
  indikator, sumber (OTOMATIS/MANUAL), `metric_key` (untuk OTOMATIS),
  target, bobot %, arah (makin tinggi/rendah makin baik). Total bobot
  wajib 100%.
- **Indikator default:**

  | Jabatan | Indikator otomatis | Contoh manual |
  |---|---|---|
  | Desainer / Arsitek | % desain on schedule, rata-rata hari delay, jumlah desain ACC klien | Kualitas gambar |
  | Estimator | rata-rata turnaround quotation, jumlah quotation terkirim | Akurasi RAB |
  | Marketing | lead baru, konversi lead → deal, follow-up terlambat | — |
  | PM | % milestone tepat waktu, % QA lolos pertama kali, proyek delay | Koordinasi tim |
  | QA / Finance / Logistik / Admin | jumlah form QA diproses, termin tertagih tepat waktu, selisih stok | sebagian besar manual |

- **Skor per indikator:** `aktual / target × 100` (dibalik untuk arah
  "makin rendah makin baik"), dibatasi 0–120, dikali bobot.
- **Periode** (`kpi_periods`): OPEN → hitung otomatis + SDM isi manual →
  CLOSED (snapshot `kpi_scores` dikunci; data operasional yang dikoreksi
  belakangan tidak mengubah skor bulan lalu). Job terjadwal tanggal 1
  menyiapkan periode bulan berjalan.

### 3.4 Evaluasi (per semester)

- `performance_reviews`: rata-rata KPI 6 bulan (dari periode CLOSED),
  ringkasan kedisiplinan (SP aktif/terbit, nanti absensi), aspek
  kualitatif skala 1–5 (sikap, kerja sama, inisiatif, tanggung jawab),
  catatan, nilai akhir, grade A–E, rekomendasi (naik gaji / bonus /
  pembinaan / SP / tidak ada).
- Bobot nilai akhir default: KPI 60% · kualitatif 25% · kedisiplinan 15%
  (bisa diubah SDM).
- Alur: `DRAFT` → `SUBMITTED` (SDM) → `APPROVED` / dikembalikan ke DRAFT
  dengan catatan (CEO) → `ACKNOWLEDGED` (karyawan konfirmasi sudah
  membaca). Setelah APPROVED terkunci; diaudit (`hr.review_*`).
- Rekomendasi "naik gaji" bisa langsung jadi draf pengajuan
  `salary_changes` (tetap butuh approval CEO, tidak otomatis).
- Export PDF (DomPDF).

---

## 4. RBAC (dikunci)

| Fitur | CEO | HR | FINANCE | Karyawan sendiri |
|---|---|---|---|---|
| Data karyawan | R | CRU (nonaktifkan, tidak hapus) | R + bayar gaji (seperti sekarang) | — |
| Master jabatan | R | CRU | — | — |
| Perubahan gaji pokok | Approve/tolak | Ajukan | R | — |
| Rekap gaji | R | R | R (sudah ada di Penggajian) | R* (slip/riwayat sendiri) |
| Kedisiplinan / SP | R | CR (append-only) | — | R* |
| Template KPI | R | CRU | — | — |
| Skor & periode KPI | R | Isi manual, hitung, tutup periode | — | R* (periode CLOSED) |
| Evaluasi | Approve / kembalikan | CRU sampai SUBMITTED | — | R* (APPROVED) + Acknowledge |
| Absensi (SDM-7) | R | R + koreksi/izin | — | R* |

`R*` = hanya data milik sendiri lewat `employees.user_id` (Policy, bukan
sekadar role check). SUPERADMIN tetap god-mode. Halaman "milik saya"
(KPI, evaluasi, SP, slip gaji) ada di menu terpisah yang bisa diakses
semua role yang punya baris `employees` tertaut.

---

## 5. Data model

```
divisions                  name (unik), is_active, sort_order, created_by — Data Master
positions                  division_id, name (unik per divisi), is_active, sort_order, created_by
employees (existing)       + position_id (FK positions, wajib; kolom teks position di-backfill lalu dihapus)
                           user_id tidak boleh menunjuk user FIELD_STAFF (keputusan #11)
salary_changes             employee_id, old_salary, new_salary, effective_date, reason,
                           status (PENDING|APPROVED|REJECTED), reject_note,
                           requested_by, decided_by, decided_at          — tanpa update setelah diputus
disciplinary_records       employee_id, type, issued_on, valid_until, description, link,
                           voids_id, recorded_by                         — append-only
kpi_templates              position_id (unik), is_active
kpi_indicators             kpi_template_id, name, source (AUTO|MANUAL), metric_key,
                           target, weight, direction, sort_order
kpi_periods                period (Y-m, unik), status (OPEN|CLOSED), closed_by, closed_at
kpi_scores                 kpi_period_id, employee_id, kpi_indicator_id, indicator snapshot
                           (nama/target/bobot), actual, score, weighted_score, input_by
                           — unik (period, employee, indicator); dikunci saat CLOSED
performance_reviews        employee_id, year, semester (1|2), kpi_average, discipline_summary (JSON),
                           qualitative (JSON), final_score, grade, recommendation, notes,
                           status, reviewer_id, approved_by/at, return_note, acknowledged_at
                           — unik (employee, year, semester)
attendance_devices (SDM-7) name, vendor, serial_no, api config terenkripsi, is_active
attendance_logs    (SDM-7) employee_id, device_id, scanned_at, type (IN|OUT), raw (JSON) — append-only
```

Enum PHP + union TS: `DisciplinaryType`, `SalaryChangeStatus`,
`KpiIndicatorSource`, `KpiPeriodStatus`, `ReviewStatus`, `ReviewGrade`,
`ReviewRecommendation`. Indikator disalin (snapshot) ke `kpi_scores` agar
mengubah template tidak mengubah skor bulan yang sudah ditutup.

---

## 6. Rencana eksekusi

```
Fase 1  ── SDM-1 fondasi (role HR, grup nav SDM, master jabatan)
Fase 2  ── SDM-2 Kedisiplinan · SDM-3 Gaji             (paralel)
Fase 3  ── SDM-4 KPI
Fase 4  ── SDM-5 Evaluasi (butuh snapshot KPI + SP)
Fase 5  ── SDM-6 dashboard, "milik saya", seeder, test, build
Ditunda ── SDM-7 Absensi (menunggu pilihan alat absen)
```

### SDM-1 · Fondasi
- [ ] **[Setup]** Role `HR` di `RoleSeeder` + `ProductionSeeder`, user demo `hr@daikuinterior.com`, redirect dashboard (`RoleRedirectService`)
- [ ] **[Setup]** Grup route `hr.*` dengan satu middleware modul (siap diganti akses per-user nanti — keputusan #2)
- [ ] **[Setup]** Grup `NAV_GROUPS` "SDM" (Karyawan, Kedisiplinan, Gaji, KPI, Evaluasi — disabled sampai route ada)
- [ ] **[Database]** Master `divisions` → `positions` + `employees.position_id` wajib (backfill dari teks `position`, lalu kolom teks dihapus); halaman Divisi & Jabatan di Data Master (aktif/nonaktif, tanpa hapus bila terpakai)
- [ ] **[HR]** Pengecualian tukang (keputusan #11): validasi `user_id` bukan FIELD_STAFF, `Employee::scopeHrEligible()`, tolak pemberian role FIELD_STAFF ke user yang tertaut karyawan
- [ ] **[HR]** Halaman Karyawan untuk HR (CRU, nonaktifkan) + profil bertab (Ringkasan · Kedisiplinan · Gaji · KPI · Evaluasi); Finance tetap read + bayar

### SDM-2 · Kedisiplinan
- [ ] **[HR]** `disciplinary_records` + `DisciplineService` (terbit, pembatalan, masa berlaku, SP bertingkat wajib — keputusan 12)
- [ ] **[HR]** Halaman rekap SP aktif + riwayat per karyawan, export Excel
- [ ] **[HR]** Notifikasi ke karyawan (bila punya akun) saat SP diterbitkan

### SDM-3 · Gaji
- [ ] **[HR]** `salary_changes` + `SalaryChangeService` (ajukan → approve/tolak CEO → update `base_salary` dalam satu transaksi, diaudit)
- [ ] **[Finance]** Kunci `base_salary` dari form edit karyawan (hanya nilai awal saat dibuat)
- [ ] **[HR]** Rekap gaji per karyawan (riwayat bayar, perubahan gaji pokok, sisa pinjaman)
- [ ] **[HR]** Rekap beban gaji per bulan & per jabatan

### SDM-4 · KPI
- [ ] **[HR]** Template & indikator per jabatan (validasi bobot 100%, peringatan indikator otomatis vs karyawan tanpa akun)
- [ ] **[HR]** `KpiService`: kalkulator `metric_key` otomatis (reuse `DivisionDashboardService`), input manual, rumus skor
- [ ] **[HR]** Periode KPI: buka (job tanggal 1) → hitung → tutup (snapshot dikunci, diaudit)
- [ ] **[HR]** Halaman KPI: peringkat per jabatan, detail per karyawan, tren bulanan

### SDM-5 · Evaluasi
- [ ] **[HR]** `performance_reviews` + state machine DRAFT → SUBMITTED → APPROVED/kembali → ACKNOWLEDGED
- [ ] **[HR]** Form evaluasi: prefill rata-rata KPI semester + ringkasan SP, aspek kualitatif, grade otomatis
- [ ] **[HR]** Approval CEO + notifikasi; rekomendasi "naik gaji" → draf `salary_changes`
- [ ] **[HR]** Export PDF evaluasi

### SDM-6 · Penutup
- [ ] **[HR]** Dashboard SDM (karyawan aktif, SP aktif, rata-rata KPI per jabatan, evaluasi tertunda, pengajuan gaji menunggu)
- [ ] **[HR]** Menu "Milik Saya": KPI, evaluasi (+ tombol konfirmasi), SP, riwayat gaji — Policy `R*`
- [ ] **[Setup]** DemoDataSeeder: jabatan, SP, perubahan gaji, 3 periode KPI, evaluasi di tiap status
- [ ] **[Test]** Unit test `KpiService`, `DisciplineService` (termasuk SP lompat tingkat/ulang tingkat ditolak, SP kedaluwarsa/dibatalkan reset ke SP1), `SalaryChangeService`, state machine evaluasi; test pengecualian tukang (tidak bisa ditautkan, tidak muncul di daftar SDM, 403 di "Milik Saya"); feature test RBAC tiap route (berhak 200/302, tidak berhak 403, `R*` hanya milik sendiri)
- [ ] **[Setup]** `npm run build`, `pint --test`, update `plan/README.md` + `CLAUDE.md`

### SDM-7 · Absensi (DITUNDA — menunggu alat)
- [ ] **[Riset]** User memilih alat absen; cek syarat integrasi di bawah
- [ ] **[Database]** `attendance_devices`, `attendance_logs` (append-only), mapping PIN/ID alat → `employees`
- [ ] **[HR]** Endpoint penerima data alat (adapter per vendor, autentikasi token/serial, idempotent terhadap scan dobel)
- [ ] **[HR]** Aturan jam kerja (jam masuk, toleransi terlambat, hari kerja) + rekap harian hadir/terlambat/alpa
- [ ] **[HR]** Izin/sakit/cuti (input SDM) + indikator KPI kehadiran

**Syarat alat absen yang perlu dicek saat memilih:**
- Bisa **mengirim** data scan ke server kita secara otomatis (push ke URL
  HTTP/HTTPS, mis. protokol ADMS di mesin berbasis ZKTeco, atau
  webhook/API cloud vendor) — bukan hanya ekspor USB/Excel.
- Kalau hanya mendukung *pull* (server yang menarik data) lewat jaringan
  lokal, perlu PC/bridge di kantor yang meneruskan ke server.
- Server production harus bisa diakses alat (HTTPS publik atau VPN).
- Tiap scan membawa ID karyawan di alat + waktu + serial alat.
- Dokumentasi API/SDK tersedia (minta ke distributor sebelum membeli).
