# Sprint 28 — Perbaikan dari Uji Alur Klien (simulasi Rina Kusuma)

> Status: **rencana — belum dikerjakan** (2026-10-20). Q1–Q5 dijawab user
> 2026-10-20 (§4): Q1 tetap boleh, Q2 boleh, Q3 tetap "Form Harian", Q4 ya,
> Q5 ya → keputusan K1–K16 terkunci (F18 & blokir Tandai Selesai dibatalkan).
> Sumber: uji ulang [`simulasi-alur-klien.md`](simulasi-alur-klien.md)
> langkah 1–27 + Tahap 9 pada 2026-10-20, dua cara:
> 1. **Pest end-to-end** — `tests/Feature/ClientJourneySimulationTest.php`
>    (1 test, 297 assertion, lulus; suite penuh 2201 lulus). Belum di-commit.
> 2. **Browser per role** — `http://127.0.0.1:8010`, database terpisah
>    `daiku_interior_sim` (`migrate --seed`), login bergantian Marketing →
>    Estimator → PM → CEO → klien (tanpa login) → Finance → Kepala Desain →
>    Arsitek → Pengawas (QA) → Tukang.
>
> Hasil: **alur lengkap berjalan** dari lead sampai pelunasan; angka, nomor
> surat, status, RBAC (403 Tukang ubah judul task, Marketing/Asisten PM tanpa
> `budget`, Arsitek/Estimator tanpa `payments`), penahanan QA oleh temuan, dan
> COMPLETED otomatis semuanya sesuai. Sprint ini memperbaiki **1 bug yang
> membuat pengguna terjebak**, beberapa masalah UI/konsistensi, dan dokumen
> yang tertinggal dari Sprint 23–27. Di luar PRD; lanjutan Sprint 23–27.

## 1. Temuan

Prioritas: **P1** = pengguna bisa terjebak / salah paham alur · **P2** =
tampilan salah atau tidak konsisten · **P3** = kosmetik / dokumen.

