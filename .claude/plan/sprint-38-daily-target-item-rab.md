# Sprint 38 — Daily Target ↔ Item RAB (Progres Target per item)

> Status: **kode selesai 2026-10-23** — 2431 test lulus, build & Pint bersih, migrasi dev
> jalan (rollback dicek); cek browser per role belum (Chrome tidak tersambung). Di luar PRD; lanjutan
> Sprint 31 Sub 08 dan "Menunggu" Sprint 35. (Nomor 37 sudah dipakai kode untuk
> template Tim Tukang per item RAB — CLAUDE.md aturan 21.) Keputusan user: **Opsi A versi
> longgar**.

## 1. Asal

Sprint 31 §3 Sub 08 bertanya: daily target dan item RAB disambung atau
tetap terpisah? Jawaban user 2026-10-23:

| Pertanyaan | Jawaban |
|---|---|
| Disambung? | **Ya** — pekerjaan tukang di daily target harus terasa pengaruhnya ke progres |
| % pengecekan | **Tetap diisi manual oleh Pengawas** (di lapangan orang menaksir progres langsung) — tidak dihitung dari target |
| Bentuk | **Opsi A**: Progres Target per item = target selesai ÷ semua target item itu, dihitung otomatis, tanpa isian tambahan untuk tukang/Pengawas |
| Ketat/longgar | **Longgar**: item RAB di target boleh kosong (terisi otomatis bila timeline hanya punya 1 item berupah); selisih besar dengan % Pengawas hanya **tanda kuning**, tidak wajib catatan, tidak menghambat kirim |

Ditolak / ditunda: Opsi B (porsi % per target → progres sementara) — bisa
ditambah belakangan di atas kolom yang sama bila ukuran target terlalu
beda-beda.

Sebelum sprint ini: daily target → Timeline → item RAB sudah tersambung
datanya (`milestone_work_item`, Sprint 35), tapi satu timeline bisa berisi
beberapa item, jadi target tidak tahu mengerjakan item mana. Progres utama
proyek berupah per item hanya membaca % pengecekan; daily target hanya
berpengaruh ke Activity Target dan penalti.

## 2. Aturan

- **Satu target → paling banyak satu item RAB** (`tasks.project_work_item_id`,
  nullable). Item harus salah satu item di timeline target itu
  (`milestone_work_item`); timeline tanpa item → item kosong. Timeline dengan
  tepat 1 item berupah → terisi otomatis (tetap bisa dikosongkan).
- **Progres Target item** = target item itu yang **selesai** ÷ semua target
  item itu × 100. "Selesai" = definisi yang sama dengan Activity Target
  (`ProductionService::activityFacts()`: status `DONE`, harinya =
  `completed_at` WIB). Target OVER / belum selesai tetap di penyebut.
  **Target perbaikan** (dari temuan / QC Item — `create(..., repair: true)`)
  tidak dihitung.
- **Progres Target proyek** = Σ (bobot item × Progres Target item) ÷ Σ bobot
  item — hanya item berupah yang punya ≥ 1 target (item tanpa target tidak
  menurunkan angka). Null bila belum ada target yang tersambung.
- Rumus hanya di `WorkItemService` (hundredths, tanpa float menumpuk) —
  satu query agregat untuk semua item (tanpa query per baris).
- **Tidak mengubah** uang (`payableNow`), progres berbobot
  (`Project::actualProgress()`), 70 % "Berangkat ke Lokasi", QA per item,
  penalti, Activity Target. Progres Target = acuan saja.
- **Tanda kuning** bila |% pengecekan kumulatif item − Progres Target item|
  ≥ `daiku.execution.target_gap_warning` (default 30 poin) dan item punya
  ≥ 1 target. Tidak wajib catatan, tidak memblokir kirim / persetujuan PM.
