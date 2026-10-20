# Sprint 29 · 15 — Uji Alur, Kecepatan Ulang & Dokumen

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · §3 alur, §5 kecepatan & UX
> Status: **belum dikerjakan** · Bergantung: Sub 01–14

## Checklist
- [ ] **[Test]** Test E2E alur §3 (pola `ClientJourneySimulationTest`): Buka Proyek dua Pengawas → TM Selesai → upah per item (angka File A) → DP 10 % disetujui PM = Start Produksi → tukang Selesai / Pengawas centang → pengecekan % (angka File B) → pencairan → 70 % → QA per item → COMPLETED
- [ ] **[Test]** Suite Pest penuh; RBAC setiap route baru (Pengawas, QA lain, Admin Proyek, PM, Logistik, Finance, HR, Tukang)
- [ ] **[Ukur]** Ulangi pengukuran Sub 01 untuk semua form baru (upah per baris, pengecekan %, centang, ajukan bahan, aktivitas harian) — catat di Sub 01
- [ ] **[Browser]** Per role, desktop + HP 390 px: Pengawas, Admin Proyek, Tukang, Logistik, PM, Finance, HR — checklist §5c tiap halaman yang berubah
- [ ] **[Security]** `/security-review`: pembayaran upah, harga RAB ke Pengawas, role Admin Proyek, aktivitas harian
- [ ] **[Seed]** Data demo: proyek dengan dua Pengawas, item berupah, pengecekan, akun `adminproyek`
- [ ] **[Dok]** `CLAUDE.md` (aturan 17 diperbarui + aturan baru Admin Proyek & Upah Tukang per item), `security-standards.md` §4, `design-standards.md` (pola simpan cepat), `simulasi-eksekusi-proyek.md`, `simulasi-alur-klien.md` (Tahap 7), README status Sprint 29
- [ ] **[Build]** `npm run build`, Pint
