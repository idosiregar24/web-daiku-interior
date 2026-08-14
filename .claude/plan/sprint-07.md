# Sprint 7 — Week 13 (Bulan 4)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 0 selesai · 1 sebagian · 9 belum mulai (dari 10 task).

## Ido Refael Siregar

### Week 13 (2025-03-31)

- [ ] **[UAT]** Setup staging server: deploy ke VPS + env config + seed data dummy — *DevOps · 4 jam*
- [ ] **[UAT]** UAT sesi 1: Marketing + Designer (CRM, Design, Quotation) — *UAT · 4 jam*
- [ ] **[UAT]** UAT sesi 2: PM + Tukang (Task, Daily Form, Overtime, QA) — *UAT · 4 jam*
- [ ] **[UAT]** UAT sesi 3: Finance + CEO (Cash Flow, Termin, Dashboard) — *UAT · 4 jam*
- [ ] **[UAT]** Kompilasi feedback UAT + prioritas fix — *UAT · 4 jam*


## Jonathan Sigalingging

### Week 13 (2025-03-31)

- [ ] **[Setup]** GitHub Actions CI/CD pipeline: test → staging → production — *DevOps · 4 jam*
  - 📌 **Status:** Sebagian. Skeleton `.github/workflows/ci.yml` sudah dibuat (job test lengkap dengan service MySQL + Redis, `npm run build`, `php artisan test`). Job staging/production masih placeholder `echo`, belum ada step deploy nyata.
- [ ] **[Bugfix]** Fix UAT feedback batch 1: Task, Daily Form, Penalty, Overtime — *Bugfix · 4 jam*
- [ ] **[Bugfix]** Fix UAT feedback batch 2: QA, Finance, Logistics — *Bugfix · 4 jam*
- [ ] **[Security]** Rate limiting + audit log aksi sensitif + XSS check — *Security · 4 jam*
- [ ] **[Docs]** Seed data production-ready + dokumentasi teknis singkat — *Docs · 4 jam*

