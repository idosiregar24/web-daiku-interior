# Sprint 4 — Week 7–Week 8 (Bulan 2)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 0 selesai · 0 sebagian · 20 belum mulai (dari 20 task).

## Ido Refael Siregar

### Week 7 (2025-02-17)

- [ ] **[QA]** QAForm model + auto-create trigger saat PM mark milestone selesai — *Backend · 4 jam* _(Catatan CSV: observer atau service)_
- [ ] **[QA]** QAFormController: show, update (approve/reject + checklist) — *Backend · 4 jam*
- [ ] **[QA]** QA form page: checklist item list + approve/reject + catatan — *Frontend · 4 jam*
- [ ] **[QA]** Blocking mechanism: milestone berikutnya locked jika QA pending — *Backend · 4 jam* _(Catatan CSV: policy / service check)_
- [ ] **[QA]** Rejection counter: jika reject 2x → notif otomatis ke CEO — *Backend · 4 jam*

### Week 8 (2025-02-24)

- [ ] **[Finance]** TerminController + TerminService: CRUD + jadwal Sabtu otomatis — *Backend · 4 jam* _(Catatan CSV: getNextSaturday logic)_
- [ ] **[Finance]** Validasi total persentase termin = 100% di TerminService — *Backend · 4 jam*
- [ ] **[Finance]** Termin list page Finance: status chip + tombol mark as paid — *Frontend · 4 jam*
- [ ] **[Finance]** Export invoice PDF per termin: DomPDF template — *Backend · 4 jam*
- [ ] **[Finance]** TerminReminderJob: notif H-3 sebelum jadwal termin ke Finance — *Backend · 4 jam* _(Catatan CSV: Laravel Scheduler)_


## Jonathan Sigalingging

### Week 7 (2025-02-17)

- [ ] **[Projects]** ProgressLogController + ProgressLog model — *Backend · 4 jam*
- [ ] **[Projects]** Progress log form PM: persentase + deskripsi + URL referensi — *Frontend · 4 jam*
- [ ] **[Projects]** Progress timeline component: log kronologis per proyek — *Frontend · 4 jam*
- [ ] **[Projects]** Project overview: progress bar dari log terbaru — *Frontend · 4 jam*
- [ ] **[Tasks]** Task overdue detection: update status OVERDUE via scheduled job tengah malam — *Backend · 4 jam*

### Week 8 (2025-02-24)

- [ ] **[Finance]** FinanceTransactionController + model — *Backend · 4 jam*
- [ ] **[Finance]** Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan — *Frontend · 4 jam* _(Catatan CSV: Recharts bar chart)_
- [ ] **[Finance]** Transaksi list: filter by type/tanggal/proyek + total summary — *Frontend · 4 jam*
- [ ] **[Finance]** Export Excel laporan cash flow bulanan: Laravel Excel — *Backend · 4 jam*
- [ ] **[Finance]** Pencatatan upah tukang per task selesai + staff payment list — *Fullstack · 4 jam*

