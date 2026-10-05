# Sprint 12 · 04 — Quotation: Review per Item & Approval PM → CEO

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 01 (role ASISTEN_PM), 03 · Keputusan: #7–#10
> **Menggantikan Sprint 11 Fitur A.**

## Tujuan
PM/Asisten PM menandai tiap item ✔/✘ lalu ACC; RAB Proyek lanjut ke CEO;
Estimator mengirim RAB Final ke Marketing; Marketing mengirim ke client.

## File yang disentuh
- `app/Services/QuotationService.php` (`GATES`, `submit`, `ceoDecision`, `pmDecision`, `clientReject`)
- `app/Enums/QuotationStatus.php`, `app/Models/QuotationApproval.php`
- `app/Http/Controllers/Quotation/{QuotationController,QuotationDashboardController}.php`
- `routes/web.php` (blok `quotations.*` ±baris 155–200)
- `resources/js/Pages/Quotation/{Show,Index,Dashboard}.tsx`, `Components/shared/StatusChip.tsx`
- `app/Services/{DivisionDashboardService,AnalyticsService}.php` (antrean/turnaround)
- `.claude/rules/security-standards.md` §4 (contoh "CEO lalu PM" → "PM lalu CEO")
- Test: `tests/Feature/Quotation/*`

## Rancangan
```
DIMINTA → DRAFT → SUBMITTED (menunggu PM/Asisten PM)
   ├─ ada ✘ / tolak  → DRAFT versi+1          (dihitung "dikembalikan" — KPI Estimator)
   └─ ACC ─ SURVEY/DESAIN → APPROVED_INTERNAL
          └ PROYEK → WAITING_CEO
                       ├─ tolak → DRAFT versi+1 (dihitung "lolos review" — KPI PM yang ACC)
                       └─ ACC  → APPROVED_INTERNAL
APPROVED_INTERNAL → READY_TO_SEND  (Estimator "Kirim RAB Final ke Marketing")
READY_TO_SEND     → SENT_TO_CLIENT (Marketing "Kirim ke Client")
SENT_TO_CLIENT    → CLIENT_APPROVED (sementara lewat jalur lama confirmDeal; sub-plan 05 menggantinya)
* → CANCELLED (Marketing, alasan wajib)
```
```
quotation_item_reviews   quotation_id, version, quotation_item_id, stage (PM | CEO),
                         reviewer_id, verdict (OK | SALAH), note       — append-only
```
- **Migrasi status lama**: `SUBMITTED` (dulu menunggu CEO) → SUBMITTED
  (menunggu PM); `CEO_REVIEW` (CEO sudah ACC, menunggu PM) → SUBMITTED;
  `APPROVED` → CLIENT_APPROVED. Case enum lama dipertahankan untuk histori
  `quotation_approvals` (append-only, tidak diubah).
- **Gate** di satu tempat (`QuotationService`): SUBMITTED → role PM |
  ASISTEN_PM (T1 Sprint 11 masih tertunda); WAITING_CEO → CEO. CEO tidak
  bisa memutuskan sebelum PM.
- **Review PM**: semua item wajib diberi tanda; ✘ wajib catatan; "Setujui
  RAB" hanya aktif bila semua ✔; "Kembalikan ke Estimator" bila ada ✘.
  CEO boleh menandai juga (stage CEO) untuk menunjukkan item yang salah.
- Estimator di versi baru melihat item ✘ + catatan (highlight).
- Notifikasi: submit → PM & Asisten PM; ACC PM (PROYEK) → CEO; tolak →
  Estimator; READY_TO_SEND → Marketing (pemilik lead).
- Audit: `quotation.pm_approved/returned`, `quotation.ceo_approved/returned`,
  `quotation.sent_to_marketing`, `quotation.sent_to_client`.

## Checklist
- [x] **[Database]** `quotation_item_reviews`; status baru di enum; migrasi data status lama (reversibel)
- [x] **[Quotation]** `QuotationService`: state machine baru, gate PM|ASISTEN_PM lalu CEO (PROYEK), review per item, kirim ke Marketing/client, cancel; notifikasi + audit
- [x] **[Quotation]** Route: ganti `ceoDecision`/`pmDecision` dengan `review` (PM/CEO), `sendToMarketing` (Estimator), `sendToClient`/`cancel` (Marketing)
- [x] **[UI]** Layar review ✔/✘ per item + ringkasan; highlight item ✘ untuk Estimator; tombol per status; dashboard antrean per role
- [x] **[Docs]** `security-standards.md` §4 + catatan deviasi PRD §4.3/§6.2/§7.1 di `plan/README.md`
- [x] **[Test]** SURVEY/DESAIN: PM saja; PROYEK: PM → CEO, CEO sebelum PM ditolak; ✘ memaksa kembali; Asisten PM bisa review; Estimator/Marketing tidak bisa review (403); migrasi status lama

## Di luar cakupan
Halaman client (05); perhitungan KPI (13) — di sini hanya datanya.
