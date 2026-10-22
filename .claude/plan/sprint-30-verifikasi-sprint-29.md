# Sprint 30 — Verifikasi Sprint 29: Kecepatan, Browser per Role & Security Review

> Status: **belum dikerjakan** · Rencana 2026-10-21 · Di luar PRD; lanjutan
> [Sprint 29](sprint-29-revisi-pengawas-upah-tukang.md).
>
> Sprint 29 selesai di tingkat kode (Sub 01–14, 08b dibatalkan; 2380 test
> lulus, build & Pint bersih). Sub 15 menyisakan tiga pemeriksaan yang tidak
> bisa dilakukan lewat test otomatis — dipindah ke sprint ini supaya punya
> checklist dan status sendiri. Tidak ada fitur baru; perubahan kode hanya
> untuk memperbaiki temuan (Sub 04).

## 1. Ruang lingkup

| Sub | Isi | Bergantung | Dikerjakan oleh |
|---|---|---|---|
| 01 | Ukur ulang kecepatan simpan semua form baru Sprint 29 (Telescope, MySQL + data demo) | — | Claude (butuh MySQL Laragon menyala) |
| 02 | Cek browser per role — desktop + HP 390 px, checklist UX Sprint 29 §5c | — | Claude via browser (Claude in Chrome) atau user |
| 03 | `/security-review` — pembayaran upah, harga RAB ke Pengawas, Admin Proyek, Aktivitas Harian, QA per item | Sprint 29 di-commit | User menjalankan perintahnya; Claude menindaklanjuti |
| 04 | Perbaikan temuan Sub 01–03, tutup Sprint 29 | 01–03 | Claude |

## 2. Persiapan (sekali, dipakai Sub 01 & 02)

- [ ] Commit pekerjaan Sprint 29 dulu (Sub 03 membaca diff branch; Sub 01–02
      mengukur kode yang sudah dibekukan). Pesan commit disiapkan Claude,
      user yang menjalankan `git commit`.
- [ ] Database terpisah supaya data dev tidak tertimpa (pola Sprint 28):
      `DB_DATABASE=daiku_interior_sim` → `php artisan migrate:fresh --seed`.
      Data demo memuat proyek per tahap, termasuk Hendra Gunawan (dua
      Pengawas, pengecekan menunggu PM) dan Dewi Lestari (QC Item).
- [ ] `composer dev` (server :8010 + worker + scheduler + vite) — `localhost`
      dipakai supaya kamera HP & Web Push jalan (origin aman).
- [ ] Telescope aktif (`TELESCOPE_ENABLED=true`) untuk Sub 01.
- [ ] Akun (password `password`): `pm`, `qa`, `qa2`, `adminproyek`,
      `fieldstaff`, `logistics`, `finance`, `hr`, `ceo`, `marketing`.

---

## Sub 01 — Ukur ulang kecepatan simpan

Target Sprint 29 §5b: simpan di Detail Proyek **< 300 ms** di server, overlay
"Menyimpan…" **tidak** muncul untuk isian berulang (reload sebagian
`projectVisit`), tombol terkunci + spinner selama menyimpan. Angka "sebelum"
ada di [`sprint-29/01-kecepatan-simpan.md`](sprint-29/01-kecepatan-simpan.md)
§Pengukuran (diukur di SQLite karena MySQL mati waktu itu).

Cara: Telescope → Requests, proyek demo terbesar, median 5× tiap aksi; catat
durasi request, jumlah query, ukuran respons, dan prop yang dikirim balik
(`X-Inertia-Partial-Data`).

