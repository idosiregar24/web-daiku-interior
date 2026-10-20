# Sprint 29 · 12 — QA per Item RAB

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "QA Form harus berdasarkan per item yang dilihat dari RAB" · Keputusan G1–G3 · Q31
> Status: **belum dikerjakan** · Bergantung: Sub 09 (progres per item)

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
- [ ] **[DB]** `work_item_qa_checks`
- [ ] **[Backend]** `WorkItemQaService` (antrean, keputusan, task perbaikan); `completeIfFinished` versi item; `MilestoneService::markDone` tanpa QaForm untuk proyek baru
- [ ] **[UI]** Sub-tab "QC Item" (daftar item + status QA), form QA item, antrean
- [ ] **[UX]** Lolos banyak item sekaligus; HP 390 px dengan foto dari kamera; ConfirmDialog "Lolos QA"
- [ ] **[Test]** Pest: item 100 % masuk antrean; Lolos/perbaikan; proyek COMPLETED hanya bila semua lolos; RBAC; proyek lama tidak berubah
- [ ] **[Build]** `npm run build`, Pint