| # | Prio | Temuan | Di mana | Langkah uji |
|---|---|---|---|---|
| F1 | P1 | **Tanggal yang sudah terpilih tidak bisa dipilih ulang.** Mengklik tanggal yang sudah tersorot di kalender membatalkan pilihan (`Calendar mode="single"` → `onSelect(undefined)`), tetapi tombol tetap menampilkan tanggal itu (React Hook Form kembali ke `defaultValues`). Hasilnya "Tanggal mulai timeline wajib diisi" pada kolom yang terlihat terisi; klik ulang tanggal yang sama tetap membatalkan — pengguna harus memilih tanggal lain dulu. | `Components/shared/DatePicker.tsx` (semua form yang memakai `DatePicker` dengan nilai awal — terlihat di Tambah Timeline, tanggal mulai yang disarankan) | 22 |
| F2 | P1 | **Task "Minta dicek" tidak masuk Perlu Tindakan PM.** Chip-nya "Pengecekan · menunggu PM / Asisten PM", tetapi tidak ada antrean/badge — PM hanya tahu bila membuka halaman Tugas. | `ActionInboxService` | 23 |
| F3 | P1 | **Peringatan anggaran yang tidak bisa diselesaikan.** Setelah semua item RAB dialokasikan (sesuai instruksi), muncul "Total pos (Rp 53.860.000) melebihi total RAB (Rp 51.860.000) — diskon tidak ikut dibebankan ke pos. Periksa kembali alokasinya." Selalu muncul pada RAB berdiskon; PM tidak punya cara memperbaikinya. | `BudgetAllocationTab.tsx:130` | 21 |
| F4 | P2 | **Status task berupa enum mentah** di dialog "Ubah Status" PM: `PENDING / ONPROGRESS / PENGECEKAN / DONE` (Tukang melihat "Belum mulai / Sedang dikerjakan / Minta dicek / Selesai"). Kolom prioritas halaman Tugas juga "MEDIUM". | `TaskStatusDialog.tsx:105-108`, `Pages/Tasks/Index.tsx` | 23 |
| F5 | P2 | **"Gambar Kerja Selesai" memakai `confirm()` bawaan browser** — melanggar `design-standards.md` §2 (kode baru = `ConfirmDialog`). | `WorkingDrawingShow.tsx:87` | 22a |
| F6 | P2 | **"Setujui Berangkat ke Lokasi" tanpa konfirmasi** — perpindahan fase PRODUKSI → PEMASANGAN tidak bisa dibatalkan tetapi langsung dieksekusi sekali klik. | `DepartureButton.tsx` | 27 |
| F7 | P2 | **Termin & invoice tidak sepakat soal giliran.** Setelah Marketing mengirim bukti bayar, chip termin tetap "Ditagih · menunggu **Klien**" sementara invoice-nya "Menunggu Konfirmasi · menunggu **Finance**". | `lib/waitingOn.ts:89` (termin `INVOICED → Klien`), baris termin di tab Keuangan | 20, 27 |
| F8 | P2 | **Termin yang sudah Lunas masih bertuliskan "Menunggu pemicu"** di kolom Jadwal (Termin 2 & Pelunasan). | `Pages/Finance/Termins/Index.tsx:40` | Tahap 9 |
| F9 | P2 | **"Termin 3" vs "Pelunasan".** Marketing menerima notifikasi "Terbitkan Invoice Termin 3" dan dialog "Terbitkan Invoice Termin 3"; Finance & invoice menyebutnya "Pelunasan". Aturan 11 CLAUDE.md: label pembayaran hanya dari `Invoice::paymentLabel()` / `Termin::invoiceType()`. | `TerminService.php:465`, dialog terbitkan invoice termin (`Projects/Show.tsx` ±699) | 27 |
| F10 | P2 | **Timeline pertama tetap "Belum Mulai"** selama Produksi walau target di dalamnya sudah dikerjakan/Selesai; baru timeline berikutnya yang otomatis IN_PROGRESS setelah QA. | Start Produksi (`ProjectPhaseService` / `SupervisorPaymentService` DP disetujui) | 22–25 |
| F11 | P2 | **QA form tidak memberi tahu temuan terbuka di awal** — Pengawas mengisi checklist, klik Setujui, baru ditolak "Masih ada 1 temuan finishing…". | `Pages/QA/…Show` + `QaFormService::review` | 27 |
| F12 | P3 | Catatan surat RAB Proyek: "Pembayaran mengikuti skema termin pada tabel **di bawah**" — di surat/link klien skema tampil **di atas** catatan. | `Support/Letters/QuotationLetter.php:47` | 18 |
| F13 | P3 | **Isi dialog hilang saat menutup**: "Bayar Pengawas: — Rp 0 untuk —" dan `Tandai timeline selesai? ""` sekilas terlihat (prop dikosongkan sebelum animasi tutup selesai). | Dialog Bayar Jasa Pengawas, ConfirmDialog Tandai Selesai timeline | 22, 25 |
| F14 | P3 | **Geser target menawarkan hari Minggu** ("Pindah ke sini" di Min, 25), padahal hari kerja Senin–Sabtu (`DailyFormSchedule`) dan bergulir melompati Minggu. | `TaskWeekBoard.tsx` + `TaskService` reschedule | 23 |
| F15 | P3 | **Chip status proyek tidak ikut fase**: "Berjalan · menunggu PM" saat langkah header "Lengkapi bahan… · Pengawas → PM" / "giliran Pengawas". | Header Detail Proyek | 22 |
| F16 | P3 | **Dua tombol "TM Selesai"** dengan status berbeda: header aktif, tombol di kartu TM nonaktif walau 6/6 syarat. | Kartu TM (`TechnicalMeeting…Card`) | 22 |
| F17 | P3 | **Audit Trail**: aksi `finance.budget_overrun_requested` / `finance.budget_overrun_approved` tampil sebagai kunci mentah (belum ada label). | `Pages/AuditLogs/Index.tsx` (peta label ±37) | Tahap 9 |
| F18 | — | ~~Menu **"Form Harian"** masih ada di sidebar Tukang~~ — **tetap** (Q3: user memilih nama "Form Harian"). | `AppLayout.tsx:251` | 23 |
| F19 | P3 | Masih ada ±20 `confirm()` bawaan browser di luar alur klien (hapus di Data Master, Material, Aset, Vendor, Portofolio, Struktur SDM…). | `grep -rn "confirm(" resources/js` | — |

### Dokumen tertinggal (`simulasi-alur-klien.md`)

