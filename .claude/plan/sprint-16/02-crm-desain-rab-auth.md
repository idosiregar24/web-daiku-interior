# Sprint 16 · 02 — CRM, Desain, RAB, Auth

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K2, K4–K9
> Status: **selesai 2026-10-07** · Butuh Sub 01

Cara kerja per file: buka Form Request pasangannya (`app/Http/Requests/…`),
beri `required` pada label yang servernya `required`, beri bintang dinamis
untuk `required_if`, hapus "(opsional)", lalu samakan Zod bila berbeda
(K9). Semua path relatif terhadap `resources/js/`.

## Checklist
### CRM
- [x] `Components/modules/crm/LeadStatusDialog.tsx`: alasan LOST wajib
      (PRD §4.1), jadi bintang dinamis saat status LOST
- [x] `Components/modules/crm/LeadTimeline.tsx`
- [x] `Components/modules/crm/SubmitLeadRequestDialog.tsx`: catatan RAB
      lawan "(opsional)" bersyarat `isRab`
- [x] `Components/modules/crm/SurveyFormFields.tsx`

### Desain
- [x] `Components/modules/design/AssignDesignDialog.tsx`
- [x] `Components/modules/design/DesignRevisionDialog.tsx`
- [x] `Pages/Design/Show.tsx`

### Quotation / RAB
- [x] `Components/modules/quotation/RabBuilder.tsx`: bintang di header
      kolom item (K8)
- [x] `Components/modules/quotation/RabReferenceFields.tsx`
- [x] `Components/modules/quotation/QuotationReviewPanel.tsx`
- [x] `Components/modules/quotation/QuotationDecisionDialog.tsx`: alasan
      tolak, bintang dinamis
- [x] `Components/modules/quotation/CancelQuotationDialog.tsx`
- [x] `Components/modules/quotation/PaymentTermsEditor.tsx` (skema bayar)
- [x] `Pages/Public/Quotation.tsx`: link klien (alasan tolak/revisi). Cek
      tampilan di format surat.

### Auth & profil
- [x] `Pages/Auth/Login.tsx` (Email, Password)
- [x] `Pages/Auth/ForgotPassword.tsx`
- [x] `Pages/Auth/ResetPassword.tsx`
- [x] `Pages/Auth/ConfirmPassword.tsx`
- [x] `Pages/Auth/Users/Create.tsx`
- [x] `Pages/Auth/Users/Edit.tsx`: password di Edit biasanya `nullable`,
      jadi tanpa bintang
- [x] `Pages/Profile/Partials/UpdateProfileInformationForm.tsx`
- [x] `Pages/Profile/Partials/UpdatePasswordForm.tsx`

### Selesai
- [x] `npm run build` lulus · cek di browser: Login, RabBuilder, link klien

## Catatan pelaksanaan (2026-10-07)
- Aturan server dibaca juga dari controller yang memvalidasi inline
  (`LeadFollowUpController`, `LeadSurveyController`), bukan hanya Form Request.
- Bintang dinamis: Alasan Lost (hanya tampil saat LOST), Catatan permintaan
  (wajib hanya untuk RAB — `isRab`), Nama RAB (hanya tampil untuk "Lainnya"),
  kolom "Tanda" review RAB (wajib hanya tahap PM — tahap CEO boleh kosong,
  sesuai `superRefine` & `QuotationService::review()`), header RabBuilder /
  skema bayar hanya saat `editable`.
- Link klien: centang persetujuan (`accepted`) diberi bintang (pengecualian K6).
- Tidak diberi bintang: catatan umum review (wajib hanya bila "kembalikan"
  tanpa item ✘ — aturan gabungan, pesan error Zod sudah menjelaskan),
  catatan per item ✘ (per baris).
- Zod ≠ server: tidak ditemukan.
- `PaymentTermsEditor.tsx` dan `RabBuilder.tsx` dikerjakan lewat header
  kolom (K8). Halaman Profil masih berlabel Inggris bawaan Breeze ("Name",
  "Current Password") — di luar lingkup, dicatat untuk Sub 06.
- Dicek di browser (Chrome headless): halaman Login — bintang ±2px dari teks.
