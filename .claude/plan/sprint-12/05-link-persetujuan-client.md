# Sprint 12 · 05 — Halaman RAB untuk Client (link persetujuan)

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 04 · Keputusan: #13, #14

## Tujuan
Marketing mengirim link; client membuka tanpa login dan menyetujui dengan
konfirmasi centang. Menggantikan jalur lama `crm.leads.confirmDeal`.

## File yang disentuh
- `routes/web.php` (route publik baru; `crm.leads.confirmDeal` ±baris 113 diganti)
- `app/Services/{QuotationService,LeadService}.php` (`LeadService::confirmDeal`)
- `app/Http/Controllers/CRM/LeadController.php` (`confirmDeal`), `Quotation/QuotationController.php`
- Baru: `app/Http/Controllers/PublicQuotationController.php`, layout publik
  `resources/js/Layouts/PublicLayout.tsx`, halaman `Pages/Public/Quotation.tsx`
- `app/Events/QuotationClientApproved.php` (baru)
- Test: `tests/Feature/Quotation/*`, `tests/Feature/Security/*`

## Rancangan
- Kolom `quotations`: `public_token` (unik, `Str::random(48)`), `sent_at`,
  `sent_by`, `client_approved_at`, `client_approved_ip`,
  `client_approved_user_agent`. Token dibuat saat "Kirim ke Client".
- Route publik di luar `auth`: `GET /penawaran/{token}` dan
  `POST /penawaran/{token}/setujui`, `throttle:30,1`, header `X-Robots-Tag:
  noindex`, CSRF tetap aktif.
- **Data yang dikirim = whitelist** (Resource/array khusus): identitas
  situs, nama & alamat client, jenis, nomor/versi, tanggal & berlaku
  sampai, bagian + item (nama, dimensi, volume, satuan, harga, subtotal),
  total, diskon, pembulatan, skema bayar. **Tidak**: review ✔/✘, catatan
  internal, harga modal, user internal.
- Server menolak setuju bila: bukan versi terbaru, lewat `valid_until`,
  sudah disetujui, status ≠ SENT_TO_CLIENT. Tampilan: "Penawaran ini sudah
  diperbarui" / "Masa berlaku habis — hubungi Marketing" / "Telah disetujui
  pada ...".
- UI: tombol "Setujui Penawaran" → Dialog dengan Checkbox wajib "Saya telah
  membaca dan menyetujui penawaran ini" → tombol final aktif setelah
  dicentang. Tidak ada tombol revisi (revisi via WhatsApp).
- Marketing: tombol "Salin Link" + "Kirim via WhatsApp"
  (`https://wa.me/<nomor>?text=...`, nomor dari `leads.contact`).
- Setelah setuju: status CLIENT_APPROVED, audit
  `quotation.client_approved`, notif Marketing/Estimator/PM, dispatch event
  `QuotationClientApproved` (listener dipasang di 06/07/08: survey →
  MENUNGGU_BAYAR, desain dibuat, pop-up Buka Proyek).
- `crm.leads.confirmDeal` dihapus; status lead → CLOSING dipindah ke
  listener persetujuan RAB PROYEK.

## Checklist
- [x] **[Database]** Kolom token & persetujuan di `quotations`
- [x] **[Quotation]** Controller publik + whitelist data + aturan tolak + event `QuotationClientApproved`
- [x] **[UI]** `PublicLayout` + halaman penawaran (desktop & HP) + dialog konfirmasi centang; tombol Salin Link / WhatsApp di `Quotation/Show.tsx`
- [x] **[CRM]** Hapus `confirmDeal` lama; lead → CLOSING lewat listener PROYEK
- [x] **[Test]** Token acak tidak bisa ditebak/diulang; versi lama/kedaluwarsa/sudah disetujui ditolak; respons tidak mengandung field internal; throttle; tanpa centang ditolak; event ter-dispatch
- [x] **[Security]** `/security-review` untuk route publik

## Selesai bila
Halaman terbuka tanpa login di HP, tampil sama dengan PDF, dan tidak ada
data internal di payload Inertia (cek di test).
