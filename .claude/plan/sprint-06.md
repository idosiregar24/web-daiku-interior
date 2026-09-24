# Sprint 6 — Week 11–Week 12 (Bulan 3)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 21 selesai · 0 sebagian · 0 belum mulai (dari 21 task). Dikerjakan 2026-09-24.

## Ido Refael Siregar

### Week 11 (2025-03-17)

- [x] **[Analytics]** Widget: pipeline funnel chart (Recharts) — *Frontend · 3 jam* (kumulatif, dari `pipeline_logs`)
- [x] **[Analytics]** Widget: active projects overview + progress bar — *Frontend · 1 jam*
- [x] **[Analytics]** Widget: revenue vs target chart 6 bulan — *Frontend · 4 jam* _(Catatan CSV: input target manual per bulan)_ (tabel `revenue_targets`, dialog "Atur Target")
- [x] **[Analytics]** Widget: penalty summary + family gathering fund total — *Frontend · 4 jam*
- [x] **[Analytics]** Widget: team performance PM + overdue heatmap — *Frontend · 4 jam*
- [x] **[Analytics]** Partial dashboard per divisi (Marketing, Finance, Logistics) — *Frontend · 4 jam*
  - Marketing `crm.dashboard`, Finance `finance.dashboard` (sudah ada), Logistics = ringkasan di `logistics.materials.index`. Tiap role kini mendarat di halaman PRD §8.4-nya (`RoleRedirectService`).

### Week 12 (2025-03-24)

- [x] **[Testing]** Unit test: PenaltyService (form missing, tidak ganda) — *Testing · 4 jam* _(Catatan CSV: Pest PHP)_
- [x] **[Testing]** Unit test: TerminService (jadwal Sabtu, validasi total 100%) — *Testing · 4 jam*
- [x] **[Testing]** Feature test: alur presales Lead→Design→Quotation→Deal→Project — *Testing · 4 jam*
- [x] **[Testing]** RBAC test: semua role × semua route sensitif — *Testing · 4 jam* (per modul, dataset per role; + `SeederTest`)
- [x] **[Testing]** Bug fix sprint: semua issue dari hasil testing — *Bugfix · 4 jam* — lihat README "Bug yang ditemukan".


## Jonathan Sigalingging

### Week 11 (2025-03-17)

- [x] **[Logistics]** ProjectMaterial: kebutuhan material per proyek + input pemakaian — *Fullstack · 4 jam* (tab "Material" di detail proyek; `qty_used` hanya bertambah lewat stock-out)
- [x] **[Projects]** Milestone status auto-update: OVERDUE jika lewat targetDate (scheduler) — *Backend · 4 jam* (`MilestoneOverdueJob` 00:00; OVERDUE tetap bisa di-mark done)
- [x] **[Projects]** Project selesai flow: semua milestone COMPLETED → project COMPLETED — *Backend · 4 jam* (hanya lewat approval QA)
- [x] **[Tasks]** PM task board: kanban-lite per milestone (groupBy status) — *Frontend · 4 jam* (`TaskKanbanBoard`)
- [x] **[Tasks]** Penalty list PM: per tukang + total + link ke family fund — *Frontend · 4 jam* (link hanya untuk CEO/Finance — PM tidak punya akses Family Fund di §7.1)

### Week 12 (2025-03-24)

- [x] **[Testing]** Unit test: OvertimeService (approval flow, status transition) — *Testing · 4 jam*
- [x] **[Testing]** Feature test: task lock mechanism + status update Tukang — *Testing · 4 jam*
- [x] **[Testing]** Feature test: QA blocking mechanism + rejection counter — *Testing · 4 jam*
- [x] **[Testing]** Feature test: Finance termin + family gathering fund — *Testing · 4 jam*
- [x] **[Testing]** Bug fix sprint + regression test — *Bugfix · 4 jam*
