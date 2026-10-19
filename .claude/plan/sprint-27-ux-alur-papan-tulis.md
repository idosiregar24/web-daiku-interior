# Sprint 27 — UX Mengikuti Papan Tulis: Syarat, QC Pengawas, Bahan, H-30%, Target per Task

> Status: **rencana 2026-10-19** — belum dikerjakan. Pertanyaan §5 menunggu
> jawaban user; bila tidak dijawab, dipakai kolom "Usulan".
> Sumber: pertanyaan user 2026-10-19 ("ada lagi UX yang bisa disempurnakan,
> sesuai alur papan tulis kemarin?") → 6 saran Claude + penjelasan user atas
> tulisan kecil di pojok kiri bawah papan. Papan:
> [`sprint-24/papan-tulis-2026-10-13.jpg`](sprint-24/papan-tulis-2026-10-13.jpg),
> transkripsi di [`sprint-24-alur-eksekusi-proyek.md`](sprint-24-alur-eksekusi-proyek.md) §1.
> Di luar PRD. Lanjutan Sprint 24 (alur), 25 (Activity Target), 26 (UX
> Detail Proyek).
>
> **Aturan bisnis tidak berubah** (fase, syarat DP, 70%, penalti, bergulir,
> akses role). Yang berubah: cara alur papan tulis terbaca di layar. Backend
> hanya disentuh untuk menambah data tampilan (P1) dan saringan daftar (P6).

## 1. Permintaan

1. Tampilan proyek harus membaca seperti papan tulis: **Syarat Pengawas**
   (Bahan ✓ · Upah ✓ · Daily target ✓), **Start Produksi → Pemesanan bahan**,
   **QC** (mingguan Jumat · daily target · 70% · finishing di lokasi),
   **Notif H-30%**, **Projek → Pengawas / SCM**.
2. Tulisan kecil di bawah "target – Aktivity / Actual" (penjelasan user
   2026-10-19): **sebuah tabel yang membandingkan target dengan actual
   activity — kolom kiri nama task-nya, kolom kanan actual-nya.**

## 2. Kondisi sekarang (setelah Sprint 26)

| # | Papan | Di sistem | Celah |
|---|---|---|---|
| T1 | Syarat* Bahan ✓ Upah ✓ Daily target ✓ | `SupervisorFeeCard` → `RequirementList` (label + centang) dari `SupervisorPaymentService::dpRequirements()` | Pengawas tahu ada yang kurang, tapi tidak tahu **di mana** memperbaikinya (target mana tanpa upah, timeline mana tanpa target) |
| T2 | QC → mingguan · daily target · 70% · finishing | Kartu terpisah di Eksekusi: Laporan Mingguan, Target vs Actual, Berangkat ke Lokasi, Temuan | Tidak terbaca sebagai satu tugas "QC" Pengawas; sub-tab "Pemeriksaan QC" (Timeline & QC) hanya QA timeline — kata "QC" berarti dua hal |
| T3 | Start Produksi → Pemesanan bahan (Logistik) | Logistik diberi notifikasi; status bahan hanya di tab Material | PM/Pengawas tidak melihat di Eksekusi apakah bahan sudah dipesan/datang |
| T4 | Notif H-30% (ke PM, berulang) | Notifikasi harian + `Notice` di tab Eksekusi | Ringkasan & stepper tidak memberi tanda; PM harus membuka Eksekusi |
| T5 | target – Aktivity/Actual: **kiri nama task, kanan actual** | Activity Target (Sprint 25) = **angka per hari** tanpa nama task (sengaja, A6/A7); Target vs Actual (Eksekusi) = jumlah per tukang per hari | Belum ada tabel **per task**: target apa → actual-nya bagaimana |
| T6 | Projek → Pengawas / SCM | Daftar proyek: chip fase, Actual Activity | Pengawas/Logistik yang memegang banyak proyek tidak bisa menyaring "yang saya awasi" / per fase, tidak terlihat giliran siapa |
| T7 | Bahan … "bisa diedit" | Persiapan: kartu tautan ke tab Material (Sprint 26, diterima user) | Planner tidak bisa dipakai langsung di Eksekusi setelah TM |

## 3. Keputusan (usulan Claude, dikunci setelah §5 dijawab)

### P1 — Syarat Pengawas yang bisa ditindaklanjuti (T1)

| # | Keputusan |
|---|---|
| P1a | `dpRequirements()` tiap baris ditambah **`detail`** (teks pendek) dan **`missing`** (daftar kecil, maks. 5 + "dan n lainnya"): Bahan → `[]` (cukup "belum ada rencana bahan"); Upah → judul + tanggal target tanpa `rate_per_task`; Daily target → nama timeline tanpa target. Hanya untuk viewer yang sudah menerima `dpRequirements` sekarang; judul task hanya bila `taskVisibility = all` (Pengawas proyek/PM — PRD §4.6), selain itu hanya jumlah. Aturan `requestDp()` tidak berubah. |
| P1b | Kartu **"Syarat DP Pengawas"** sendiri di fase PERSIAPAN (di atas Jasa Pengawas): tiga baris besar seperti papan — ✓/○ + label + `detail` + tombol **"Lengkapi →"**: Bahan → `#tm-bahan` (kartu Rencana Bahan) / tab Material · Upah → Daily Target › Per Tukang dengan sorotan target tanpa upah (`?view=tukang&upah=kosong`, filter klien) · Daily target → Daily Target › Per Hari pada minggu timeline pertama yang kosong. Semua lengkap → tombol **Ajukan DP** (yang sudah ada) pindah ke kartu ini. |
| P1c | `SupervisorFeeCard` tidak lagi menampilkan `RequirementList` (satu tempat). |

### P2 — Panel "QC Pengawas" (T2)

| # | Keputusan |
|---|---|
| P2a | Kartu **"QC Pengawas"** di Eksekusi fase PRODUKSI & PEMASANGAN, di atas kartu-kartu lain: 4 baris urut papan — **Pengecekan mingguan (Jumat)**: status laporan minggu ini (belum / diajukan / disetujui / ditolak), "hari ini Jumat" bila `currentWeek.is_report_day` · **Pengecekan daily target**: hari ini target n · selesai m · bergulir k (dari `targetVsActual` minggu berjalan) · **Pengecekan 70%**: progres x% / threshold, ✓ bila berangkat disetujui · **Finishing di lokasi** (PEMASANGAN): temuan terbuka n. Tiap baris: chip status + "Buka →" ke kartunya (`#laporan-mingguan`, `#target-vs-actual`, `#berangkat`, `#temuan`). Data semua sudah di `execution` — tanpa query baru. |
| P2b | Penamaan: sub-tab Timeline & QC **"Pemeriksaan QC" → "QC Timeline"** supaya beda dengan panel ini; isinya tetap. |
| P2c | Baris yang menunggu Pengawas (laporan Jumat belum diisi, temuan terbuka) ditandai `bg-warning/10` — sama dengan "Giliran Anda" di Ringkasan (`projectWaiting`), tanpa logika baru. |

### P3 — Pemesanan Bahan setelah Start Produksi (T3)

| # | Keputusan |
|---|---|
| P3a | Kartu kecil **"Pemesanan Bahan"** di Eksekusi fase PRODUKSI (dan riwayat di PEMASANGAN): "n dari m bahan diterima · p dipesan · q belum" + `ProgressBar`, daftar 3 bahan yang belum datang, tautan "Lihat Material →". Dihitung dari prop `projectMaterials` (sudah dikirim ke yang boleh melihat Material); viewer tanpa prop itu tidak melihat kartunya. |
| P3b | Definisi status baris memakai field yang sudah dipakai `ProjectMaterialsPanel` (sumber, `qty_received` vs `qty_planned`, status pengajuan) — fungsi kecil bersama, bukan hitungan kedua yang berbeda. |
| P3c | Ringkasan: baris menunggu "Bahan belum datang · Logistik" bila fase PRODUKSI dan ada bahan belum diterima > 0 (holder Logistik, `mine` = `materialPermissions.receive`). |

### P4 — H-30% terlihat tanpa membuka Eksekusi (T4)

| # | Keputusan |
|---|---|
| P4a | Ringkasan › Informasi Proyek: baris "sisa n hari" → `text-warning-ink` + "· masa H-30%" saat `execution.deadline.warning`; lewat → `text-error-ink` (sudah ada). |
| P4b | `ProjectPhaseStepper`: titik "Selesai" diberi catatan kecil "sisa n hari" (warning) / "lewat n hari" (error) saat `deadline.warning`. Viewer tanpa `execution` tidak melihatnya. |
| P4c | Baris "Giliran Anda" untuk PM saat masa H-30%: "Mendekati Tanggal Selesai — actual x%" (target `execution#…` paling relevan: target vs actual). Tidak ada notifikasi baru (notifikasi H-30% Sprint 24 D13 tetap). |

### P5 — Tabel Target per Task: kiri target, kanan actual (T5, catatan papan)

| # | Keputusan |
|---|---|
| P5a | Section baru **"Target vs Actual per Task"** di Daily Target › Activity Target, di bawah tabel angka Sprint 25 (atau sub-tab sendiri — Q2). Satu baris per daily target: **kiri (Target)** = judul task · tukang · timeline · hari target; **kanan (Actual)** = ✓ Selesai {tanggal, jam} (+ "lebih cepat n hari" / "terlambat n hari" vs hari target) · ↻ Bergulir ×n → sekarang {due_date} · Terlambat (OVER) · Belum · kendala (bila ada). |
| P5b | Dikelompokkan per **hari target**, per minggu (minggu berjalan terbuka, minggu lama terlipat — sama dengan tabel Sprint 25 A4); saringan tukang & status (Semua / Belum / Selesai / Bergulir / Terlambat). |
| P5c | **Definisi = Sprint 25 A5**: hari target = `planned_date ?? due_date`; actual = `completed_at` (dibaca dalam **WIB**, `Asia/Jakarta`, bukan zona browser — pelajaran bug tanggal Sprint 24); bergulir = `rollover_count`. Jumlah per hari di tabel ini harus sama dengan kolom Daily Target / Actual tabel angka (diuji dengan data demo). |
| P5d | **Akses**: hanya bila halaman menerima `tasks` (`canViewTasks` — CEO, PM, Asisten PM proyeknya, Pengawas proyek ini, SUPERADMIN). QA lain, Marketing, Finance, Desainer tetap hanya angka (PRD §4.6, Sprint 25 A7). Tukang tidak menerima tab ini. Data dari prop `tasks` yang sudah ada — **tanpa prop/query baru**. |
| P5e | HP: kartu per task, dua bagian berjajar "Target | Actual" (kiri-kanan tetap terbaca seperti papan), pola `TableCard` (`md:hidden` / `hidden md:table`). |

### P6 — Daftar proyek untuk Pengawas & Logistik (T6)

| # | Keputusan |
|---|---|
| P6a | `Projects/Index`: saringan **Fase** (5 fase + Selesai) dan, untuk QA, **"Yang saya awasi"** (default aktif untuk QA; `supervisor_id = saya`). Query string + backend (`ProjectController::index`), pola DataTable toolbar yang sudah ada. |
| P6b | Kolom Status: chip status + **mini-stepper** (6 segmen, `ProjectPhaseStepper` mode ringkas — satu komponen) + "giliran {pemegang}" dari `waitingOn('projectPhase')`. HP: `mobileCard` yang sama. |
| P6c | Tidak ada perubahan akses daftar (scope `visibleTo()` tetap). |

### P7 — (opsional) Rencana bahan bisa diedit di Persiapan (T7)

Hanya bila user memintanya (Sprint 26 sudah menerima kartu tautan):
`ProjectExecutionService` mengirim `meetingMaterials` + `planMaterials` /
`updateMaterials` / `removeMaterials` juga di PERSIAPAN (izin revisi Pengawas
sudah berlaku sampai PRODUKSI — `reviseMaterialPlan`); kartu Rencana Bahan
memakai `TmMaterialPlanner`.

## 4. Di luar cakupan

- Mengubah aturan syarat DP, laporan mingguan, 70%, temuan, H-30%.
- Notifikasi baru (P4 hanya tampilan).
- Bobot task per upah (E5 tetap).
- Halaman Hari Ini / Tugas Tukang.

## 5. Pertanyaan untuk user

| # | Pertanyaan | Usulan bila tidak dijawab |
|---|---|---|
| Q1 | Tombol **Ajukan DP** pindah ke kartu Syarat (P1b), atau tetap di Jasa Pengawas? | Pindah — syarat dan tombolnya satu tempat |
| Q2 | Tabel per task (P5) di bawah tabel angka dalam **Activity Target**, atau **sub-tab sendiri** di Daily Target ("Target per Task")? | Di bawah tabel angka, dalam Activity Target — satu tempat untuk "target vs actual" |
| Q3 | Nama tabelnya: "Target vs Actual per Task" atau lainnya? | "Target vs Actual per Task" |
| Q4 | "Pemeriksaan QC" → "QC Timeline" (P2b)? | Ya |
| Q5 | Saringan "Yang saya awasi" default **aktif** untuk QA di daftar proyek? | Ya |
| Q6 | P7 (planner di Persiapan) dikerjakan juga? | Tidak — kecuali diminta |

## 6. Sub-plan

Satu perintah = satu sub ("Kerjakan Sprint 27 Sub 1"). Urutan dari yang
paling dekat dengan papan & risiko terendah.

### Sub 01 — Tabel Target vs Actual per Task (P5)
- [ ] Fungsi murni `taskTargetRows(tasks)` (hari target, actual WIB, selisih hari, bergulir) di `Components/modules/projects/`
- [ ] Section/komponen tabel + kartu HP, saringan tukang & status, minggu terlipat
- [ ] Hanya bila `canViewTasks`; cek jumlah per hari = tabel angka Sprint 25 pada data demo
- [ ] `npm run build`

### Sub 02 — Syarat DP Pengawas (P1)
- [ ] `SupervisorPaymentService::dpRequirements()` + `detail`/`missing` (judul task hanya `taskVisibility = all`); tipe `ExecutionRequirement`
- [ ] Kartu Syarat DP di PERSIAPAN, tombol "Lengkapi →" (+ sorotan upah kosong di Per Tukang), Ajukan DP pindah (Q1)
- [ ] Test Pest: isi `missing` per syarat; QA lain tidak menerima judul task; `requestDp()` tetap menolak syarat kurang
- [ ] `npm run build`, Pint

### Sub 03 — Panel QC Pengawas (P2)
- [ ] Kartu QC Pengawas (4 baris) di PRODUKSI/PEMASANGAN, tautan ke kartu, tanda "menunggu Pengawas"
- [ ] "Pemeriksaan QC" → "QC Timeline" (Q4) + simulasi dokumen
- [ ] `npm run build`

### Sub 04 — Pemesanan Bahan (P3)
- [ ] Fungsi status baris bersama (dipakai `ProjectMaterialsPanel` & kartu)
- [ ] Kartu Pemesanan Bahan di PRODUKSI (+ riwayat), baris Ringkasan "Bahan belum datang · Logistik"
- [ ] `npm run build`

### Sub 05 — H-30% di Ringkasan & stepper (P4)
- [ ] Informasi Proyek warning, catatan di titik Selesai stepper, baris Giliran Anda PM
- [ ] `npm run build`

### Sub 06 — Daftar proyek: fase, "yang saya awasi", mini-stepper (P6)
- [ ] `ProjectController::index` saringan `phase` + `supervised` (QA), default Q5; test Pest (QA melihat proyek awasannya, saringan fase, RBAC tetap)
- [ ] `ProjectPhaseStepper` mode ringkas di kolom Status + `mobileCard`
- [ ] `npm run build`, Pint

### Sub 07 — (opsional, bila Q6 = ya) Planner bahan di Persiapan (P7)

### Sub 08 — Uji & dokumen
- [ ] Suite Pest penuh, Pint, build
- [ ] Uji browser per role (PM, Pengawas, QA lain, Logistik, Finance, Marketing) + HP 390px
- [ ] `CLAUDE.md` aturan 17 (satu kalimat: syarat DP dengan "Lengkapi", QC Pengawas, tabel per task hanya untuk yang melihat task); `simulasi-eksekusi-proyek.md`
- [ ] README: status Sprint 27
