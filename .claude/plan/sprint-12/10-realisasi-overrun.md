# Sprint 12 · 10 — Realisasi per Item & Persetujuan Overrun CEO

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: Sprint 11 Sub 2 (Vendor), 09 · Keputusan: #27, #28

## Tujuan
PM mencatat biaya riil tiap item (qty riil × harga modal, vendor opsional);
Finance melihat anggaran vs realisasi per pos. Realisasi yang melebihi
anggaran pos diblokir sampai CEO menyetujui.

## File yang disentuh
- `app/Services/ProjectBudgetService.php` (dari 09), model baru
- `resources/js/Pages/Projects/Show.tsx` (tab Alokasi Dana), `Components/shared/VendorSelect.tsx`
- Notifikasi CEO (`NotificationService`), `AuditLogService`
- Test: `tests/Feature/Projects/*`

## Rancangan
```
budget_realizations      budget_line_id, qty_actual, unit_cost, total_cost,
                         vendor_id (nullable), note, recorded_by, recorded_at  — append-only
                         (koreksi = baris baru yang membatalkan, bukan update)
budget_overrun_requests  budget_post_id, budget_realization_payload (JSON — isian yang tertahan),
                         amount_over, reason, requested_by,
                         status (MENUNGGU | DISETUJUI | DITOLAK), decided_by, decided_at, decision_note
```
- Contoh Excel: Listrik — stop kontak 280.000 (anggaran) vs 250.000 (modal);
  LED strip RAB 4,15 m, riil 7,5 m + PS → `qty_actual` boleh beda dari RAB.
- Per pos: Anggaran · Realisasi · Selisih (+ margin %). Per baris: anggaran,
  realisasi, vendor, catatan.
- **Overrun**: simpan realisasi yang membuat Σ realisasi pos > anggaran pos
  → ditolak dengan pesan "Melebihi anggaran pos Listrik Rp X" → tombol
  "Ajukan ke CEO" (alasan wajib) → MENUNGGU, notif CEO → CEO "Setujui"
  (realisasi dari payload tersimpan) / "Tolak" (catatan) → notif PM.
  Satu pengajuan menunggu per pos. Audit `finance.budget_overrun_*`.
- RBAC: catat realisasi = PM proyek itu; putuskan overrun = CEO; baca =
  CEO, Finance, PM. Marketing & Asisten PM tidak.

## Checklist
- [x] **[Database]** `budget_realizations`, `budget_overrun_requests`
- [x] **[Projects]** Catat realisasi (lock pos, cek overrun), ajukan/putuskan overrun, ringkasan anggaran vs realisasi
- [x] **[UI]** Kolom realisasi di tab, dialog catat (qty, harga modal, vendor, catatan), banner overrun + pengajuan, antrean persetujuan CEO (dashboard CEO)
- [x] **[Test]** Overrun diblokir lalu tersimpan setelah CEO setuju; ditolak tidak tersimpan; hanya CEO memutuskan; append-only; Asisten PM/Marketing 403