| # | Aksi | Role | Route | Durasi | Query | Overlay? |
|---|---|---|---|---|---|---|
| 1 | Tambah bahan (satu baris) | Pengawas | `projects.materials.store` | | | |
| 2 | Tambah bahan "Simpan semua" (5 baris) | Pengawas | `projects.materials.storeMany` | | | |
| 3 | Rencana bahan TM → Ajukan ke Logistik | Pengawas | `projects.material-plans.submit` | | | |
| 4 | Satuan Upah satu baris | Pengawas | `projects.work-items.update` | | | |
| 5 | Kepala Tim "Terapkan ke bagian ini" | Pengawas | `projects.work-items.teamLead` | | | |
| 6 | Draf pengecekan (otomatis 1,5 dtk) | Pengawas / Admin Proyek | `projects.work-check.draft` | | | |
| 7 | Kirim Pengecekan | Pengawas | `projects.weekly-reports.store` | | | |
| 8 | Setujui / Centang target | Pengawas | `tasks.approve` / `tasks.checkOff` | | | |
| 9 | Setujui semua (satu hari) | Pengawas | `tasks.approveMany` | | | |
| 10 | Geser kartu (Per Hari) | Pengawas | `tasks.reschedule` | | | |
| 11 | Buat daily target "Simpan & buat lagi" | Pengawas | `tasks.store` | | | |
| 12 | Simpan TM | PM / Admin Proyek | `projects.technical-meeting.save` | | | |
| 13 | Lolos QA (n item) | Pengawas | `projects.item-qa.decide` | | | |
| 14 | Perlu perbaikan + foto | Pengawas | `projects.item-qa.decide` | | | |
| 15 | Aktivitas harian (tambah) | semua kecuali Tukang | `daily-activities.store` | | | |
| 16 | Periksa aktivitas (HR) | HR | `daily-activities.review.store` / `.review.bulk` | | | |
| 17 | Logistik: proses baris pemesanan | Logistik | `logistics.material-orders.decide` / `.order` | | | |

### Checklist
- [ ] Ukur 17 aksi di atas, isi tabel
- [ ] Aksi > 300 ms atau overlay muncul padahal isian berulang → catat
      penyebab (prop yang ikut dimuat, N+1, query per baris) di §5 Temuan
- [ ] Bandingkan dengan angka Sprint 29 Sub 01; salin ringkasannya ke
      `sprint-29/01-kecepatan-simpan.md` §Pengukuran dan centang `[Ukur]` di sana

---

## Sub 02 — Cek browser per role (desktop + HP 390 px)

Checklist UX Sprint 29 §5c tiap halaman: (1) tanpa menunggu, (2) satu langkah
berikutnya jelas, (3) HP dulu — 390 px, panel bawah, tombol ≥ 48 px,
(4) bahasa membumi, (5) aman — ConfirmDialog, bintang wajib, MoneyInput,
(6) kosong & salah — EmptyState, Notice dengan tautan, (7) console bersih.
Alur lengkapnya mengikuti [`simulasi-eksekusi-proyek.md`](simulasi-eksekusi-proyek.md).

| Role | Halaman / bagian yang dicek | HP? |
|---|---|---|
| **Pengawas** (`qa`, `qa2`) | Perlu Tindakan (TM, daily target menunggu dicek, item menunggu QA); kartu TM bahan + Ajukan ke Logistik; Upah Tukang; Syarat DP Upah; Daily Target › Per Hari (Setujui/Kembalikan/Centang, Setujui semua, seret & Pindah ke sini, hari Minggu); Pengecekan Mingguan (tombol +10/+20/+50/100, draf otomatis, Kirim · Ajukan); Temuan Finishing; **QC Item** (Lolos banyak, Periksa + **foto kamera**, Jadikan Task Perbaikan, Sudah Diperbaiki); menu Tugas | ✔ |
| **Admin Proyek** | Perlu Tindakan ("Proyek menunggu input", "Daily target menunggu dicek"); isi TM tanpa tombol TM Selesai; Upah Tukang; draf pengecekan tanpa tombol Kirim; QC Item hanya baca; tidak ada termin/dokumen/anggaran | ✔ |
| **Tukang** (`fieldstaff`, `budi`) | Hari Ini → ✔ Selesai jadi "Minta dicek"; target dikembalikan + catatannya; task perbaikan; tidak ada Aktivitas Harian | ✔ |
| **Logistik** | Pemesanan Bahan (status per baris, proses banyak baris), Material proyek; tidak melihat harga RAB / upah | ✔ |
| **PM** | Header & stepper langkah berikutnya; TM Selesai; dialog keputusan DP & pengecekan (rincian % dan per Kepala Tim, menurunkan nominal, tolak + alasan); Berangkat ke Lokasi; Tandai Selesai timeline tanpa QA Form; QC Item hanya baca; progres berbobot di daftar/dashboard | desktop |
| **Finance** | Keuangan → Upah Tukang (rincian per Kepala Tim, Bayar) | desktop |
| **HR** | Periksa Aktivitas (hari kerja sebelumnya, Sesuai / Perlu klarifikasi / Tidak mengisi, sekaligus banyak); KPI dari aktivitas | desktop |
| **CEO** | Audit Trail (label baru, filter area); Analytics/progres berbobot | desktop |
| Semua role non-Tukang | Aktivitas Harian (tambah beberapa, hari ini saja) | ✔ |