| # | Dokumen | Kenyataan |
|---|---|---|
| D1 | Tab "Alokasi Dana", "Timeline", "Keuangan → termin DP" | 6 tab Sprint 26: Ringkasan · Eksekusi · Timeline & QC · Daily Target · Material · Keuangan; Alokasi Dana = **Keuangan → Anggaran**; termin = **Keuangan → Termin & Invoice** |
| D2 | Meja bar: P 4 · T 1,1 · Volume 1 · unit | Mengisi P & T otomatis menghitung 4,4 (P × T/L untuk semua satuan selain m/lari) — volume harus diketik 1 (beralih ke manual), atau P/T dikosongkan |
| D3 | Gambaran alur: Invoice DP **sebelum** Buka Proyek; Langkah 19–20: **sesudah** | Keduanya bisa (Sprint 17 Sub 06: DP sebelum buka → ditempel ke termin). Marketing juga langsung mendapat "Terbitkan Invoice DP" setelah klien setuju |
| D4 | "Salin link klien", "Tandai Follow-up Selesai", "Simpan RAB", "Kirim Bukti Bayar", "Catatan surat" | UI: "Salin Link", "Tandai Selesai", "Simpan sebagai Draft", "Tandai Klien Sudah Bayar", "Catatan untuk Klien" |
| D5 | Langkah 23: PM "mengubah ke Selesai" (tidak disebut di mana) | Menu **Tugas** → "Ubah Status Task" (setelah F2: lewat Perlu Tindakan) |
| D6 | Langkah 22: Gantt, "Tambah Timeline" di kartu TM | Ada di kartu TM langkah 2 **dan** Timeline & QC; tanggal mulai otomatis = hari setelah timeline sebelumnya, Tanggal Selesai Proyek otomatis = selesai timeline terakhir |
| D7 | Langkah 23: Geser Tanggal "menu baris task" | Daily Target › Per Hari: seret kartu / ketuk kartu → "Pindah ke sini" |

## 2. Keputusan (terkunci 2026-10-20 — jawaban §4)

| # | Keputusan |
|---|---|
| K1 | **F1** — `DatePicker`: abaikan `onSelect(undefined)` (kalender `required`), jadi klik tanggal yang sama tidak mengosongkan nilai. Kolom opsional yang memang perlu dikosongkan diberi tombol "Hapus tanggal" eksplisit (prop `clearable`). Satu perbaikan di komponen bersama — semua pemakai ikut. |
| K2 | **F2** — `ActionInboxService`: antrean baru **"Daily target minta dicek"** untuk PM/Asisten PM (scope proyek yang dikelola, `Project::isManagedBy()`), tujuan menu Tugas dengan saringan status PENGECEKAN; badge ikut otomatis (`navBadges`). Query memakai scope halaman Tugas, bukan query kedua. |
| K3 | **F3** — Peringatan dibandingkan dengan **total item RAB sebelum diskon** (`items_total`), bukan total RAB. Diskon ditampilkan sebagai baris informasi ("Diskon Rp 2.000.000 menjadi pengurang margin, tidak dibebankan ke pos"), bukan peringatan. Peringatan hanya bila total pos > total item (seharusnya tidak mungkin) atau masih ada item belum dialokasikan. |
| K4 | **F4** — Opsi status pakai label yang sama dengan Tukang (satu peta label di `types`/`lib`, dipakai `TaskStatusDialog`, `TaskActionSheet`, `StatusChip domain="task"`); prioritas pakai label Indonesia (Tinggi / Sedang / Rendah). |
| K5 | **F5, F6** — `ConfirmDialog` untuk "Gambar Kerja Selesai" dan "Setujui Berangkat ke Lokasi" (deskripsi: fase berikutnya, siapa yang diberi tahu). |
| K6 | **F7** — Giliran termin `INVOICED` diturunkan dari status invoice-nya (DITERBITKAN → Klien, MENUNGGU_VERIFIKASI → Finance) — satu peta di `waitingOn.ts`, baris termin mengirim status invoice. |
| K7 | **F8** — Kolom Jadwal termin lunas: tanggal lunas ("Lunas 20/10/2026"); pemicu tercapai tapi belum ditagih: "Pemicu tercapai"; belum: "Menunggu pemicu · {pemicu}". |
| K8 | **F9** — Judul notifikasi & dialog memakai `Termin::invoiceType()->label()` / `paymentTypeLabel()` ("Terbitkan Invoice Pelunasan", "Terbitkan Invoice Termin 2 dari 3 · 30%"). |
| K8b | **Q2 = boleh** — Marketing tetap boleh menagih termin sebelum pemicunya tercapai (aturan tidak berubah). Dialog terbitkan invoice hanya memberi keterangan "Pemicu belum tercapai ({pemicu})" — informasi, tidak memblokir. |
| K9 | **F10** — Start Produksi (DP Pengawas disetujui PM) menandai timeline pertama yang belum selesai menjadi IN_PROGRESS (lewat `MilestoneService`, bukan update langsung). Proyek lama tidak di-backfill. |
| K10 | **F11** — QA form menampilkan `Notice tone="warning"` "n temuan finishing masih terbuka di timeline ini" + tautan ke kartu Temuan, dan tombol Setujui QA nonaktif selama ada (aturan server tetap). |
| K11 | **F12** — Teks catatan: "Pembayaran mengikuti skema pembayaran di atas." (cek posisi di PDF dan link klien — keduanya dari `LetterDocument`). |
| K12 | **F13** — Dialog yang menerima objek lewat prop menyimpan objek terakhir selama animasi tutup (pola `useLastDefined`), dipakai di dialog Bayar Pengawas dan ConfirmDialog timeline. |
| K13 | **F14** — Geser/tukar ke hari Minggu ditolak di server (hari kerja dari `DailyFormSchedule`) dan tidak ditawarkan di papan. |
| K14 | **F15, F16** — Chip status proyek ACTIVE memakai pemegang giliran fase (`waitingOn('projectPhase')`, sama dengan stepper). Tombol TM Selesai hanya satu (header); kartu TM menampilkan status syarat + "TM Selesai ada di atas". |
| K15 | **F17** — Label audit: "Pengajuan overrun anggaran", "Overrun anggaran disetujui", "Overrun anggaran ditolak". |
| K16 | **F19** — Ganti `confirm()` sisanya dengan `ConfirmDialog tone="danger"` (sub terpisah, mekanis — Q5 = ya). |
| K17 | **Q1 = tetap boleh (untuk sekarang)** — `MilestoneService::markDone()` tidak diubah: PM boleh "Tandai Selesai" timeline walau ada daily target belum Selesai. Dicatat sebagai keputusan terbuka untuk ditinjau lagi; dokumen simulasi tidak lagi menulis "semua task sudah Selesai" sebagai syarat. |
| K18 | **Q3 = "Form Harian"** — menu Tukang tetap bernama "Form Harian", tidak diubah. |

