# Sprint 3 — Week 5–Week 6 (Bulan 2)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 19 selesai · 1 sebagian · 0 belum mulai (dari 20 task).

## Ido Refael Siregar

### Week 5 (2025-02-03)

- [x] **[Quotation]** Quotation approval flow: CEO approve → PM approve (sequential) — *Backend · 4 jam* _(Catatan CSV: QuotationApproval model)_
- [x] **[Quotation]** Quotation approval UI: tombol approve/reject + catatan — *Frontend · 4 jam*
- [x] **[Quotation]** Export PDF quotation: DomPDF template RAB Daiku — *Backend · 4 jam*
- [x] **[CRM]** Pipeline dashboard Marketing: funnel chart + statistik lead — *Frontend · 4 jam* _(Catatan CSV: Recharts)_
- [ ] **[Review]** Code review Jonathan Sigalingging + update dokumentasi + bug fix minggu 1-4 — *Review · 4 jam*
  📌 **Status: Sebagian** — tidak ada rekan kerja terpisah di alur solo-agent ini, jadi "code review" diadaptasi jadi self-review lewat penambahan test menyeluruh (257 test lulus setelah sprint ini, mencakup regresi minggu 1-4) — proses itu sendiri **menemukan & memperbaiki 4 bug nyata** minggu ini (lihat `.claude/plan/README.md`). Dokumentasi ter-update (checklist ini + README deviations). "Bug fix minggu 1-4" spesifik tidak dikerjakan tanpa laporan bug konkret — tidak ada fishing expedition tanpa arah jelas.

### Week 6 (2025-02-10)

- [x] **[Penalty]** PenaltyService: cek form harian missing, generate penalti Rp50.000 — *Backend · 4 jam*
- [x] **[Penalty]** DailyPenaltyJob: dispatch jam 21:00 Senin–Sabtu via Laravel Scheduler — *Backend · 4 jam* _(Catatan CSV: routes/console.php)_
- [x] **[Penalty]** FamilyGatheringFund: akumulasi penalti otomatis + model — *Backend · 4 jam*
- [x] **[Penalty]** FamilyGatheringFund page Finance: total dana + riwayat — *Frontend · 4 jam*
- [x] **[Review]** Code review Jonathan Sigalingging + integration test alur presales end-to-end — *Review · 4 jam*
  _(Catatan: "code review" diadaptasi jadi self-review — lihat Week 5's note. "Integration test alur presales end-to-end" ada di `tests/Feature/PresalesIntegrationTest.php` — satu test yang jalan lewat rantai penuh Lead→DEAL_DESAIN→Design→Client ACC→Quotation RAB→submit→CEO approve→PM approve→confirmDeal→Project, lulus di percobaan pertama.)_

## Jonathan Sigalingging

### Week 5 (2025-02-03)

- [x] **[Tasks]** Task list page Tukang: hanya task sendiri, immutable (no edit btn) — *Frontend · 4 jam*
  _(Catatan: sudah terpenuhi oleh `Tasks/Index.tsx` dari Sprint 2 Week 4 — field-staff-scoped query + tabel read-only, tidak ada tombol edit judul/deskripsi/tanggal. Diverifikasi ulang sprint ini, tidak ada perubahan kode baru.)_
- [x] **[Tasks]** Task status update Tukang: PENDING→IN_PROGRESS→DONE — *Fullstack · 4 jam* _(Catatan CSV: policy: hanya assignee)_
  _(Catatan: sudah terpenuhi oleh `TaskPolicy::updateStatus()` + `TaskStatusDialog` dari Sprint 2 Week 4. CSV pakai istilah "IN_PROGRESS", proyek ini pakai "ONPROGRESS" sesuai PRD §4.5 — sudah konsisten sejak awal.)_
- [x] **[DailyForm]** DailyTaskFormController: store, index by date + validasi 1/task/hari — *Backend · 4 jam*
- [x] **[DailyForm]** Daily form page Tukang: form per task aktif hari ini — *Frontend · 4 jam*
- [x] **[DailyForm]** Validasi deadline 21:00: form tidak bisa disubmit setelah jam 21:00 — *Backend · 4 jam*

### Week 6 (2025-02-10)

- [x] **[Overtime]** OvertimeRequest model + OvertimeController: store (Tukang) — *Backend · 4 jam*
- [x] **[Overtime]** Overtime approval PM: list pengajuan + approve/reject form — *Frontend · 4 jam*
- [x] **[Overtime]** Overtime approval Finance: list pending finance + approve + catat EXPENSE — *Frontend · 4 jam*
- [x] **[Overtime]** OvertimeService: alur status PENDING→APPROVED_PM→APPROVED_FINANCE — *Backend · 4 jam*
- [x] **[Overtime]** Overtime request form Tukang: jam, rate, tanggal, alasan — *Frontend · 4 jam*