### Checklist
- [ ] Pengawas — desktop + HP 390 px
- [ ] Admin Proyek — desktop + HP
- [ ] Tukang — HP
- [ ] Logistik — desktop + HP
- [ ] PM — desktop
- [ ] Finance — desktop
- [ ] HR — desktop
- [ ] CEO — desktop
- [ ] Aktivitas Harian — HP (satu role non-Tukang)
- [ ] Console browser bersih (tanpa error / warning React) di semua halaman di atas
- [ ] Temuan → §5, dengan role, halaman, ukuran layar, langkah ulang
- [ ] Centang `[UX]` yang masih terbuka di `sprint-29/01, 09, 11, 12, 13` dan ubah status sub Sprint 29 "tunggu cek browser" → selesai

---

## Sub 03 — Security review

Jalankan **`/security-review`** (perintah user) setelah Sprint 29 di-commit.
Fokus yang harus disebut ke reviewer:

| Area | Yang diperiksa | Kode |
|---|---|---|
| Pembayaran upah | Nominal DP / pencairan / pelunasan tidak bisa melebihi total, tidak bisa diputuskan dua kali, hanya PM proyek yang memutuskan, hanya Finance yang membayar; pengecekan % append-only (Σ 0–100, koreksi minus + catatan); kunci baris proyek saat menghitung | `SupervisorPaymentService`, `WorkItemService` (`payableNow`, `recordProgress`), `WeeklyReportService` |
| Harga RAB ke Pengawas | `execution.workItems` & `workCheck` hanya untuk CEO/PM/Finance/Pengawas proyek + Admin Proyek; tidak ke Logistik, Marketing, Tukang, QA lain | `ProjectExecutionService::overview()`, `ProjectController::show()` |
| Admin Proyek | Hanya route input; 403 untuk TM Selesai, DP, pencairan, Berangkat, Tandai Selesai timeline, QA, anggaran, Buka Proyek, RAB, pembayaran; props termin/dokumen/anggaran tidak dikirim | `routes/web.php`, `ProjectPolicy`, `TaskPolicy` |
| Banyak Pengawas | Setiap Pengawas setara, QA lain tidak bisa apa-apa di proyek itu | `Project::isSupervisedBy()`, `isQaReviewableBy()` |
| QA per item | Keputusan hanya Pengawas; unggah foto (tipe, ukuran, decode ulang WebP tanpa EXIF), foto hanya lewat route ber-policy, path tidak bocor | `WorkItemQaService`, `WorkItemQaController::photo`, `DecideWorkItemQaRequest` |
| Aktivitas Harian | Hanya milik sendiri & hari itu; pemeriksaan HR append-only; PM/CEO hanya baca | `DailyActivityService`, `DailyActivityPolicy` |
| Bahan custom → Logistik | Pengawas tidak melihat stok/harga gudang; status baris hanya berubah lewat service | `ProjectMaterialService`, `MaterialRequestService` |

### Checklist
- [ ] Sprint 29 di-commit (§2)
- [ ] User menjalankan `/security-review`
- [ ] Setiap temuan → §5 dengan tingkat (Tinggi / Sedang / Rendah) dan keputusan (perbaiki / terima + alasan)
- [ ] Hapus "& `/security-review`" dari status `sprint-29/09-dp-pencairan-upah.md`

---

## Sub 04 — Perbaikan temuan & penutupan Sprint 29

### Checklist
- [ ] Perbaiki temuan §5 berprioritas P1/P2 dan temuan keamanan Tinggi/Sedang (test + build tiap perbaikan)
- [ ] Temuan P3 / Rendah: perbaiki atau tulis alasan tidak diperbaiki
- [ ] Ulang ukur / cek browser hanya untuk bagian yang berubah
- [ ] Suite Pest penuh, `npm run build`, Pint
- [ ] Status Sprint 29 di `plan/README.md` → **selesai**; Sub 15 dicentang
- [ ] Status Sprint 30 → selesai

---

## 5. Temuan

Diisi selama Sub 01–03. Prioritas: **P1** = pengguna terjebak / uang salah /
data bocor · **P2** = lambat (> 300 ms) atau tampilan salah · **P3** = kosmetik.

| # | Sub | Prio | Temuan | Di mana | Keputusan |
|---|---|---|---|---|---|
| | | | | | |
