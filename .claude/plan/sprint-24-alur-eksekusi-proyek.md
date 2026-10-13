# Sprint 24 — Alur Eksekusi Proyek: Gambar Kerja → TM → Pengawas → Produksi → QC

> Status: **plan dikunci (2026-10-13)** — P1–P16 dan E1–E4 dijawab; E5
> memakai usulan (§5). Belum ada kode yang diubah. Pengerjaan baru
> dimulai kalau user bilang **"kerjakan"**, dan **setelah Sprint 23 Sub 07–09**
> (Pengawas, Timeline bertanggal, halaman Desain baru) selesai.
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
