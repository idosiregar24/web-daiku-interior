# Sprint 13 · 05 — Detail Proyek sebagai Pusat

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **selesai 2026-10-05** · Prasyarat: — · Keputusan: #3

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
- [x] **[Projects]** Tab QA & Lembur (+ Pengajuan Barang bila belum ada), props per role
- [x] **[UI]** Tab dibaca/ditulis lewat `?tab=`
- [x] **[UI]** Tautan proyek → tab yang tepat di 6 daftar lintas proyek; ringkasan "menunggu" di Overview
- [x] **[Test]** Props tab baru: ada untuk role berhak, absen untuk QA (detail task) & Marketing (finance); `npm run build`

## Catatan pelaksanaan (2026-10-05)
- Tab baru **QA** (CEO/PM/Asisten PM/QA — status, catatan QA, reviewer;
  tanpa data task) dan **Lembur** (CEO/PM/Asisten PM/Finance, baca saja —
  keputusan tetap di halaman Lembur). Nama milestone menaut ke form QA
  hanya untuk role yang boleh membuka `qa-forms.show` (bukan Asisten PM).
- **Pengajuan Barang sudah ada** di tab Material (`ProjectMaterialsPanel`,
  Sprint 11 Sub 4) — tidak dibuat tab baru.
- `?tab=` lewat hook `useQueryTab()` (`resources/js/hooks/`): ganti tab
  memakai `router.replace({ url })` — kunjungan sisi-klien Inertia v2,
  **bukan** `router.get` seperti rancangan, supaya tidak memuat ulang
  semua props proyek dari server setiap ganti tab.
- Komponen `ProjectLink` (`Components/modules/projects/`) dipakai di 6
  daftar: QA → tab QA (nama milestone kini yang membuka form QA), Lembur →
  Lembur, Pengajuan Barang → Material, Termin → Finance, Invoice → Dokumen
  (baris di bawah nama klien, hanya invoice yang punya proyek), Task → Task.
- Overview: kartu "Menunggu di Proyek Ini" (QA ditolak/menunggu, lembur
  menunggu, pengajuan barang menunggu, termin berikutnya) dihitung dari
  props yang sudah diterima role itu — tidak ada query baru; klik = buka tab.
- Antrean "QA ditolak" di Perlu Tindakan kini menaut ke tab QA.
