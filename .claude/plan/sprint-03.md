# Sprint 3 — Week 5–Week 6 (Bulan 2)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 0 selesai · 0 sebagian · 20 belum mulai (dari 20 task).

## Ido Refael Siregar

### Week 5 (2025-02-03)

- [ ] **[Quotation]** Quotation approval flow: CEO approve → PM approve (sequential) — *Backend · 4 jam* _(Catatan CSV: QuotationApproval model)_
- [ ] **[Quotation]** Quotation approval UI: tombol approve/reject + catatan — *Frontend · 4 jam*
- [ ] **[Quotation]** Export PDF quotation: DomPDF template RAB Daiku — *Backend · 4 jam*
- [ ] **[CRM]** Pipeline dashboard Marketing: funnel chart + statistik lead — *Frontend · 4 jam* _(Catatan CSV: Recharts)_
- [ ] **[Review]** Code review Jonathan Sigalingging + update dokumentasi + bug fix minggu 1-4 — *Review · 4 jam*

### Week 6 (2025-02-10)

- [ ] **[Penalty]** PenaltyService: cek form harian missing, generate penalti Rp50.000 — *Backend · 4 jam*
- [ ] **[Penalty]** DailyPenaltyJob: dispatch jam 21:00 Senin–Sabtu via Laravel Scheduler — *Backend · 4 jam* _(Catatan CSV: routes/console.php)_
- [ ] **[Penalty]** FamilyGatheringFund: akumulasi penalti otomatis + model — *Backend · 4 jam*
- [ ] **[Penalty]** FamilyGatheringFund page Finance: total dana + riwayat — *Frontend · 4 jam*
- [ ] **[Review]** Code review Jonathan Sigalingging + integration test alur presales end-to-end — *Review · 4 jam* _(Catatan CSV: Lead→Design→Quotation→Deal)_


## Jonathan Sigalingging

### Week 5 (2025-02-03)

- [ ] **[Tasks]** Task list page Tukang: hanya task sendiri, immutable (no edit btn) — *Frontend · 4 jam*
- [ ] **[Tasks]** Task status update Tukang: PENDING→IN_PROGRESS→DONE — *Fullstack · 4 jam* _(Catatan CSV: policy: hanya assignee)_
- [ ] **[DailyForm]** DailyTaskFormController: store, index by date + validasi 1/task/hari — *Backend · 4 jam*
- [ ] **[DailyForm]** Daily form page Tukang: form per task aktif hari ini — *Frontend · 4 jam*
- [ ] **[DailyForm]** Validasi deadline 21:00: form tidak bisa disubmit setelah jam 21:00 — *Backend · 4 jam*

### Week 6 (2025-02-10)

- [ ] **[Overtime]** OvertimeRequest model + OvertimeController: store (Tukang) — *Backend · 4 jam*
- [ ] **[Overtime]** Overtime approval PM: list pengajuan + approve/reject form — *Frontend · 4 jam*
- [ ] **[Overtime]** Overtime approval Finance: list pending finance + approve + catat EXPENSE — *Frontend · 4 jam*
- [ ] **[Overtime]** OvertimeService: alur status PENDING→APPROVED_PM→APPROVED_FINANCE — *Backend · 4 jam*
- [ ] **[Overtime]** Overtime request form Tukang: jam, rate, tanggal, alasan — *Frontend · 4 jam*

