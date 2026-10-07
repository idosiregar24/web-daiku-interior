# Sprint 16 · 03 — Proyek, Tugas, Lapangan

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K2, K4–K9
> Status: **selesai 2026-10-07** · Butuh Sub 01

Cara kerja sama dengan Sub 02. Form di sub ini banyak dipakai dari HP
(`ResponsiveDialogContent`, `TaskActionSheet`), jadi cek bintangnya di
lebar 390px.

## Checklist
### Proyek
- [x] `Components/modules/projects/ProjectFormDialog.tsx`
- [x] `Components/modules/projects/OpenProjectDialog.tsx` (Buka Proyek, CEO)
- [x] `Components/modules/projects/MilestoneFormDialog.tsx`
- [x] `Components/modules/projects/ProgressLogFormDialog.tsx`
- [x] `Components/modules/projects/RequestAddendumDialog.tsx`
- [x] `Components/modules/projects/BudgetPostDialog.tsx`
- [x] `Components/modules/projects/RealizationDialog.tsx`
- [x] `Components/modules/projects/OverrunDecisionDialog.tsx`: alasan
      tolak, bintang dinamis
- [x] `Components/modules/projects/ProjectMaterialActionDialog.tsx` (13
      label, aturan berbeda per aksi, jadi bintang mengikuti aksi aktif)
- [x] `Components/modules/projects/ProjectMaterialsPanel.tsx`

### Tugas
- [x] `Components/modules/projects/TaskFormDialog.tsx`
- [x] `Components/modules/projects/TaskStatusDialog.tsx`: `kendala` wajib
      bila status tertentu, jadi bintang dinamis
- [x] `Components/modules/tasks/TaskActionSheet.tsx` (versi HP tukang)

### Lapangan
- [x] `Pages/DailyForm/Index.tsx`
- [x] `Pages/Overtime/Index.tsx`
- [x] `Pages/QA/Show.tsx`: "(wajib jika reject)" diganti bintang dinamis
      saat keputusan REJECT (PRD §4.6)

### Selesai
- [x] `npm run build` lulus · cek di browser: TaskActionSheet (390px), QA
      reject, ProjectMaterialActionDialog

## Catatan pelaksanaan (2026-10-07)
- Bintang dinamis: Alasan Pembatalan proyek (`required_if:status,CANCELLED`),
  catatan tolak overrun (`required_if:decision,reject`), Prioritas task
  (Store `nullable`, Update `required` → `required={editing !== null}`),
  catatan aksi material (`waste`/`handOver` wajib, lainnya opsional), field
  "barang katalog baru" (`required_with:new_material`, tampil hanya saat dipilih).
- QA: keputusan dipilih lewat tombol (tidak ada state sebelum klik), jadi
  "(wajib jika reject)" diganti `FormDescription` "Wajib diisi bila keputusan
  Reject." — bukan bintang.
- Tukang (`TaskActionSheet`): "Kendala (kalau ada)" / "Catatan (kalau ada)"
  **dipertahankan** — bahasa membumi Sprint 13 untuk tukang, bukan label
  "(opsional)". Hanya Status diberi bintang.
- **Zod ≠ server (K9, dibiarkan — perlu keputusan user):** catatan tolak
  Lembur — Zod mewajibkan saat Tolak, `OvertimeDecisionRequest` `nullable`.
  Bintang mengikuti form (wajib saat Tolak). Usul: server
  `required_if:decision,reject` supaya sama.
