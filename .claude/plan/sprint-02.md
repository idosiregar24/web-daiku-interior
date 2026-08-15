# Sprint 2 — Week 3–Week 4 (Bulan 1)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 9 selesai · 1 sebagian · 10 belum mulai (dari 20 task).

## Ido Refael Siregar

### Week 3 (2025-01-20)

- [x] **[CRM]** Lead create/edit form modal + validasi client-side — *Frontend · 4 jam*
- [x] **[CRM]** Pipeline status update + PipelineLog otomatis di LeadService — *Backend · 4 jam*
- [ ] **[CRM]** Follow-up date reminder: notif + tampil di dashboard Marketing — *Fullstack · 4 jam*
  📌 **Status: Sebagian** — dashboard widget (`DashboardController` + `FollowUpReminder` di `Dashboard.tsx`) selesai, untuk CEO/MARKETING/SUPERADMIN. Komponen "notif" (push/broadcast, PRD §4.9) **belum** dibangun — belum ada modul lain yang butuh notification infra tahun ini, jadi ditunda sampai modul yang benar-benar butuh Echo/Soketi real-time muncul, bukan dibangun cuma untuk task ini.
- [x] **[CRM]** Lead → Deal: konfirmasi + trigger buat Project draft otomatis — *Backend · 4 jam*
- [x] **[Design]** DesignController + Design model + relasi Lead — *Backend · 4 jam*

### Week 4 (2025-01-27)

- [ ] **[Design]** Design page UI: brief form + input URL + status badge — *Frontend · 4 jam*
- [ ] **[Design]** Client ACC button + konfirmasi modal + trigger buka Quotation — *Fullstack · 4 jam*
- [ ] **[Quotation]** Database migration: quotations, quotation_items, quotation_approvals — *Backend · 4 jam*
- [ ] **[Quotation]** QuotationController + QuotationService + Form Request — *Backend · 4 jam*
- [ ] **[Quotation]** Quotation builder UI: tambah/hapus item RAB + kalkulasi otomatis — *Frontend · 4 jam*


## Jonathan Sigalingging

### Week 3 (2025-01-20)

- [x] **[Projects]** Project model + Milestone model + Task model + relasi — *Backend · 4 jam* _(Catatan CSV: fillable, scope, relasi)_
- [x] **[Projects]** ProjectController: index, show, store + StoreProjectRequest — *Backend · 4 jam*
- [x] **[Projects]** Project index page: daftar proyek + status chip + filter PM — *Frontend · 4 jam*
- [x] **[Projects]** Project detail page: layout tab (Overview, Milestone, Task, Finance) — *Frontend · 4 jam*
- [x] **[Projects]** MilestoneController: CRUD + urutan order + MilestoneService — *Backend · 4 jam*

### Week 4 (2025-01-27)

- [ ] **[Projects]** Milestone list component: timeline visual per proyek — *Frontend · 4 jam*
- [ ] **[Tasks]** TaskController: index, store, updateStatus + StoreTaskRequest — *Backend · 4 jam*
- [ ] **[Tasks]** Task model: is_locked logic + scope overdue, byAssignee — *Backend · 4 jam*
- [ ] **[Tasks]** Task assignment form PM: pilih tukang, due date, rate per task — *Frontend · 4 jam*
- [ ] **[Tasks]** Task list PM: filter by milestone/assignee/status — *Frontend · 4 jam*

