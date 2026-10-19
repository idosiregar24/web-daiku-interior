# Sprint 24 — Alur Eksekusi Proyek: Gambar Kerja → TM → Pengawas → Produksi → QC

> Status: **kode selesai 2026-10-15** (Sub 01–12) — 2187 test lulus (40
> test baru di `tests/Feature/Projects/ExecutionFlowTest.php`), `npm run build`
> & Pint bersih, 6 migrasi jalan di DB dev, seed demo penuh lulus, tab Eksekusi
> dicek di browser, `/security-review` bersih (§7). Tersisa: uji alur
> per role di browser oleh user, deploy (migrasi baru). Hasil & keputusan
> tambahan: §7. Plan dikunci 2026-10-13 (P1–P16, E1–E4; E5 = usulan).
>
> Sumber: foto papan tulis ([`sprint-24/papan-tulis-2026-10-13.jpg`](sprint-24/papan-tulis-2026-10-13.jpg))
> + cerita & jawaban user 2026-10-13. Di luar PRD — PRD §4.5/§4.6 (Task, QA),
> Sprint 3 (Daily Form, penalti) dan Sprint 9/12 menjadi dasar yang diubah.

## 1. Papan tulis (bacaan dikoreksi user)

```
Projek ─▶ Desain ─▶ Gambar Kerja ✓  (langsung final, tanpa persetujuan)
      ─▶ Pengawas (QA/QC)            │
      ─▶ SCM (= Logistik)            ▼
                                    TM ─▶ Durasi (Timeline)          — PM
                                       ─▶ Bahan yang digunakan       — Pengawas
                                       ─▶ DP 10% ─▶ Finance ─▶ Pengawas
                                            ▲ "bisa diedit"
Pengawas
  Syarat* ─ Bahan ✓ · Upah ✓ · Daily target ✓

Start Produksi ─▶ Pemesanan bahan (Logistik)
────────────────────────────────────────────────────────────
QC ─▶ Pengecekan mingguan — Jum'at (Pengawas isi → PM approve → Finance)
   ─▶ Pengecekan daily target
   ─▶ Pengecekan 70% (dari actual activity) → PM approve berangkat ke lokasi
   ─▶ Pengecekan finishing di lokasi (temuan → task perbaikan)
   ─▶ Notif H-30% ke PM (berulang)

Target ─ Activity / Actual   (per tukang per hari)
```

## 2. Alur

| Tahap (fase) | Menunggu | Isi |
|---|---|---|
| 0. Buka Proyek | PM peng-ACC RAB | (Sprint 23) PM, Kepala Tim (karyawan / orang luar), **Pengawas** (user QA/QC), tanggal buka / mulai / selesai |
| 1. `GAMBAR_KERJA` | Kepala Desain → Arsitek | Proyek baru otomatis masuk tim desain sebagai tugas **gambar kerja**; Kepala Desain menugaskan; arsitek menyimpan link → **"Gambar Kerja Selesai"** (tanpa persetujuan — hasil tim desain sudah final) |
| 2. `TM` | PM + Pengawas | Technical Meeting semua divisi. **PM**: tanggal TM, peserta, notulen, **Timeline** (mulai–selesai), **nilai jasa pengawas + DP %** (default 10%, bisa diedit). **Pengawas**: **bahan** yang digunakan. PM menekan **"TM Selesai"** |
| 3. `PERSIAPAN` | Pengawas → PM → Finance | Pengawas melengkapi syarat: **bahan ✓ · upah ✓ · daily target ✓** → **"Ajukan DP Pengawas"** → **PM approve** → Finance membayar. Disetujui PM = **Start Produksi** |
| 4. `PRODUKSI` | Pengawas + Tukang | Logistik memesan bahan. Daily Target vs Actual Activity, task bergulir, geser dalam timeline. Laporan mingguan Jumat → PM → Finance |
| 5. Cek 70% | PM | Actual activity ≥ 70% → notifikasi PM → **"Setujui Berangkat ke Lokasi"** (terkunci < 70%) |
| 6. `PEMASANGAN` | Pengawas | Pengecekan finishing di lokasi; temuan → task perbaikan; QA Timeline tetap sebagai penutup |
| 7. Selesai | — | Aturan lama `ProjectService::completeIfFinished()` (+ temuan terbuka menahan) |
| Sepanjang proyek | Sistem → PM | **H-30%** dari Tanggal Selesai proyek: notifikasi setiap hari sampai selesai |

