# Sprint 5 — Week 9–Week 10 (Bulan 3)

> Sumber: `.claude/File Skema/Daiku v1.0.0/Daiku-Task-Schedule.csv`. Tanggal di CSV memakai kalender placeholder (mulai 2025-01-06) dari draft awal — jadikan acuan **urutan minggu** (Week N), bukan tanggal absolut, saat sprint ini benar-benar dimulai. Checklist di bawah boleh dicentang langsung di file ini seiring progres.

**Ringkasan status:** 26 selesai · 1 sebagian · 0 belum mulai (dari 27 task). Dikerjakan 2026-09-24 — lihat `README.md` bagian "Sprint 5–7".

## Ido Refael Siregar

### Week 9 (2025-03-03)

- [x] **[Logistics]** MaterialController + Material model + margin kalkulasi otomatis — *Backend · 4 jam* _(Catatan CSV: sellPrice - costPrice)_
  - `Material::margin`/`margin_percent`/`is_low_stock` (accessor, tidak disimpan).
- [x] **[Logistics]** Material list page: tabel + margin profit + alert stok minimum — *Frontend · 4 jam* _(Catatan CSV: badge merah jika < min_stock)_
  - `Pages/Logistics/Materials/Index.tsx` + notifikasi `material_low_stock` ke Logistics saat stok *melewati* batas minimum.
- [x] **[Logistics]** Stok management: penerimaan + pemakaian per proyek — *Fullstack · 4 jam* _(Catatan CSV: validasi stok tidak negatif)_
  - `StockService` (row lock, ledger `stock_movements` append-only, pemakaian wajib proyek).
- [x] **[Logistics]** AssetController + Asset model + CRUD aset inventaris — *Fullstack · 4 jam*
- [x] **[Logistics]** Export Excel: daftar material + aset — *Backend · 4 jam* _(Catatan CSV: Laravel Excel)_

### Week 10 (2025-03-10)

- [x] **[Notifications]** Trigger: lead follow-up jatuh tempo → Marketing — *Backend · 2 jam* (`LeadFollowUpReminderJob`, 08:00)
- [x] **[Notifications]** Trigger: design ACC → Estimator + PM — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: quotation submit → CEO + PM — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: quotation approve/reject → Estimator + Marketing — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: deal confirmed → PM + CEO + Finance + Logistics — *Backend · 4 jam*
- [x] **[Notifications]** Trigger: task overdue → PM proyek terkait — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: penalti dijatuhkan → Tukang + Finance — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: termin overdue → Finance + CEO — *Backend · 2 jam* (`TerminOverdueJob` — juga set status `OVERDUE`)
- [x] **[Notifications]** Trigger: QA reject 2x berturut-turut → CEO — *Backend · 2 jam*


## Jonathan Sigalingging

### Week 9 (2025-03-03)

- [ ] **[Notifications]** Setup Laravel Echo + Soketi di frontend (echo.ts) — *Setup · 4 jam* _(Catatan CSV: pusher-js + private channel)_
  - 📌 **Status:** Sebagian. Kode lengkap (`lib/echo.ts` hanya membuka socket bila `VITE_BROADCAST_CONNECTION=pusher`, hook `useRealtimeNotifications`, event `NotificationCreated` di private channel). Belum diuji end-to-end dengan Soketi sungguhan — butuh Docker.
- [x] **[Notifications]** NotificationController + Laravel Notification class base — *Backend · 4 jam*
- [x] **[Notifications]** Broadcast: NotificationCreated event → Echo private channel user — *Backend · 4 jam*
- [x] **[Notifications]** Notification bell realtime: subscribe Echo channel di React — *Frontend · 4 jam* _(Catatan CSV: badge counter update live)_
- [x] **[Notifications]** Notification list page: mark as read, mark all read, riwayat 90 hari — *Frontend · 4 jam* (`PruneNotificationsJob` 02:00)

### Week 10 (2025-03-10)

- [x] **[Notifications]** Trigger: task baru di-assign → Tukang bersangkutan — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: QA form dibuat → tim QA — *Backend · 2 jam* (juga saat PM mengajukan ulang setelah reject)
- [x] **[Notifications]** Trigger: QA approve/reject → PM proyek — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: overtime approved PM → Tukang + Finance — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: overtime approved Finance → Tukang — *Backend · 2 jam*
- [x] **[Notifications]** Trigger: daily form belum diisi jam 20:00 (reminder) → Tukang — *Backend · 2 jam* _(Catatan CSV: 30 menit sebelum penalti)_
  - Dijadwalkan **20:30** (Senin–Sabtu) — catatan CSV "30 menit sebelum penalti" (penalti 21:00) lebih spesifik daripada judul "20:00".
- [x] **[Analytics]** AnalyticsController: query agregasi per widget CEO — *Backend · 4 jam* (`AnalyticsService`)
- [x] **[Analytics]** CEO Dashboard layout: grid widget + data dari AnalyticsController — *Frontend · 4 jam*
