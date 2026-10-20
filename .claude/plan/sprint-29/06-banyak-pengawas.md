# Sprint 29 · 06 — Banyak Pengawas per Proyek

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Pengawas bisa lebih dari 1" · Keputusan A1–A4
> Status: **belum dikerjakan** · Bergantung: Sub 01 · Dikerjakan sebelum Sub 07–09

## Keputusan user
> "Setara (menu opsional)" — semua Pengawas sama haknya; Pengawas tambahan
> opsional di form.

## Kondisi sekarang
Satu kolom `projects.supervisor_id`, dipakai ±21 file: `Project`
(`supervisor()`, `isSupervisedBy()`, `scopeSupervisedBy()`,
`isQaReviewableBy()`), `ProjectPolicy`, `TaskPolicy`, `QaFormPolicy`,
`ProjectService` (Buka/Ubah + notifikasi assigned/unassigned),
`SupervisorPaymentService`, `WeeklyReportService`, `TechnicalMeetingService`,
`WorkingDrawingService`, `ActionInboxService`, `ProductionService`,
`ProjectExecutionService`, `NotificationTarget`, `ValidatesProjectPeople`,
`ProjectController`, `QaFormController`, `WorkingDrawingController`,
`SupervisorPaymentController`; UI `ProjectTeamFields`, `projectWaiting.ts`,
`SupervisorFeeCard`, `DpRequirementsCard`, `ExecutionDecisionDialog`,
`TechnicalMeetingCard`, `ProjectOverviewTab`, `WorkingDrawingShow`,
`Finance/SupervisorPayments/Index`, `AppLayout`, `types/index.d.ts`.

## Rancangan
- Migrasi: tabel `project_supervisors` (`project_id`, `user_id`,
  `assigned_by`, timestamps, UNIQUE pasangan, index `user_id`) → salin
  `supervisor_id` yang terisi → hapus kolom. `down()`: kolom kembali, diisi
  pengawas pertama.
- `Project::supervisors()` (belongsToMany), `isSupervisedBy()` /
  `scopeSupervisedBy()` / `isQaReviewableBy()` lewat pivot — semua Policy
  ikut tanpa diubah satu-satu. `supervisor` (tunggal) dihapus; tempat yang
  menampilkan nama memakai daftar nama ("Andi, Budi").
- Notifikasi ke Pengawas = ke **semua** Pengawas proyek (`NotificationTarget`).
- Form Buka / Ubah Proyek (`ProjectTeamFields`): "Pengawas (QA/QC)" wajib +
  tombol "+ Tambah Pengawas" (opsional), multi-select user role QA aktif;
  notifikasi ditugaskan / dicopot per orang.
- Pembayaran upah (Sub 09) mencatat Pengawas yang mengajukan; pengecekan
  mingguan tetap satu per proyek per minggu.
- Seed demo: satu proyek dengan dua Pengawas.

## Checklist
- [ ] **[DB]** Migrasi pivot + salin data + hapus kolom, `down()` reversibel
- [ ] **[Backend]** Model & scope lewat pivot; semua pemakai `supervisor_id` / `->supervisor` (daftar di atas); `ValidatesProjectPeople` array `supervisor_ids` (min 1, role QA aktif)
- [ ] **[UI]** `ProjectTeamFields` multi; nama Pengawas berupa daftar di semua tampilan
- [ ] **[UX]** Satu Pengawas tetap tampil seperti sekarang (tidak menambah langkah); HP 390 px form proyek
- [ ] **[Test]** Pest: dua Pengawas sama-sama boleh (task, QA, laporan, bahan), QA lain 403; notifikasi ke keduanya; migrasi data lama; test lama yang memakai `supervisor_id` disesuaikan
- [ ] **[Build]** `npm run build`, Pint, suite penuh
