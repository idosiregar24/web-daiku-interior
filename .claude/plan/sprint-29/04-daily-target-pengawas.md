# Sprint 29 · 04 — Daily Target: Approval & Centang Pengawas

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · Revisi "Pengecekan daily target = approval Pengawas", "QC juga bisa isi status task" · Keputusan F1–F6 · Menggantikan Sprint 28 K2
> Status: **belum dikerjakan** · Bergantung: Sub 01

## Keputusan user
> "Karena tukang dan kepala tukang agak gaptek, buat saja Pengawas bisa
> ceklis task yang sudah di tiap daily target-nya, dan apabila tukang bisa
> gunakan HP tukang juga bisa klik selesai." · "Pengecekan harian Pengawas
> sifatnya approval dari hasil input tukang, tapi bisa juga Pengawas yang
> menandai pekerjaan tukang selesai."

## Rancangan
1. **Tukang** (Hari Ini, Tugas, `TaskActionSheet`): "✔ Selesai" →
   `PENGECEKAN`, label **"Menunggu dicek Pengawas"**; laporan hari itu tetap
   tercatat (`TaskService::recordActivity`).
2. **Pengawas / Admin Proyek / PM**:
   - **Setujui** → `DONE`, `completed_at` = waktu tukang menandai.
   - **Kembalikan** → catatan wajib → `ONPROGRESS` + notifikasi tukang
     ("Target dikembalikan: {catatan}") + riwayat.
   - **Centang langsung** target yang belum disentuh → `DONE`; dicatat
     sebagai laporan tukang hari itu dengan pencatat = Pengawas → tidak kena
     penalti (Q30). Audit `task.checked_by_supervisor`.
3. **Tampilan centang cepat** — Daily Target › Per Hari / Per Tukang: kotak
   centang besar per target; chip "Menunggu dicek"; tombol **"Setujui semua
   (n)"** per tukang per hari; "Kembalikan" di menu baris. Semua optimistic
   (Sub 01 K3).
4. **Buat target beberapa hari sekaligus** (K5): `TaskFormDialog` — pilih
   tukang (bisa >1) + rentang hari kerja → satu request membuat satu target
   per tukang per hari (`tasks.storeMany`, aturan `ensureWithinTimeline` per
   baris).
5. **Antrean** `ActionInboxService`: "Daily target menunggu dicek · n target ·
   Proyek X" untuk Pengawas proyek (pengganti Sprint 28 K2 ke PM);
   `waitingOn` task `PENGECEKAN` → "Pengawas (QA/QC)".
6. **Menu Tugas** (`tasks.index`): + `QA` (proyek yang diawasinya — scope
   `supervisedBy`) dan `ADMIN_PROYEK` (Sub 07), saringan status
   `?status=PENGECEKAN`.

## Checklist
- [ ] **[Backend]** `TaskService`: `approve()`, `returnToStaff()`, `checkOffBySupervisor()` (catat aktivitas + audit); route + `TaskPolicy` (Pengawas proyek, PM, Admin Proyek); tukang tidak bisa langsung `DONE` di proyek fase baru
- [ ] **[Backend]** `tasks.storeMany` (beberapa tukang × hari)
- [ ] **[Backend]** Penalti: centang Pengawas dihitung laporan (`DailyPenaltyJob` / `awaitingDailyForm`)
- [ ] **[Backend]** Antrean + `waitingOn`; `tasks.index` untuk QA & Admin Proyek
- [ ] **[UI]** Kartu tukang (label baru), papan Per Hari / Per Tukang (centang, Setujui semua, Kembalikan), `TaskFormDialog` beberapa hari/tukang
- [ ] **[UX]** Optimistic + `projectVisit(['tasks','execution','activityTarget'])`; HP 390 px untuk Pengawas (kotak ≥ 48 px); ConfirmDialog untuk "Setujui semua"
- [ ] **[Test]** Pest: tukang → PENGECEKAN; Setujui/Kembalikan/centang (RBAC: Pengawas proyek ya, QA lain 403, tukang 403); penalti tidak jatuh bila Pengawas mencentang; antrean muncul/hilang; storeMany
- [ ] **[Build]** `npm run build`, Pint