## 3. Di luar cakupan

- Aturan bisnis Sprint 23–27 (syarat DP, 70%, penalti, bergulir, akses role, Tandai Selesai timeline, tagih termin lebih awal) — tidak berubah (Q1, Q2).
- Mengganti nama / menghapus menu "Form Harian" (Q3).
- Notifikasi baru selain antrean K2.
- Klik mouse dari ekstensi uji browser yang tidak sampai ke halaman — masalah alat uji, bukan aplikasi.
- Tampilan HP Tukang — tidak teruji di browser (ukuran jendela tidak bisa diubah); diuji ulang di Sub 08.

## 4. Pertanyaan untuk user — **dijawab 2026-10-20**

| # | Pertanyaan | Jawaban user |
|---|---|---|
| Q1 | PM sekarang **bisa "Tandai Selesai" timeline walau daily target di dalamnya belum Selesai** (`MilestoneService::markDone()` tidak memeriksa task). Diblokir? | **Untuk sekarang tetap boleh** → K17 (tinjau lagi nanti) |
| Q2 | Marketing bisa **menerbitkan invoice Termin 2 / Pelunasan sebelum pemicunya tercapai**. Dibiarkan atau dikunci? | **Boleh** → K8b (keterangan saja, tidak memblokir) |
| Q3 | Menu **"Form Harian"** di sidebar Tukang: hapus, atau ganti nama? | **Tetap "Form Harian"** → K18 |
| Q4 | Peringatan anggaran (K3): cukup tampilkan diskon sebagai info? | **Ya** → K3 |
| Q5 | Ganti ±20 `confirm()` lama (K16) ikut sprint ini? | **Ya** → Sub 09 |

## 5. Sub-plan

Satu perintah = satu sub ("Kerjakan Sprint 28 Sub 1"). Urutan: yang
menjebak pengguna dulu.

### Sub 01 — Tanggal tidak bisa dipilih ulang (F1, K1)
- [ ] `DatePicker`: abaikan `onSelect(undefined)`; prop `clearable` + tombol "Hapus tanggal" untuk kolom opsional yang sekarang bisa dikosongkan
- [ ] Cari semua pemakai `DatePicker` (`grep -rn "<DatePicker" resources/js`) — tandai yang opsional sebagai `clearable`
- [ ] Cek di browser: Tambah Timeline → klik tanggal mulai yang disarankan → tetap terisi, Simpan berhasil
- [ ] `npm run build`