## 3. Yang sudah ada di sistem (dipakai ulang)

| Konsep | Yang sudah ada | Perubahan |
|---|---|---|
| Gambar Kerja | Modul Desain (Kepala Desain menugaskan, link, diskusi) — setelah Sprint 23 G | Jenis desain baru `GAMBAR_KERJA` menempel ke proyek, tanpa invoice/ACC klien |
| Timeline | `milestones` + `start_date` (Sprint 23 K17) | Diisi PM saat TM |
| Bahan | `ProjectMaterial` rencana → Logistik keluarkan/beli | Pengawas proyek itu boleh mengisi saat TM |
| Daily Target | `Task` (`assignee_id`, `due_date`, `milestone_id`, `rate_per_task`) | Dibuat Pengawas (PM tetap boleh); `planned_date`; wajib timeline |
| Actual Activity | Status task `DONE` + `DailyTaskForm` + `DailyPenaltyJob` | Centang menggantikan Daily Form; penalti tetap bila tidak lapor sama sekali |
| Terlambat | `TaskOverdueJob` → `OVER` | Bergulir dulu di dalam timeline; lewat timeline → `OVER` |
| Upah | `rate_per_task` → `StaffPaymentService` | Diisi pengawas di tiap daily target (E4) |
| Progres | `ProgressLog` (diisi PM) | Progres utama = actual activity (otomatis) |
| QA | `QaForm` per Timeline | Tetap; ditambah temuan finishing |
| Bayar Pengawas | — | **Baru**: jasa pengawas (DP, mingguan, pelunasan) |

## 4. Keputusan

