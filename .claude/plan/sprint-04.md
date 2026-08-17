# Sprint 4 — Week 7–Week 8 (Bulan 2)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 20 selesai · 0 sebagian · 0 belum mulai (dari 20 task).

## Ido Refael Siregar

### Week 7 (2025-02-17)

- [x] **[QA]** QAForm model + auto-create trigger saat PM mark milestone selesai — *Backend · 4 jam* _(Catatan CSV: observer atau service)_ — `MilestoneService::markDone()` men-trigger `QaFormService::createForMilestone()`, bukan observer (satu-satunya jalur, PRD: "dibuat otomatis oleh sistem, bukan PM/QA").
- [x] **[QA]** QAFormController: show, update (approve/reject + checklist) — *Backend · 4 jam* — plus `index()` (list, tidak diminta CSV tapi perlu supaya QA punya cara menemukan form pending-nya).
- [x] **[QA]** QA form page: checklist item list + approve/reject + catatan — *Frontend · 4 jam* — `Pages/QA/Show.tsx` + `Pages/QA/Index.tsx`.
- [x] **[QA]** Blocking mechanism: milestone berikutnya locked jika QA pending — *Backend · 4 jam* _(Catatan CSV: policy / service check)_ — sudah otomatis dari status machine (`QA_WAITING` gate + `advanceNextMilestone()`), bukan Policy terpisah — tidak ada ownership check yang perlu di-Policy-kan di sini (role-only, sesuai matriks §7.1).
- [x] **[QA]** Rejection counter: jika reject 2x → notif otomatis ke CEO — *Backend · 4 jam* — `QaFormService::review()`.

### Week 8 (2025-02-24)

- [x] **[Finance]** TerminController + TerminService: CRUD + jadwal Sabtu otomatis — *Backend · 4 jam* _(Catatan CSV: getNextSaturday logic)_ — `create()`/`markPaid()` (tidak ada `update()`/`destroy()` — PRD §7.1 matriks tidak beri siapapun U selain "mark paid", tidak ada D sama sekali).
- [x] **[Finance]** Validasi total persentase termin = 100% di TerminService — *Backend · 4 jam* — ditegakkan sebagai batas atas (≤100%) per `create()`, bukan pemaksaan "harus tepat 100%" di satu transaksi (PM boleh menjadwalkan bertahap).
- [x] **[Finance]** Termin list page Finance: status chip + tombol mark as paid — *Frontend · 4 jam* — `Pages/Finance/Termins/Index.tsx` (Finance/CEO — PM lihat/schedule termin miliknya lewat tab Finance di Project Show, lihat catatan RBAC di bawah).
- [x] **[Finance]** Export invoice PDF per termin: DomPDF template — *Backend · 4 jam* — `resources/views/pdf/termin.blade.php`.
- [x] **[Finance]** TerminReminderJob: notif H-3 sebelum jadwal termin ke Finance — *Backend · 4 jam* _(Catatan CSV: Laravel Scheduler)_ — `routes/console.php`, jalan tiap pagi jam 08:00 WIB.


## Jonathan Sigalingging

### Week 7 (2025-02-17)

- [x] **[Projects]** ProgressLogController + ProgressLog model — *Backend · 4 jam*
- [x] **[Projects]** Progress log form PM: persentase + deskripsi + URL referensi — *Frontend · 4 jam* — `ProgressLogFormDialog.tsx`, dalam tab "Progress" baru di Project Show.
- [x] **[Projects]** Progress timeline component: log kronologis per proyek — *Frontend · 4 jam* — `ProgressTimeline.tsx`.
- [x] **[Projects]** Project overview: progress bar dari log terbaru — *Frontend · 4 jam* — ditambahkan ke tab Overview Project Show.
- [x] **[Tasks]** Task overdue detection: update status OVERDUE via scheduled job tengah malam — *Backend · 4 jam* — `TaskOverdueJob` (`TaskService::markOverdueTasks()`, reuse `Task::scopeOverdue()` yang sudah ada dari Sprint 3), `routes/console.php` `dailyAt('00:00')`.

### Week 8 (2025-02-24)

- [x] **[Finance]** FinanceTransactionController + model — *Backend · 4 jam* — plus migrasi rekonsiliasi `type`+`kategori` split (`daiku_schema.sql`) yang sengaja ditunda dari Sprint 1 ke sprint ini.
- [x] **[Finance]** Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan — *Frontend · 4 jam* _(Catatan CSV: Recharts bar chart)_ — `Pages/Finance/Dashboard.tsx`. Agregasi bulan dilakukan di PHP (Collection), bukan `DATE_FORMAT()` SQL — lihat catatan MySQL-only di `plan/README.md`.
- [x] **[Finance]** Transaksi list: filter by type/tanggal/proyek + total summary — *Frontend · 4 jam* — `Pages/Finance/Transactions/Index.tsx`.
- [x] **[Finance]** Export Excel laporan cash flow bulanan: Laravel Excel — *Backend · 4 jam* — `App\Exports\CashFlowExport`.
- [x] **[Finance]** Pencatatan upah tukang per task selesai + staff payment list — *Fullstack · 4 jam* — `Pages/Finance/StaffPayments/Index.tsx`, "sudah dibayar" dideteksi dari `FinanceTransaction` beracuan `reference_id`+`kategori=GAJI_KARYAWAN`, bukan kolom baru di `Task` (task tetap immutable, golden rule #6).

## Di luar 20 task CSV, dikerjakan karena diminta eksplisit di sesi ini

- **Milestone tab redesign**: `MilestoneGanttCalendar.tsx` — timeline horizontal ala Gantt/kalender (posisi persentase terhadap rentang tanggal proyek), menggantikan `MilestoneTimeline.tsx` (dot-timeline vertikal, dihapus). Tombol "Tandai Selesai" (PM, memicu `milestones.markDone`) juga dipindah ke sini.
- **Task tab redesign**: tabel per-assignee (`TaskAssigneeTable`, dikelompokkan via `useMemo` di `Projects/Show.tsx`) menggantikan satu tabel flat.
- Notifikasi bell di `AppLayout.tsx` disambungkan ke data nyata (`notifications` prop dari `HandleInertiaRequests`) — sebelumnya placeholder statis "Belum ada notifikasi."; `NotificationController::markAsRead` (dibuat sebelum sprint ini dimulai) baru di-wire ke route di sprint ini.
