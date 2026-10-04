# Sprint 12 · 03 — Quotation: 3 Jenis & Struktur RAB (format Excel)

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: Sprint 11 Sub 1 (Master Satuan), sub-plan 02 · Keputusan: #6, #7 (bagian "diminta Marketing"), #11, #12

## Tujuan
Quotation punya jenis SURVEY/DESAIN/PROYEK, bisa diminta Marketing, dan
isinya mengikuti format RAB Excel Daiku (bagian pekerjaan, dimensi, diskon,
pembulatan, skema DP/termin). **Alur approval belum diubah** (sub-plan 04).

## File yang disentuh
- `app/Models/{Quotation,QuotationItem,QuotationRevision}.php`, `app/Enums/QuotationStatus.php`
- `app/Services/QuotationService.php` (`createFromDesign`, `replaceItems`, `submit`)
- `app/Http/Controllers/Quotation/QuotationController.php`, `app/Http/Requests/Quotation/*`
- `resources/js/Pages/Quotation/{Index,Show,Dashboard}.tsx`, `resources/js/types/index.d.ts`
- PDF/Excel quotation (view DomPDF + export Laravel Excel yang ada)
- `resources/js/Pages/CRM/Show.tsx` (aktifkan opsi "Minta RAB ..." di dialog sub-plan 02)
- Test: `tests/Feature/Quotation/*`

## Rancangan
```
quotations (existing)       + type (SURVEY | DESAIN | PROYEK), default PROYEK untuk data lama
                            + lead_survey_id (nullable), design_id → nullable
                            + parent_quotation_id (nullable — addendum, dipakai sub-plan 12)
                            + discount_amount (default 0), rounded_total (nullable)
                            + requested_by, request_note
quotation_sections          quotation_id, name, sort_order
quotation_items (existing)  + section_id (nullable untuk data lama), + dim_length,
                            + dim_width_height (DECIMAL(10,2), nullable)
quotation_payment_terms     quotation_id, sequence (1..6), label, percentage (DECIMAL(5,2)),
                            amount, trigger (TANGGAL | MILESTONE | PROYEK_SELESAI),
                            due_date (nullable), milestone_name (nullable)
```
- Status baru `DIMINTA`: Marketing "Minta RAB Jasa Survey/Desain/Proyek"
  dari Detail Lead (catatan wajib) → notif Estimator → Estimator "Mulai
  Susun" → DRAFT. RAB Proyek boleh tanpa desain (keputusan #6).
- Satu lead boleh punya banyak quotation (per jenis, + versi).
- **Total**: Σ subtotal item → `discount_amount` → `rounded_total` (diisi
  Estimator, default = total − diskon). Termin dihitung dari `rounded_total`.
- **Skema bayar**: 1–6 baris (D1: DP termasuk), Σ persentase = 100% dan
  Σ amount = `rounded_total` (validasi server + Zod sama). SURVEY/DESAIN
  default 1 baris 100% (D4).
- **Snapshot revisi** (`quotation_revisions.items` JSON) ikut menyimpan
  section, dimensi, diskon, pembulatan, skema bayar.
- **RAB builder** di `Quotation/Show.tsx`: tambah bagian, item per bagian
  (No otomatis, Item, P, T/L, Volume, Satuan, Harga, Subtotal), ringkasan
  Total/Diskon/Pembulatan, editor skema bayar.
- PDF/Excel meniru tata letak Excel "RAB_KOPI OZ ARIFIN".

## Checklist
- [ ] **[Database]** Kolom & tabel di atas; data lama → type PROYEK, item tanpa section tampil di bagian "Umum"
- [ ] **[Quotation]** `QuotationService`: `request()` (Marketing), `startDraft()` (Estimator), `createForLead()` per jenis, `replaceItems()` dengan section/dimensi, `savePaymentTerms()`, kalkulasi total
- [ ] **[UI]** RAB builder (bagian, dimensi, diskon, pembulatan, skema bayar); filter jenis di Index; opsi "Minta RAB" di Detail Lead
- [ ] **[Quotation]** PDF/Excel format baru
- [ ] **[Test]** Permintaan Marketing → DIMINTA → DRAFT; validasi skema (maks. 6, Σ 100%, Σ = total); RAB Proyek tanpa desain; snapshot revisi lengkap; RBAC (Marketing minta, Estimator susun)

## Di luar cakupan
Review ✔/✘, PM → CEO (04); link client (05); addendum (12).
