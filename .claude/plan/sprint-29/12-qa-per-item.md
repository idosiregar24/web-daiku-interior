# Sprint 29 · 12 — QA per Item RAB

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "QA Form harus berdasarkan per item yang dilihat dari RAB" · Keputusan G1–G3 · Q31
> Status: **kode selesai 2026-10-21** — tersisa cek browser (HP 390 px dengan kamera) · Bergantung: Sub 09 (progres per item)
>
> **Perubahan dari rancangan:** "Proyek baru" = `Project::usesItemQa()` — ada item berupah **dan** belum ada
> QaForm (proyek yang timeline-nya sudah pernah lewat QA tetap per timeline, walau kemudian diberi upah).
> Status QA item tidak disimpan: diturunkan dari progres + baris `work_item_qa_checks` terbaru
> (`WorkItemQaService::statuses()`, kembaran SQL `ProjectWorkItem::awaitingQa()`). Item yang sudah Lolos boleh
> ditandai Perlu perbaikan lagi (bila ternyata salah); Lolos hanya untuk yang Menunggu.

## Keputusan user
Ronde 4: setuju opsi (b) — QA dilakukan **per item RAB**, bukan checklist
generik per Timeline.

## Rancangan
- Item kerja yang progresnya mencapai **100 %** di pengecekan → status QA
  **Menunggu QA** → antrean Pengawas "Item menunggu QA · n item · Proyek X".
- Form QA per item (atau beberapa item sekaligus): Uraian · Volume · Kepala
  Tim · **Lolos** / **Perlu perbaikan** + catatan + foto/link. Perlu perbaikan
  → "Jadikan Task Perbaikan" (alur temuan Sprint 24 D12) dan item kembali
  di bawah 100 % tidak perlu — QA ulang setelah task perbaikan selesai.
- Tabel `work_item_qa_checks` (append-only: item, keputusan, catatan,
  pemeriksa, waktu). Status terakhir per item dibaca dari baris terbaru.
- Proyek selesai (`ProjectService::completeIfFinished`): semua item berupah
  100 % + QA terakhir Lolos + tanpa temuan terbuka (G2). Proyek lama tetap
  QA per timeline.
- Proyek baru: "Tandai Selesai" timeline tidak lagi membuat `QaForm` (G3);
  tab "Timeline & QC" → sub-tab "QC Item" menggantikan "QC Timeline".
- `QaFormPolicy::review` setara: Pengawas proyek (semua, Sub 06); proyek tanpa
  Pengawas → semua QA.

## Checklist
- [x] **[DB]** `work_item_qa_checks` (item, `decision` LOLOS/PERBAIKAN, `note`, `photo_url`, `photo_path` disk privat, `site_finding_id`, `checked_by`; model `WorkItemQaCheck` melempar saat update/delete)
- [x] **[Backend]** `WorkItemQaService` (`statuses()`, `overview()`, `decide()` — Lolos banyak item / Perlu perbaikan satu item + catatan wajib → `SiteFinding`, foto lewat pipeline WebP `InvoicePaymentProofService::encode()`), `allPassed()`; `ProjectService::completeIfFinished` versi item (G2: semua item berupah Lolos + tanpa temuan terbuka; timeline tidak dihitung); temuan yang ditutup (`SiteFindingService::resolve` / task perbaikan selesai) memanggil `afterClosed()`; `MilestoneService::markDone` → langsung COMPLETED + timeline berikutnya berjalan, tanpa QaForm (G3); `ProjectPolicy::reviewItemQa` (Pengawas proyek / semua QA bila tanpa Pengawas — bukan Admin Proyek) & `viewItemQa`; route `projects.item-qa.decide` (`role:QA`) & `projects.item-qa.photo`; notifikasi `work_item_qa_decided` ke PM/Asisten PM; antrean Perlu Tindakan "Item menunggu QA" (satu baris per proyek)
- [x] **[UI]** Sub-tab "QC Item" (`WorkItemQaPanel`: ringkasan Lolos x dari n + chip Menunggu/Perbaikan/Belum, tabel + kartu HP, pemeriksaan terakhir + foto/link + temuan/task), dialog Periksa (`ResponsiveDialogContent`), "Jadikan Task Perbaikan" (`RepairTaskDialog` dipakai ulang) & "Sudah Diperbaiki", langkah header "n item RAB menunggu QA"; `RELOAD.itemQa`; teks konfirmasi Tandai Selesai timeline
- [ ] **[UX]** Lolos banyak sekaligus (centang + "Lolos QA (n)" + ConfirmDialog) ✓, foto `capture="environment"` diperkecil di HP sebelum unggah ✓ — **belum dicek di browser HP 390 px**
- [x] **[Test]** Pest `WorkItemQaTest` (6 tes): 100 % masuk antrean, < 100 % ditolak; Lolos banyak + append-only; Perlu perbaikan → temuan → task selesai → Menunggu lagi → Lolos; COMPLETED hanya bila semua lolos & temuan tertutup; RBAC (QA lain, Admin Proyek, PM, Marketing 403; Pengawas kedua boleh; foto WebP privat, Marketing/Tukang 403); proyek lama tetap QA per timeline. `ClientJourneySimulationTest` Tahap 8 pindah ke QA per item
- [x] **[Build]** Pint ✓, `npm run build` ✓, 2314 test lulus
