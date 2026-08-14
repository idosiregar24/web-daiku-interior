# Sprint 5 — Week 9–Week 10 (Bulan 3)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 0 selesai · 1 sebagian · 26 belum mulai (dari 27 task).

## Ido Refael Siregar

### Week 9 (2025-03-03)

- [ ] **[Logistics]** MaterialController + Material model + margin kalkulasi otomatis — *Backend · 4 jam* _(Catatan CSV: sellPrice - costPrice)_
- [ ] **[Logistics]** Material list page: tabel + margin profit + alert stok minimum — *Frontend · 4 jam* _(Catatan CSV: badge merah jika < min_stock)_
- [ ] **[Logistics]** Stok management: penerimaan + pemakaian per proyek — *Fullstack · 4 jam* _(Catatan CSV: validasi stok tidak negatif)_
- [ ] **[Logistics]** AssetController + Asset model + CRUD aset inventaris — *Fullstack · 4 jam*
- [ ] **[Logistics]** Export Excel: daftar material + aset — *Backend · 4 jam* _(Catatan CSV: Laravel Excel)_

### Week 10 (2025-03-10)

- [ ] **[Notifications]** Trigger: lead follow-up jatuh tempo → Marketing — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: design ACC → Estimator + PM — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: quotation submit → CEO + PM — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: quotation approve/reject → Estimator + Marketing — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: deal confirmed → PM + CEO + Finance + Logistics — *Backend · 4 jam*
- [ ] **[Notifications]** Trigger: task overdue → PM proyek terkait — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: penalti dijatuhkan → Tukang + Finance — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: termin overdue → Finance + CEO — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: QA reject 2x berturut-turut → CEO — *Backend · 2 jam*


## Jonathan Sigalingging

### Week 9 (2025-03-03)

- [ ] **[Notifications]** Setup Laravel Echo + Soketi di frontend (echo.ts) — *Setup · 4 jam* _(Catatan CSV: pusher-js + private channel)_
  - 📌 **Status:** Sebagian. `resources/js/lib/echo.ts` sudah dibuat saat instalasi stack awal. Belum diuji end-to-end karena Soketi belum berjalan (butuh Docker).
- [ ] **[Notifications]** NotificationController + Laravel Notification class base — *Backend · 4 jam*
- [ ] **[Notifications]** Broadcast: NotificationCreated event → Echo private channel user — *Backend · 4 jam*
- [ ] **[Notifications]** Notification bell realtime: subscribe Echo channel di React — *Frontend · 4 jam* _(Catatan CSV: badge counter update live)_
- [ ] **[Notifications]** Notification list page: mark as read, mark all read, riwayat 90 hari — *Frontend · 4 jam*

### Week 10 (2025-03-10)

- [ ] **[Notifications]** Trigger: task baru di-assign → Tukang bersangkutan — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: QA form dibuat → tim QA — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: QA approve/reject → PM proyek — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: overtime approved PM → Tukang + Finance — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: overtime approved Finance → Tukang — *Backend · 2 jam*
- [ ] **[Notifications]** Trigger: daily form belum diisi jam 20:00 (reminder) → Tukang — *Backend · 2 jam* _(Catatan CSV: 30 menit sebelum penalti)_
- [ ] **[Analytics]** AnalyticsController: query agregasi per widget CEO — *Backend · 4 jam*
- [ ] **[Analytics]** CEO Dashboard layout: grid widget + data dari AnalyticsController — *Frontend · 4 jam*

