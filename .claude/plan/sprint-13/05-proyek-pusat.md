# Sprint 13 · 05 — Detail Proyek sebagai Pusat

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: — · Keputusan: #3

## Tujuan
Semua urusan satu proyek bisa dijangkau dari Detail Proyek, dan setiap
daftar lintas proyek menautkan ke tab proyek yang tepat.

## File yang disentuh
- `app/Http/Controllers/Projects/ProjectController.php` (`show` — props baru, per role)
- `resources/js/Pages/Projects/Show.tsx` (+ komponen tab di `Components/modules/projects/`)
- Halaman daftar: `QA/Index`, `Overtime/Index`, `Logistics/MaterialRequests/Index`, `Finance/Termins/Index`, `Finance/Invoices/Index`, `Tasks/Index`
- Test: `tests/Feature/Projects/ProjectShowTest.php` (atau yang setara)

## Rancangan
- Tab sekarang: Overview, Milestone, Task, Progress, Finance, Alokasi Dana,
  Dokumen, Material. Tambah:
  - **QA** — form QA per milestone (status + tautan ke detail). Role QA
    tetap tidak melihat detail task (security-standards §2).
  - **Lembur** — pengajuan lembur di proyek ini (CEO/PM/Asisten PM/Finance).
  - **Pengajuan Barang** — ditampilkan di tab Material jika belum ada.
  Props hanya dikirim ke role yang boleh (filter di controller).
- **Tab via URL**: `?tab=qa` dibaca saat halaman dibuka, dan tab yang
  dipilih memperbarui query (`router.get(..., { preserveState: true, replace: true })`),
  supaya tautan dari luar bisa menunjuk tab tertentu.
- Di daftar lintas proyek, nama proyek pada tiap baris menaut ke
  `projects.show` + `?tab=` yang relevan.
- Overview menjawab "apa yang menunggu di proyek ini" (ringkas: QA tertunda,
  lembur/pengajuan menunggu, termin berikutnya).

## Checklist
- [ ] **[Projects]** Tab QA & Lembur (+ Pengajuan Barang bila belum ada), props per role
- [ ] **[UI]** Tab dibaca/ditulis lewat `?tab=`
- [ ] **[UI]** Tautan proyek → tab yang tepat di 6 daftar lintas proyek; ringkasan "menunggu" di Overview
- [ ] **[Test]** Props tab baru: ada untuk role berhak, absen untuk QA (detail task) & Marketing (finance); `npm run build`