### Sub 02 — Antrean "Daily target minta dicek" (F2, K2)
- [ ] Method baru di `ActionInboxService` (scope halaman Tugas + `isManagedBy()`), `routeName` = menu Tugas dengan saringan PENGECEKAN
- [ ] Halaman Tugas menerima `?status=PENGECEKAN` (bila belum)
- [ ] Test Pest: PM proyek melihat item, PM lain / Asisten PM tak ditugaskan tidak; item hilang setelah DONE
- [ ] `npm run build`, Pint

### Sub 03 — Label task & prioritas (F4, K4)
- [ ] Satu peta label status task (`lib/`), dipakai `TaskStatusDialog`, `TaskActionSheet`, halaman Tugas
- [ ] Prioritas berlabel Indonesia di tabel & form
- [ ] `npm run build`

### Sub 04 — Peringatan anggaran berdiskon (F3, K3)
- [ ] `BudgetAllocationTab`: bandingkan `postsTotal` dengan total item (sebelum diskon); diskon jadi baris info
- [ ] Ringkasan backend (`ProjectBudgetService` summary) mengirim `itemsTotal` bila belum ada
- [ ] Test Pest: RAB berdiskon, semua item dialokasikan → tanpa peringatan
- [ ] `npm run build`, Pint

### Sub 05 — Konfirmasi & dialog (F5, F6, F13, K5, K12)
- [ ] `ConfirmDialog` untuk Gambar Kerja Selesai & Setujui Berangkat ke Lokasi
- [ ] Isi dialog tidak kosong saat menutup (Bayar Pengawas, Tandai Selesai timeline)
- [ ] `npm run build`

### Sub 06 — Penagihan konsisten (F7, F8, F9, F12, K6–K8, K11)
- [ ] `waitingOn` termin INVOICED dari status invoice
- [ ] Kolom Jadwal termin: Lunas {tanggal} / Pemicu tercapai / Menunggu pemicu · {pemicu}
- [ ] Judul notifikasi `TerminInvoiceDue` & dialog terbitkan invoice termin dari `invoiceType()` / `paymentTypeLabel()`
- [ ] (K8b) dialog termin yang pemicunya belum tercapai: keterangan "Pemicu belum tercapai ({pemicu})" — tidak memblokir
- [ ] `QuotationLetter` catatan skema "di atas"; cek PDF & link klien
- [ ] Test Pest: judul notifikasi pelunasan, label kolom jadwal
- [ ] `npm run build`, Pint

### Sub 07 — Eksekusi proyek (F10, F11, F14–F17, K9, K10, K13–K15)
- [ ] Start Produksi → timeline pertama IN_PROGRESS (`MilestoneService`)
- [ ] QA form: notice temuan terbuka + Setujui nonaktif
- [ ] Geser/tukar: tolak hari Minggu (server) + tidak ditawarkan di `TaskWeekBoard`
- [ ] Chip status proyek ACTIVE = pemegang giliran fase; satu tombol TM Selesai
- [ ] Label audit overrun
- [ ] Test Pest: reschedule/tukar ke Minggu ditolak; timeline pertama IN_PROGRESS setelah DP disetujui
- [ ] Tidak diubah: `markDone()` (K17), menu "Form Harian" (K18)
- [ ] `npm run build`, Pint

### Sub 08 — Dokumen, test E2E & uji ulang
- [ ] `simulasi-alur-klien.md`: D1–D7 + perubahan sprint ini (antrean minta dicek, konfirmasi Berangkat, keterangan tagih termin lebih awal); Langkah 25 tanpa "semua task sudah Selesai" sebagai syarat (K17)
- [ ] `ClientJourneySimulationTest`: tambah cek K9 (timeline pertama IN_PROGRESS setelah Start Produksi); commit bersama sprint ini
- [ ] Suite Pest penuh, Pint, `npm run build`
- [ ] Uji ulang di browser (database `daiku_interior_sim`) langkah 21–27 + HP 390px untuk Tukang
- [ ] README: status Sprint 28

### Sub 09 — Ganti `confirm()` lama (F19, K16 — Q5 = ya)
- [ ] ±20 lokasi → `ConfirmDialog tone="danger"` (daftar: `grep -rn "confirm(" resources/js`)
- [ ] `npm run build`
