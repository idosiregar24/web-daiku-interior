# Sprint 12 · 02 — CRM: Follow-up Bertingkat, Survey, Alamat

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **belum dikerjakan** · Prasyarat: — · Keputusan: #1–#5

## Tujuan
Lead menyimpan tanggal masuk/pertama dihubungi, alamat + link Maps,
follow-up bertingkat, dan jadwal survey (bisa berulang).

## File yang disentuh
- `app/Models/Lead.php`, `app/Services/LeadService.php`, `app/Http/Controllers/CRM/LeadController.php`
- `app/Http/Requests/CRM/{StoreLeadRequest,UpdateLeadRequest}.php` (+ request baru)
- `follow_up_date` juga dibaca di: `app/Http/Controllers/DashboardController.php`,
  `app/Services/Kpi/KpiMetricRegistry.php` — pindahkan ke tabel follow-up
- `resources/js/Pages/CRM/{Index,Show,Dashboard}.tsx`, `resources/js/types/index.d.ts`
- `database/seeders/DemoDataSeeder.php`, `database/factories/LeadFactory.php`
- Test: `tests/Feature/CRM/*`

## Rancangan
```
leads (existing)   + first_contacted_at (date, nullable)
                   + address (text, nullable), maps_url (string, nullable — http/https saja)
                   follow_up_date → dihapus setelah backfill ke lead_follow_ups (FU-1)
lead_follow_ups    lead_id, sequence, scheduled_date, done_at (nullable), result_note,
                   created_by      index(scheduled_date, done_at)
lead_surveys       lead_id, sequence, scheduled_at, address, maps_url (default dari lead),
                   is_outside_pekanbaru (bool), quotation_id (nullable, diisi sub-plan 03),
                   status (DIJADWALKAN | MENUNGGU_BAYAR | SIAP | SELESAI | BATAL),
                   result_note, created_by
```
- `sequence` dihitung di service dalam transaksi (`lockForUpdate` di lead).
- **Saran Lost**: FU berikutnya ≥ 5 → `Notice` warning "Sudah 4 kali
  follow-up — pertimbangkan tandai Lost." Tidak memblokir.
- **Survey**: dalam Pekanbaru → DIJADWALKAN → SELESAI. Luar Pekanbaru →
  MENUNGGU_BAYAR; transisi ke SIAP **hanya** lewat verifikasi invoice
  (sub-plan 06 memasang listener). Di sini: method
  `LeadService::markSurveyReady()` yang dipanggil listener, dan tombol
  Marketing untuk SIAP/SELESAI ditolak service bila masih MENUNGGU_BAYAR.
- **Dialog "Ajukan Desain/Survey"** di Detail Lead (menggantikan tombol
  "Deal Desain"; status lead tetap pindah ke `DEAL_DESAIN`, label
  tampilannya "Pengajuan Desain/Survey"). Pilihan: Jadwalkan Survey
  (aktif di sini), Minta RAB Jasa Survey / Jasa Desain / Proyek (aktif di
  sub-plan 03 — sementara disabled "Segera").
- Detail Lead: kartu alamat (link "Buka di Google Maps"), timeline FU &
  survey dengan tombol "Tambah Follow-up", "Tandai Selesai", "Jadwalkan
  Survey", "Batalkan Survey" (alasan wajib).

## Checklist
- [ ] **[Database]** Kolom lead baru; `lead_follow_ups`, `lead_surveys`; backfill `follow_up_date` → FU-1 lalu drop (reversibel)
- [ ] **[CRM]** `LeadService`: tambah/selesaikan FU, jadwalkan/ubah/batalkan/selesaikan survey, `markSurveyReady()`; audit untuk batal
- [ ] **[CRM]** Dashboard, pengingat FU jatuh tempo, KPI metric membaca `lead_follow_ups`
- [ ] **[UI]** Form lead (alamat, Maps, tanggal pertama dihubungi); timeline FU & survey; dialog "Ajukan Desain/Survey"; saran Lost
- [ ] **[Test]** Urutan sequence, saran Lost ≥ FU-5, survey luar kota tidak bisa SIAP/SELESAI manual, `maps_url` non-http ditolak, RBAC (Marketing/CEO tulis; lainnya baca/403 sesuai route)

## Selesai bila
Semua halaman CRM & dashboard yang dulu memakai `follow_up_date` tetap
benar; DemoDataSeeder punya lead dengan FU-1..FU-5 dan satu survey luar kota.