| # | Keputusan | Dari |
|---|---|---|
| D1 | **Fase proyek** `projects.phase` di dalam status ACTIVE: `GAMBAR_KERJA` → `TM` → `PERSIAPAN` → `PRODUKSI` → `PEMASANGAN`. Fase **mengunci urutan** (daily target baru bisa dibuat setelah TM selesai, produksi baru mulai setelah DP pengawas disetujui PM, dst). Fase tampil dengan "menunggu siapa" (Sprint 23 K3). Proyek lama (sebelum sprint ini) mendapat fase `PRODUKSI` agar tidak terkunci. | P13 |
| D2 | **Gambar Kerja**: Buka Proyek otomatis membuat desain jenis `GAMBAR_KERJA` (`project_id`) di antrean Kepala Desain → menugaskan arsitek (dialog yang sama) → arsitek simpan link → **"Gambar Kerja Selesai"** (PIC/asisten/Kepala Desain) → fase TM + notifikasi PM & Pengawas. Tanpa ACC klien, tanpa invoice. | P11 |
| D3 | **TM** — tabel `technical_meetings` (proyek, tanggal, notulen, dicatat oleh) + peserta `technical_meeting_attendees` (user sistem **atau** nama bebas untuk orang luar, bisa lebih dari satu — E2). PM mengisi Timeline, nilai jasa pengawas, DP % (default 10, bisa diedit). **Pengawas** mengisi bahan (rencana material) — route material proyek menerima QA, Policy: hanya pengawas proyek itu. **"TM Selesai"** (PM) syarat: notulen, ≥1 peserta, ≥1 timeline, ≥1 bahan, jasa pengawas terisi, **Tanggal Selesai proyek terisi** (E1; default selesai timeline terakhir) → fase PERSIAPAN + notifikasi Pengawas. | P4, P12, E1, E2 |
| D4 | **Jasa pengawas** — `supervisor_fees` (proyek, pengawas, nilai total, DP %) + `supervisor_payments` (jenis DP / MINGGUAN / PELUNASAN, nominal, status DIAJUKAN → DISETUJUI_PM / DITOLAK → DIBAYAR, laporan mingguan terkait, penyetuju, pembayar, transaksi finance). Tanpa route hapus (append-only, golden rule #7). Pembayaran Finance = transaksi PENGELUARAN kategori baru "Jasa Pengawas" + audit, lewat antrean Finance **"Bayar Pengawas: DP · Proyek X"** (kalimat sesuai Sprint 23 K12b). **Pelunasan** (sisa = total − DP − mingguan) diajukan Pengawas saat proyek selesai → PM approve → Finance (E3). Total yang dibayar tidak boleh melebihi nilai jasa. | P1, P2, E3 |
| D5 | **Syarat DP pengawas** dicek sistem: bahan ✓ (≥1 rencana material), upah ✓ (semua daily target punya `rate_per_task`), daily target ✓ (setiap timeline punya ≥1 task). Lengkap → Pengawas **"Ajukan DP"** → PM approve (nominal = DP % × total, bisa diedit PM) → antrean Finance. Disetujui PM → fase PRODUKSI + notifikasi Logistik untuk pemesanan bahan. | P1, P2 |
| D6 | **Daily Target** = Task: dibuat Pengawas proyek itu (`TaskPolicy` baru: QA yang `supervisor_id` proyeknya) — PM/Asisten PM tetap boleh. Wajib timeline; tanggal di dalam rentang timeline. Kolom `planned_date` (tanggal target awal) disimpan terpisah dari `due_date` → laporan **target vs actual per tukang per hari**. | P5 |
| D7 | **Actual Activity** = tukang mencentang task yang selesai (status `DONE` lewat `tasks.updateStatus`) — **menggantikan Daily Form**. Tugas yang belum selesai boleh diberi kendala. Penalti tetap: tukang yang punya target hari itu tapi **tidak menyentuh satu pun** (tidak centang, tidak isi kendala) sampai jam batas → penalti (`DailyPenaltyJob` diubah, jam tetap dari `config/daiku.php`). Tukang tetap tidak bisa mengubah judul/tanggal (golden rule #6). | P6 |
| D8 | **Bergulir**: job akhir hari kerja memindahkan target yang belum selesai ke hari kerja berikutnya (`rollover_count` + riwayat). Bila hari berikutnya **melewati tanggal selesai timeline** → status **Terlambat (`OVER`)** + notifikasi & penalti yang sudah ada. | P7 |
| D9 | **Geser/tukar** oleh Pengawas (dan PM): pindahkan tanggal satu task atau tukar dua hari, **hanya di dalam rentang timeline task itu** — ditegakkan di Service + Policy. Tercatat di riwayat task. | cerita |
| D10 | **Laporan mingguan (Jumat)** — tabel `weekly_reports`: Pengawas mengisi (minggu, catatan pengecekan, foto/link) → PM approve (+ nominal pembayaran mingguan) / tolak (catatan wajib) → antrean Finance "Bayar Pengawas: Mingguan minggu ke-N · Proyek X". Pengingat Jumat ke Pengawas bila belum diisi. | P2, P3 |
| D11 | **Progres = actual activity**: `task DONE ÷ semua task` (bukan batal) di proyek itu, tampil di daftar & detail proyek. Saat pertama kali ≥ 70% → notifikasi PM "Siap berangkat ke lokasi". Tombol PM **"Setujui Berangkat ke Lokasi"** terkunci < 70% → fase PEMASANGAN + notifikasi Pengawas, Kepala Tim, Logistik. | P8 |
| D12 | **Temuan finishing di lokasi** — tabel `site_findings` (proyek, timeline, deskripsi, foto/link, status TERBUKA/SELESAI): Pengawas mencatat → **"Jadikan Task Perbaikan"** (tukang + tanggal). Temuan terbuka menahan QA Timeline & penyelesaian proyek. QA Timeline (`QaForm`) tetap sebagai penutup. | P9 |
| D13 | **H-30%**: job harian — proyek ACTIVE: bila hari ini ≥ tanggal mulai + 70% durasi (mulai → **Tanggal Selesai proyek**) → notifikasi PM **setiap hari** sampai proyek selesai. Proyek 10 hari → mulai hari ke-7. | P10 |
| D14 | **QA = QC = Pengawas** satu role; label role "QA" di UI diganti **"QA/QC"**. Pengawas **harus karyawan dengan akun** (bukan orang luar) — hanya Kepala Tim yang boleh orang luar. | P15, P16 |
| D15 | **SCM = Logistik** (role LOGISTICS) — pemesanan bahan memakai alur material yang sudah ada. | P14 |

## 5. Pertanyaan tersisa → dijawab user 2026-10-13

| # | Pertanyaan | Jawaban |
|---|---|---|
| E1 | H-30% butuh Tanggal Selesai, padahal di Buka Proyek opsional. Wajib diisi paling lambat saat **TM Selesai**? | **Ya** — "TM Selesai" ditolak bila Tanggal Selesai proyek masih kosong (default: selesai timeline terakhir) |
| E2 | Peserta TM: hanya user sistem, atau bisa juga **ketik nama** orang luar? | **Ya, keduanya** — peserta = user sistem atau nama bebas |
| E3 | **Pelunasan** jasa pengawas (sisa setelah DP + mingguan) dibayar kapan? | **Ya** — saat proyek selesai: Pengawas ajukan → PM approve → Finance |
| E4 | **Upah** = upah tukang per task (`rate_per_task`) yang diisi Pengawas di setiap daily target? | **Ya** |
| E5 | Progres 70%: setiap task bernilai **sama**, atau ditimbang dengan upahnya? | *Tidak dijawab* — dipakai usulan: **sama** (task selesai ÷ semua task); ubah bila user mengoreksi |

## 6. Sub-plan (dirinci per file saat "kerjakan")

| # | Sub | Isi |
|---|---|---|
| 01 | Fase proyek | `projects.phase`, enum, "menunggu siapa", kunci urutan, data lama → PRODUKSI, label role QA/QC |
| 02 | Gambar Kerja | Desain jenis `GAMBAR_KERJA` otomatis saat Buka Proyek, antrean Kepala Desain, "Gambar Kerja Selesai" |
| 03 | TM | `technical_meetings` + peserta + notulen, form TM (PM: timeline & jasa pengawas; Pengawas: bahan), "TM Selesai" |
| 04 | Jasa & DP pengawas | `supervisor_fees`, `supervisor_payments`, cek syarat, Ajukan DP → PM → antrean Finance + transaksi "Jasa Pengawas" |
| 05 | Daily Target | Pengawas membuat task, `planned_date`, wajib timeline & rentang, upah per task |
| 06 | Actual Activity | Centang tukang menggantikan Daily Form, penalti baru, laporan target vs actual |
| 07 | Bergulir & geser | Job bergulir → OVER lewat timeline, geser/tukar dalam timeline |
| 08 | Laporan mingguan | `weekly_reports`, pengingat Jumat, PM approve + nominal → Finance |
| 09 | Progres 70% & berangkat | Progres dari actual activity, notifikasi 70%, "Setujui Berangkat ke Lokasi" → PEMASANGAN |
| 10 | Temuan finishing | `site_findings`, task perbaikan, menahan QA/penyelesaian |
| 11 | H-30% | Job harian + notifikasi berulang |
| 12 | Uji & dokumen | Pest (RBAC tiap route baru), build, Pint, `/security-review` (pembayaran), matriks notifikasi, `simulasi-alur-klien.md`, `CLAUDE.md` golden rule baru |

## 7. Hasil pengerjaan (2026-10-15)

| Sub | Status | Catatan / keputusan tambahan |
|---|---|---|
| 01 Fase proyek | ✅ | `projects.phase` (enum `ProjectPhase`, default kolom `PRODUKSI` = proyek lama tidak terkunci) + `phase_changed_at`, `production_started_at`; hanya `ProjectPhaseService::advance()` yang memindahkan (maju satu langkah, audit `project.phase_changed`). Chip fase + "menunggu siapa" (`waitingOn` domain `projectPhase`), langkah berikutnya di `PageHeader step`. Label role QA → **QA/QC** (menu, tab, holder). |
| 02 Gambar Kerja | ✅ | **Tabel sendiri `working_drawings`** (+ `working_drawing_staff`), bukan baris `designs` — `designs.lead_id` unik dan dipakai KPI/delay desain klien. Buka Proyek → `WorkingDrawingService::openForProject()` + notifikasi Kepala Desain; dialog penugasan = `AssignDesignDialog` (prop `submitUrl`/`subject`); link + "Gambar Kerja Selesai" → fase TM + notifikasi PM, Asisten PM, Pengawas. Menu **Desain** jadi hub: Desain · Gambar Kerja (`design.working-drawings.*`); tab hub yang paling spesifik dipilih (`useActiveNav`). |
| 03 TM | ✅ (UX diperbarui 2026-10-19 — §8) | `technical_meetings` + `technical_meeting_attendees` (user atau nama). Form TM di tab **Eksekusi** (PM / Asisten PM proyek: `ProjectPolicy::runMeeting`) menyimpan notulen, peserta, jasa pengawas (total + DP %), Tanggal Selesai (default = selesai timeline terakhir). Pengawas mengisi bahan lewat tab Material (`projects.materials.store` + `role:QA`, `planMaterials` = Pengawas proyek itu). "TM Selesai" menolak bila checklist belum lengkap (E1) → PERSIAPAN. |
| 04 Jasa & DP pengawas | ✅ | `supervisor_fees`, `supervisor_payments` (DIAJUKAN → DISETUJUI_PM / DITOLAK → DIBAYAR, tanpa hapus). Syarat DP dicek sistem (bahan · upah · tiap timeline punya target). PM proyek memutuskan (nominal bisa diubah, tidak boleh melebihi sisa jasa); DP disetujui = **Start Produksi** + notifikasi Logistik/Kepala Tim/Pengawas. Finance membayar di **Pembayaran Staf → Jasa Pengawas** (`finance.supervisor-payments.*`) → transaksi PENGELUARAN kategori baru `JASA_PENGAWAS` (system-managed). Label "DP · Proyek X" dari `SupervisorPayment::label`. Pelunasan (E3) saat proyek COMPLETED. |
| 05 Daily Target | ✅ | Task = daily target: wajib timeline & tanggal di dalam rentangnya (`TaskService::ensureWithinTimeline`; task perbaikan temuan dikecualikan), baru bisa dibuat mulai PERSIAPAN, `planned_date` (target awal). Pengawas proyek boleh membuat/ubah/hapus/geser (`ProjectPolicy::manageTargets`, `TaskPolicy` + route `role:…|QA`) dan **melihat task proyeknya** (`taskVisibility($user, $project)`; QA lain tetap tidak — PRD §4.6). |
| 06 Actual Activity | ✅ | Ubah status/kendala oleh tukang sendiri (`tasks.updateStatus`) menulis baris hari itu di `daily_task_forms` (`TaskService::recordActivity`, satu per task per hari). Tombol besar **✔ Selesai** di kartu tugas (Hari Ini & Tugas). Tukang baru bisa mulai saat fase PRODUKSI. Penalti: tukang dengan target hari ini (jatuh tempo hari ini, atau OVER) di proyek yang sedang produksi dan **tanpa satu pun** laporan hari itu. Teks pengingat/penalti/antrean diganti "target belum dilaporkan". Tabel **Target vs Actual** per tukang per hari (minggu bisa digeser). |
| 07 Bergulir & geser | ✅ | `TaskOverdueJob` (00:00) lebih dulu menggulirkan target yang belum selesai ke hari kerja berikutnya selama masih di dalam timeline (`rollover_count`, riwayat `task_schedule_changes` ROLLOVER); lewat timeline → OVER (+ Pengawas ikut diberi tahu). **Geser** (`tasks.reschedule`) dan **Tukar Hari** (`projects.targets.swap`, semua target dua tanggal, opsional per tukang, ditolak utuh bila ada yang keluar timeline). |
| 08 Laporan mingguan | ✅ | `weekly_reports` (unik per proyek per minggu; ditolak → diperbaiki di baris yang sama). PM setujui (opsional + nominal pembayaran mingguan → langsung antrean Finance) / tolak (alasan wajib). `WeeklyReportReminderJob` Jumat 15:00 (`config/daiku.php` `execution`). Minggu ke-N dihitung dari minggu Start Produksi. |
| 09 Progres 70% & berangkat | ✅ | `Project::actualProgress()` = DONE ÷ semua task (E5: bobot sama) — di daftar proyek, Overview, tab Eksekusi. Pertama ≥ 70% → notifikasi PM sekali (`progress_70_notified_at`). "Setujui Berangkat ke Lokasi" (PM proyek, terkunci < 70%) → PEMASANGAN + notifikasi Pengawas, Kepala Tim, Logistik. |
| 10 Temuan finishing | ✅ | `site_findings` (TERBUKA/SELESAI) dicatat Pengawas saat PEMASANGAN; "Jadikan Task Perbaikan" (boleh lewat tanggal timeline); task DONE menutup temuan; "Tandai Selesai" manual (Pengawas/PM). Temuan terbuka menahan ACC QA timeline itu (atau semua, bila temuan tanpa timeline) dan `completeIfFinished()`. |
| 11 H-30% | ✅ | `ProjectDeadlineReminderJob` 08:00: proyek ACTIVE sejak hari ke-⌈70% × durasi⌉ (mulai → Tanggal Selesai, inklusif; 10 hari → hari ke-7) → notifikasi PM sekali sehari; peringatan juga di tab Eksekusi. |
| 12 Uji & dokumen | ✅ sebagian | Pest (RBAC setiap route baru + aturan), 19 test lama disesuaikan (task wajib timeline, penalti per target hari ini), seed demo: proyek lama di-*fast-forward* ke Produksi (`ExecutionFlowDemo::skipToProduction`), skenario baru satu proyek per fase (`WorkflowScenarioSeeder::executionFlow`). Dokumen: golden rule #17, `security-standards.md` §4, matriks notifikasi Sprint 18, `simulasi-alur-klien.md` Tahap 7. **Belum:** `/security-review`. |

**Koreksi user 2026-10-15 — Pengawas = QA = QC adalah orang yang sama.**
Pemeriksaan QA timeline (form QA lama) kini milik **Pengawas proyek itu**:
notifikasi `qa_form_created`, antrean "Timeline menunggu pemeriksaan", daftar
QA/QC dan tombol Lolos/Tolak (`QaFormPolicy::review`,
`Project::isQaReviewableBy()`); proyek tanpa Pengawas (sebelum Sprint 23)
tetap untuk semua user role QA. Nama di layar: **Pengawas (QA/QC)** (label
role, menu, "menunggu siapa", notifikasi, teks timeline).

Tambahan di luar daftar: notifikasi saat Pengawas diganti lewat Ubah Proyek
(`project_supervisor_assigned` / `_unassigned`, sisa Sprint 23 Sub 07).
Tab **Eksekusi** (`ProjectExecutionService::overview()`) hanya untuk CEO, PM,
QA, Finance, Logistik, Asisten PM proyeknya; nilai jasa & pembayaran hanya
untuk CEO / PM / Finance / Pengawas proyek itu (Marketing tidak menerima
props-nya). Catatan teknis: tab Eksekusi di-*import* biasa — `lazy()` membuat
chunk halaman Show hilang dari manifest Vite.

### Security review (2026-10-15)

`/security-review` atas seluruh perubahan Sprint 24: **tidak ada temuan HIGH/MEDIUM** (setiap route baru terikat ke proyek baris itu sendiri lewat Policy; batas uang lewat `ensureWithinFee()`; URL divalidasi `url:http,https`; nilai jasa hanya ke CEO / PM / Finance / Pengawas proyek). Dua catatan di bawah ambang:

| # | Catatan | Status |
|---|---|---|
| S1 | Tab Eksekusi terbuka untuk **semua** user role QA (bukan hanya Pengawas proyek itu), sehingga QA lain melihat `findings[].task` (judul/status/tukang/tanggal task perbaikan) dan `targetVsActual` (jumlah target per tukang per hari) — sedikit menyimpang dari "QA tidak melihat detail task kecuali proyek yang diawasinya" (PRD §4.6). Dampak rendah (staf internal, sebagian besar angka). Bila nanti perlu: kosongkan kedua bagian itu kecuali `taskVisibility($user, $project) === 'all'` di `ProjectExecutionService::overview()`. | **Ditandai — sengaja tidak diubah** (keputusan user 2026-10-15) |
| S2 | Dua persetujuan laporan mingguan bersamaan bisa sama-sama lolos `ensureWithinFee()` dan bersama melebihi nilai jasa (hanya baris laporan yang dikunci). | ✅ Diperbaiki — `SupervisorPaymentService::createWeekly()` mengunci baris proyek dulu, sama seperti `requestDp()` / `decide()` |

### Uji alur di browser (2026-10-15)

Browser headless, DB SQLite terpisah (seed demo penuh), per role: Kepala Desain menugaskan Gambar Kerja → simpan link → Selesai (→ TM) · PM menyimpan TM (Tanggal Selesai default dari timeline terakhir) · Pengawas menambah bahan & daily target (tanggal di luar timeline ditolak) · PM "TM Selesai" (→ Persiapan) · Pengawas "Ajukan DP" · PM menolak nominal di atas jasa lalu menyetujui (→ Produksi) · Finance membayar di Jasa Pengawas · Tukang ✔ Selesai di Hari Ini · laporan mingguan Jumat (kirim → setujui + bayar mingguan) · ≥ 70% → notifikasi PM sekali → Berangkat ke Lokasi (→ Pemasangan) · temuan → task perbaikan → selesai menutup temuan · Geser tanggal · job bergulir, pengingat Jumat (tidak dobel), H-30%. Semua lolos.

Bug yang ditemukan & diperbaiki saat uji:

- Banner Hari Ini "N target belum dilaporkan" salah hitung — tanggal dibandingkan di browser. Kini dihitung di server (`missingToday` = `Task::awaitingDailyForm()`); tanda "dilaporkan hari ini" di kartu (Hari Ini & Tugas) hanya untuk target hari ini / Terlambat.
- Batas tanggal dialog Geser & form Daily Target meleset satu hari — kolom tanggal-saja diserialisasi sebagai UTC. `Task::due_date/planned_date` dan `Milestone::start_date/target_date` kini di-cast `date:Y-m-d`.
- Data demo membuat item katalog dobel ("Paku Tembak F30") — `ExecutionFlowDemo` kini memakai item katalog yang sudah ada.

Bukan bug: pop-up "Buka Proyek" yang tampak tidak bisa ditutup di uji otomatis ternyata efek alat uji (koordinat klik pada sesi browser yang disambung ulang); tiap tutup menunda satu RAB dan RAB berikutnya muncul.

### Perbaikan tambahan dari user (2026-10-15)

- **Link RAB klien — pop-up tidak menutup setelah "Setujui" berhasil.** Penyebab: `PublicQuotationController` membalas dengan `back()`, padahal halaman penawaran mengirim `Referrer-Policy: no-referrer` dan sesi baru tidak menyimpan URL sebelumnya (kunjungan Inertia tidak dicatat) → redirect ke `/` (company profile Blade) yang Inertia tampilkan sebagai pop-up. Kini Setujui / Tidak Setuju / penolakan validasi selalu kembali ke `public.quotation.show` (`toOffer()`, `onOffer()`, `getRedirectUrl()` di kedua Form Request). Sesi kedaluwarsa (419) pada permintaan Inertia dibalas "Halaman sudah terlalu lama dibuka — silakan coba lagi" (`bootstrap/app.php`), bukan pop-up error Laravel; pesannya tampil di halaman dan di dalam dialog.
- **PDF invoice bergaya surat RAB** (`pdf/layouts/invoice`): judul INVOICE + tanggal kanan, Nomor / Perihal / data invoice / Status, "Ditagihkan kepada", "Dengan hormat", tabel bergaris dengan header biru muda + baris TOTAL TAGIHAN, TERBILANG, Cara Pembayaran sebagai tabel rekening, Catatan bernomor, tanda tangan — menggantikan gaya "tagihan modern" Sprint 17 Sub 05. Data tetap dari `InvoiceLetter`.

## 8. Perbaikan UX dari uji browser user (2026-10-19)

Mengubah cara kerja D3 (TM) dan D9 (geser) di layar — aturan bisnis, fase,
dan syarat "TM Selesai" tetap sama.

| # | Perubahan | Di kode |
|---|---|---|
| U1 | **Kartu TM = ruang kerja PM.** Langkah bernomor 1 Jadwal & Rapat · 2 Pembagian Timeline · 3 Jasa Pengawas & Tanggal Selesai · 4 Bahan (Pengawas), ✓ per langkah. Timeline ditambah/diubah/dihapus langsung di kartu (dialog yang sama dengan tab Timeline, `preserveState` agar isian TM tidak hilang). Notulen lebih besar, peserta berjajar (grid). | `TechnicalMeetingCard`, `MilestoneFormDialog` (`suggestTimelineStart`, `preserveState`) |
| U2 | **Jadwal TM lebih awal**: Nilai Jasa Pengawas opsional sampai pernah diisi (`required` setelah ada fee); menyimpan/mengubah tanggal TM memberi tahu Pengawas + peserta ber-akun (`technical_meeting_scheduled`, "Jadwal Technical Meeting" / "Jadwal TM Diubah"). | `SaveTechnicalMeetingRequest`, `TechnicalMeetingService::notifySchedule()` |
| U3 | **Perlu Tindakan Pengawas saat TM**: antrean `tm-supervisor` "Technical Meeting · giliran Pengawas" — tanggal TM + jumlah bahan direncanakan, tautan ke `#tm-bahan`. | `ActionInboxService::meetingsForSupervisor()` |
| U4 | **Acuan item RAB di samping bahan**: RAB Proyek + RAB Tambahan yang disetujui klien, per bagian, uraian + volume **tanpa harga**; hanya PM sisi TM & Pengawas proyek itu, hanya di fase TM. | `ProjectExecutionService::rabReference()` (`meetingRab`) |
| U5 | **Rencana bahan inline** (Pengawas & PM): cari katalog → jumlah → Enter, sumber otomatis (stok ada → Gudang), ubah jumlah di baris, hapus. Pengawas boleh **ubah/hapus** rencananya sampai PRODUKSI (`ProjectPolicy::reviseMaterialPlan`; route `project-materials.update` `PM|LOGISTICS|QA`, `destroy` `LOGISTICS|QA`; baris pengajuan tukang tidak). Simpan cepat tanpa pop-up sukses (`quietSuccess`). | `TmMaterialPlanner`, `ProjectMaterialController::authorizeRevision()`, `useFlashToasts` |
| U6 | **TM Selesai di header halaman** (aksi halaman detail, K4b) + di bawah kartu; terkunci dengan "n/6" & daftar yang kurang; konfirmasi **`ConfirmDialog`** (pop-up khas Daiku, juga untuk hapus timeline / bahan). | `CompleteMeetingButton`, `Components/shared/ConfirmDialog` |
| U7 | **Task → Per Hari**: kolom Senin–Minggu, seret target ke hari lain (HP: ketuk → "Pindah ke sini"); hari di luar timeline pudar; memakai `tasks.reschedule` (aturan D9 sama, tukang diberi tahu). | `TaskWeekBoard` |
| U8 | **Toast notifikasi bertema Daiku** (perlu tindakan = kuning, sukses/peringatan/error = token status, tombol "Buka" kuning, font Geist). | `Components/ui/sonner.tsx` |

Test: `ExecutionFlowTest` +4 (acuan RAB tanpa harga & siapa yang menerima,
jadwal TM + notifikasi, antrean Pengawas, ubah/hapus rencana bahan) — suite
penuh 2194 lulus, build bersih.