- Mengisi / mengubah item di target = yang sudah boleh membuat / mengubah
  daily target (`manageTargets`, `TaskPolicy::update` — Pengawas proyek,
  Admin Proyek, PM). Tukang **tidak pernah** mengubah item target (aturan
  emas #6 — Policy, bukan hanya UI).

## 3. Dikerjakan

### Sub 01 — Target terikat ke item RAB
- [x] **[DB]** `tasks.project_work_item_id` nullable, FK `project_work_items` `nullOnDelete`, index `(project_work_item_id, status)`
- [x] **[Backend]** `TaskService::create()` / `createMany()` / `update()` menerima `project_work_item_id` (`workItemFor()`); validasi "item termasuk timeline target ini" (pesan Bahasa Indonesia); isi otomatis bila timeline punya tepat 1 item berupah dan kolom tidak dikirim (target baru / pindah timeline); tidak dikirim (halaman Tugas) → item lama tetap selama timeline masih memegangnya; `MilestoneService` mengosongkan item target bila timeline melepas item itu; target perbaikan tanpa item; target DONE boleh diberi item (`DONE_EDITABLE`)
- [x] **[Backend]** `StoreTaskRequest` / `StoreTasksRequest` / `UpdateTaskRequest`: `project_work_item_id` `nullable|integer|exists` (item proyek ini, tanpa bintang wajib); `UpdateTaskStatusRequest` / laporan tukang tidak menerima kolom ini
- [x] **[Backend]** Ubah item target diaudit (`task.updated`, `item_rab` = nama item)
- [x] **[UI]** Form daily target (tunggal & banyak sekaligus): pilihan **Item RAB** di bawah Timeline — hanya item timeline yang dipilih, otomatis terpilih bila hanya 1, bisa "— tanpa item —"; daftar/kartu target menampilkan nama item. Data pilihan = prop `timelineItems` (kini juga untuk pengelola daily target; `RELOAD.wages` ikut memuatnya). Halaman Tugas tidak menampilkan/mengirim kolom ini
- [x] **[UI]** Kartu tugas tukang (`TaskCard`, Hari Ini & Tugas): nama item sebagai keterangan (tanpa angka uang / %)

### Sub 02 — Hitungan Progres Target
- [x] **[Backend]** `WorkItemService::targetProgress(Project)` — per item: `planned`, `done`, `percent`, hari-hari ada target selesai (`days`); per proyek: Progres Target berbobot (`total`). Satu query target (+ satu nilai RAB)
- [x] **[Backend]** Masuk ke prop yang sudah ada: `execution.progressRecap.items[].target` + `target_total` + `target_gap`, `execution.workCheck.items[].target` + `summary.target_gap`, `weeklyReports[].progress[].item_id` — penerimanya sama (CEO/PM/Finance/Pengawas proyek + Admin Proyek); tidak ada prop baru untuk Logistik / Marketing / Tukang
- [x] **[Config]** `daiku.execution.target_gap_warning` (30) — `WorkItemService::targetGapWarning()`; aturan tanda di `lib/workCheck.ts` `targetGap()`

### Sub 03 — Tampil di Lembar Progres, form pengecekan, ringkasan
- [x] **[UI]** `WorkProgressSheet`: titik kecil di sel hari yang ada target item itu selesai (tooltip "n target selesai"); kolom **Target** "6/8 · 75 %" di samping TOTAL % (dibekukan kanan, ikut HP: baris kecil di kartu item); baris "Jumlah" menampilkan Progres Target proyek
- [x] **[UI]** Form pengecekan (kolom **Hari ini** / `WorkCheckForm`): acuan "Target 75 %" di tiap item + **tanda kuning** "Berbeda jauh dari target lapangan" bila selisih ≥ batas; tetap bisa kirim
- [x] **[UI]** Keputusan PM atas pengecekan: tanda kuning per item di banner "Pengecekan menunggu ACC" Lembar Progres (tanpa memblokir Setujui)
- [x] **[UI]** Ringkasan: kartu **Berangkat ke Lokasi** (Eksekusi) — "Progres target 62 % — hanya acuan" di bawah progres utama; baris Jumlah Lembar Progres. Progres Target tidak pernah menggantikan angka progres utama

### Sub 04 — Seed, test, dokumen
- [x] **[Seed]** `ExecutionFlowDemo::TARGETS` — timeline ber-1 item mengisi sendiri, target pemasangan menyebut item, persiapan tanpa item. Hendra: Kitchen Set & Partisi jauh di atas % pengecekan (tanda kuning; hilang setelah draf minggu ini diketik). Siska: target tersambung, belum ada yang selesai (0 %)
- [x] **[Test]** `WorkItemTargetProgressTest` (11 test): isi otomatis 1 item; item di luar timeline ditolak; pindah timeline mengosongkan item; tukang tidak bisa mengubah item (403/validasi); hitungan DONE ÷ semua (OVER di penyebut, perbaikan tidak dihitung, hari = `completed_at` WIB); Progres Target proyek berbobot & item tanpa target diabaikan; tanda kuning di batas; `payableNow` / `actualProgress` tidak berubah; Logistik / Marketing tidak menerima data
- [x] **[Test]** RBAC route target yang berubah (Pengawas proyek, Admin Proyek, PM boleh; QA lain, Tukang, Logistik 403) — di `WorkItemTargetProgressTest`; `SeederTest` memeriksa Hendra
- [x] **[Dok]** `CLAUDE.md` aturan 22 (Progres Target = acuan, rumus di `WorkItemService`), `sprint-31` §3 Sub 08 & `sprint-35` "Menunggu" ditandai selesai, README status
- [x] **[Build]** `npm run build`, Pint, suite Pest penuh (2431 lulus)
- [ ] **[Browser]** Cek per role (Pengawas desktop + HP 390 px, PM, Logistik) — belum, Chrome tidak tersambung

### Sub 05 — Data langsung terbaca (masukan user 2026-10-23: "agar user langsung tau maknanya")
Keputusan user: tetap **dua kolom**; istilah **"Daily Target Selesai"** (pengganti "Target").
- [x] **[UI]** Judul kolom: **Daily Target Selesai** dan **Total % · cek Pengawas**, masing-masing ikon ⓘ (Popover — bisa disentuh di HP) berisi arti & bahwa Total % = dasar upah, Daily Target Selesai = pembanding
- [x] **[UI]** Tiap baris menulis kesimpulannya (`lib/workCheck.ts` `targetStatus()` / `TARGET_STATUS`, satu peta): **✓ Sejalan** · **⚠ Progres lebih tinggi** (Total % ≥ batas di atas — cek target yang belum dicentang) · **Daily target lebih maju** (persen bisa dinaikkan) · **Belum mulai**; item tanpa target "belum ada daily target"; "3 dari 4 target"
- [x] **[UI]** Total %: "dari 30%" → **"+70% hari ini"** (sebelumnya di tooltip)
- [x] **[UI]** Di atas tabel: kalimat **Cara baca** (bisa ditutup, diingat di perangkat) + ringkasan "n item progresnya jauh lebih tinggi…: A, B" (peringatan) / "n item daily targetnya lebih maju…" (info) — dari pengecekan yang tercatat
- [x] **[UI]** Baris bawah berlabel: "proyek" di Daily Target Selesai, "progres proyek" di Kontribusi; banner PM hanya menandai "Progres lebih tinggi"; form pengecekan HP & kartu Berangkat ke Lokasi memakai istilah yang sama

- [x] **[UI]** Masukan lanjutan 2026-10-23 ("Total di sebelah kiri, masih bingung cara bacanya"): urutan kolom **Total % · Daily Target Selesai · Kontribusi**; keterangan selalu bersubjek Total %: **Total sesuai target** · **Total di atas target** · **Total di bawah target** · Belum mulai; "Cara baca (dari kiri)" ditulis ulang (kunci tutup baru `…explainClosed.v2`, tampil sekali lagi)
- [x] **[UI]** User: "tidak bisa isi % di atas 100%" — isian pengecekan dibatasi **saat diketik** (`WorkCheckForm` `capTyped()`): lebih dari sisa → otomatis jadi sisa (Total tidak pernah > 100 %), koreksi minus tidak di bawah 0 %; keterangan "maks. 48%" di bawah kolom Isi hari ini / "maks. tambah" di kartu HP. Server tetap menolak > 100 % (`WorkItemService::recordProgress()`)

## 4. Cara cek (setelah selesai)

1. `qa` / `password` → Proyek Hendra › Timeline: buat daily target di timeline ber-1 item → item terisi sendiri; di timeline ber-banyak item → pilih item.
2. `fieldstaff` → tandai target Selesai; `qa` centang → Item RAB › Lembar Progres: titik di hari ini, kolom Target naik.
3. Isi pengecekan (Jumat/Sabtu) dengan % jauh dari Target → tanda kuning muncul, tetap bisa Kirim.
4. `pm` → banner "Pengecekan menunggu ACC" di Lembar Progres menampilkan tanda yang sama; progres utama proyek tidak berubah oleh target.

## 5. Di luar cakupan

- % pengecekan otomatis dari target, atau porsi % per target (Opsi B).
- Taksiran % dari tukang di HP ("sampai berapa %").
- Catatan wajib / blokir kirim karena selisih.
