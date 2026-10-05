# Sprint 12 · 08 — Desain: Kepala Desain, Kunci Bayar, Revisi, Diskusi

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 01, 06 · Keputusan: #15–#18 · Default: D5, D6

## Tujuan
Desain dibuat dari RAB Jasa Desain yang disetujui client, terkunci sampai
dibayar, ditugaskan Kepala Desain, revisi diminta Marketing (dihitung), dan
ada thread diskusi Arsitek ↔ Estimator.

## File yang disentuh
- `app/Models/Design.php`, `app/Enums/DesignStatus.php`, `app/Services/DesignService.php`
  (`create` mensyaratkan lead DEAL_DESAIN ±baris 89, `clientAcc`, `syncWithPipeline`)
- `app/Http/Controllers/Design/{DesignController,DesignDashboardController}.php`, `routes/web.php` (blok `design.*`)
- `resources/js/Pages/Design/{Index,Show,Dashboard}.tsx`
- Label "Arsitek" untuk role DESIGNER di UI (D5) — kode role tetap
- Test: `tests/Feature/Design/*`

## Rancangan
```
designs             + quotation_id (RAB Jasa Desain), + assigned_by, + assigned_at,
                    + revision_count (int, default 0); pic_id → nullable sampai ditugaskan
                    status + MENUNGGU_BAYAR, MENUNGGU_PENUGASAN
design_members      design_id, user_id, role (PIC | ASISTEN)  unique(design_id, user_id)
design_discussions  design_id, quotation_id (nullable), user_id, body, attachment_url
                    (nullable, http/https), created_at                (D6)
```
- Listener `QuotationClientApproved` (DESAIN) → design MENUNGGU_BAYAR,
  notif KEPALA_DESAIN "disetujui client, belum bayar".
- Listener `InvoiceVerified` (JASA_DESAIN) → MENUNGGU_PENUGASAN, notif
  "siap dikerjakan".
- **KEPALA_DESAIN** "Tugaskan Desain": PIC (boleh dirinya) + asisten +
  timeline (start, target hari, deadline) → DESAIN. Hanya Kepala Desain;
  Policy: arsitek melihat desain yang ia anggotai, Kepala Desain semua.
- Arsitek unggah link desain → **Marketing** "Kirim Desain ke Client" →
  WAITING_ACC_DESAIN → Marketing "Minta Revisi" (catatan wajib,
  `revision_count`+1, audit) atau "Desain Disetujui Client" → ACC_DESAIN →
  notif Estimator (siap RAB Proyek).
- Desain lama (dibuat langsung tanpa quotation) tetap berjalan; pembuatan
  desain manual dari lead dihapus untuk data baru.
- Tinjau status pipeline lama di `DesignStatus` (GAMBAR_RAB,
  PEMBUATAN_PENAWARAN, WAITING_ACC_PENAWARAN, PRODUKSI, ...) — tahap RAB kini
  di quotation; status lama dipertahankan untuk histori, tidak dipakai data
  baru. Cek `syncWithPipeline` & delay job tetap benar.
- Thread diskusi tampil di Detail Desain dan di Detail Quotation (lead sama);
  notif ke pihak lain di thread.

## Checklist
- [x] **[Database]** Kolom `designs`, `design_members`, `design_discussions`, status baru
- [x] **[Design]** Listener persetujuan & pembayaran; `DesignService::assign()` (Kepala Desain), `sendToClient()`, `requestRevision()`, `markClientApproved()` (Marketing)
- [x] **[Design]** Policy keanggotaan desain; delay job mengabaikan MENUNGGU_*
- [x] **[UI]** Antrean Kepala Desain (Menunggu Bayar / Menunggu Penugasan), dialog penugasan, hitungan revisi, thread diskusi (Desain & Quotation), label "Arsitek"
- [x] **[Test]** Tidak bisa ditugaskan sebelum bayar; hanya Kepala Desain menugaskan (boleh diri sendiri); arsitek non-anggota 403; revisi menambah hitungan; Marketing saja yang kirim/minta revisi
