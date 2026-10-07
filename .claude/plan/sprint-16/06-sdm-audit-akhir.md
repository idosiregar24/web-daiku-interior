# Sprint 16 · 06 — SDM & Audit Akhir

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K2, K4–K9
> Status: **selesai 2026-10-07** · Butuh Sub 01–05

## Checklist
### SDM
- [x] `Components/modules/hr/EmployeeFormDialog.tsx`: jabatan wajib
      lewat `position_id`
- [x] `Components/modules/hr/SalaryChangeDialog.tsx`
- [x] `Components/modules/hr/SalaryDecisionDialog.tsx`: alasan tolak,
      bintang dinamis
- [x] `Components/modules/hr/DisciplinaryRecordDialog.tsx`
- [x] `Components/modules/hr/DisciplineVoidDialog.tsx`
- [x] `Components/modules/hr/KpiTemplateDialog.tsx`
- [x] `Components/modules/hr/KpiOpenPeriodDialog.tsx`
- [x] `Components/modules/hr/ReviewCreateDialog.tsx`
- [x] `Components/modules/hr/ReviewDecisionDialog.tsx`
- [x] `Pages/HR/Structure/Index.tsx` (Divisi & Jabatan)
- [x] `Pages/HR/Discipline/Index.tsx`
- [x] `Pages/HR/Reviews/Show.tsx`

### Audit seluruh form
- [x] `grep -rn "(opsional)" resources/js` hasilnya 0 untuk label (K4)
- [x] `grep -rn "wajib jika" resources/js` hasilnya 0 untuk label
- [x] Cari form yang terlewat survei awal: file berisi `zodResolver` atau
      `useForm` tapi tanpa `required` di label mana pun, lalu periksa satu
      per satu (`DesignDiscussionPanel` dan input ber-`aria-label` tanpa
      label tampak)
- [x] Cek silang acak 10 form: setiap aturan `required` di Form Request
      punya bintang, dan setiap bintang punya aturan `required`
- [x] Rekap perbedaan Zod/server dari Sub 02–06 di induk §6 (baru)

### Penutup
- [x] `npm run build` lulus
- [x] Tabel Sprint 16 di `plan/README.md` diperbarui, status induk diubah
      menjadi selesai

## Catatan pelaksanaan (2026-10-07)
- SDM: Jabatan (`position_id`) & Gaji Pokok (hanya saat tambah — saat ubah
  tampil sebagai teks, berubah lewat `salary_changes`) berbintang; Metrik
  otomatis KPI berbintang (tampil hanya untuk sumber AUTO,
  `required_if`); bobot evaluasi berbintang, nilai kualitatif 1–5
  `nullable` → tanpa bintang; Karyawan di "Buat evaluasi" hanya tampil
  pada mode satu karyawan.
- Audit: label "(opsional)" = 0. Sisa kata "opsional" hanya di
  *placeholder* tanpa label tampak (kolom lampiran diskusi desain, catatan
  per item QA) — dibiarkan, bukan label.
- Form ber-`useForm` tanpa bintang sama sekali, dicek satu per satu & memang
  tanpa kolom wajib: `DesignDiscussionPanel` (composer chat, tanpa label
  tampak), `KpiManualInput` (`actual` `nullable`), `ClientNotesCard`
  (`nullable`), `VerifyEmail` (tanpa isian). `<Label>` polos tanpa bintang:
  filter "aktif saja" SP & link referensi RAB — keduanya opsional.
- Cek silang: setiap file Sub 02–06 dibandingkan langsung dengan Form
  Request/validasi controller-nya saat diberi bintang (lihat catatan tiap sub).
- Ditunda (di luar lingkup): halaman Profil masih berlabel Inggris bawaan
  Breeze ("Name", "Current Password", …).
