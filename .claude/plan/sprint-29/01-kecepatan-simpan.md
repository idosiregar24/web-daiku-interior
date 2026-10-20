# Sprint 29 · 01 — Kecepatan Simpan Detail Proyek + Form Bahan Tanpa Menunggu

> Induk: [`../sprint-29-revisi-pengawas-upah-tukang.md`](../sprint-29-revisi-pengawas-upah-tukang.md) · §5 (L1–L10, K1–K7)
> Status: **belum dikerjakan**
> Dikerjakan pertama — sub lain memakai pola simpan dari sini.

## Permintaan user
> "Untuk proses tambah barang yang akan digunakan dari Logistik, pastikan
> form-nya tidak menunggu loading agar tidak lama … lihat bagian-bagian mana
> yang apabila diisi butuh loading dan mengganggu kecepatan bekerja."

## Akar masalah
Setiap simpan di Detail Proyek → `back()` → `ProjectController::show`
menghitung ulang ±40 prop (overview Eksekusi, Activity Target, anggaran,
semua task, termin, dokumen, material, katalog material ×2 …) → "Menyimpan…"
+ pop-up sukses 3 detik.

## Rancangan
1. **Server (K1)** — prop berat di `ProjectController::show` dibungkus
   closure `fn () => …` (dihitung hanya bila diminta); `materialOptions` &
   `catalogOptions` → `Inertia::optional()` (dimuat sekali saat form bahan
   pertama dibuka, lalu disimpan di state komponen).
2. **Reload sebagian (K2)** — helper `resources/js/lib/projectReload.ts`:
   `projectVisit(only: ProjectProp[])` → opsi visit `{ only: [...only, 'flash',
   'navBadges'], preserveState: true, preserveScroll: true }`. Peta "aksi →
   prop yang berubah" ditulis sekali di helper (mis. bahan → `projectMaterials`
   + `execution`; task → `tasks` + `execution` + `activityTarget`).
   Cek: redirect `back()` setelah POST tetap menghormati `only` (header
   partial Inertia v2) — bila tidak, controller membalas `Inertia::location`
   / 303 ke URL yang sama dengan header partial.
3. **Form tambah bahan (L1)** — `ProjectMaterialsPanel` dialog "Tambah
   kebutuhan":
   - mode **beberapa baris**: cari katalog → jumlah → Enter menambah baris ke
     daftar di dialog (belum dikirim), ubah/hapus baris, lalu **"Simpan
     semua"** = satu request (`projects.materials.storeMany` — baru, satu
     transaksi lewat `ProjectMaterialService::plan()` per baris);
   - atau **"Simpan & tambah lagi"** untuk satu baris: dialog tetap terbuka,
     kolom dikosongkan, fokus kembali ke pencarian;
   - baris yang dikirim tampil langsung di tabel dengan tanda "menyimpan…"
     (optimistic, K3), hilang + pesan bila ditolak;
   - tanpa pop-up sukses (`quietSuccess`), tanda kecil "n bahan tersimpan ✓".
4. **Rencana bahan TM (L2)** — `TmMaterialPlanner`: baris baru tampil langsung
   (optimistic), simpan dengan `projectVisit(['projectMaterials', 'execution'])`.
5. **Centang tukang (L3)** — Hari Ini, Tugas, `TaskActionSheet`: status kartu
   berubah langsung, tombol terkunci sampai selesai, reload hanya `tasks`
   (+ `missingToday`), batal + pesan bila gagal.
6. **Daily target (L4)** — `TaskFormDialog`: "Simpan & buat lagi" (tukang &
   timeline tetap, tanggal maju ke hari kerja berikutnya), reload sebagian.
   (Input beberapa hari sekaligus → Sub 04.)
7. **Geser kartu (L5)** — `TaskWeekBoard`: kartu pindah langsung, kembali
   bila ditolak.
8. **Lainnya di Detail Proyek (L6–L8)** — kartu TM, `TaskStatusDialog`,
   laporan mingguan, temuan: semua memakai `projectVisit([...])`.
9. **Logistik (L9)** — pengajuan barang & stok masuk/keluar: `quietSuccess`
   untuk aksi beruntun + reload sebagian daftar (`only`), pop-up hanya untuk
   keputusan Setujui/Tolak.
10. **Pop-up sukses (L10)** — aturan: isian berulang = `quietSuccess`;
    keputusan/transisi = `SuccessDialog`. Ditulis di `design-standards.md` §2.

## Checklist
- [ ] **[Ukur]** Telescope: durasi POST tambah bahan, centang task, simpan TM, buat target di proyek demo terbesar — catat angka "sebelum" di sini
- [ ] **[Backend]** Closure untuk prop berat `ProjectController::show`; `Inertia::optional()` katalog; route `projects.materials.storeMany` + Form Request (array baris, aturan sama dengan `store`) + `ProjectMaterialService` satu transaksi
- [ ] **[Backend]** Pastikan redirect setelah simpan menghormati `only`
- [ ] **[UI]** `lib/projectReload.ts` (peta aksi → prop); pakai di semua simpan Detail Proyek
- [ ] **[UI]** `ProjectMaterialsPanel`: multi-baris + "Simpan semua", "Simpan & tambah lagi", optimistic, katalog dimuat sekali
- [ ] **[UI]** `TmMaterialPlanner`, `TaskWeekBoard`, Hari Ini / Tugas / `TaskActionSheet`: optimistic + reload sebagian
- [ ] **[UI]** `TaskFormDialog` "Simpan & buat lagi"
- [ ] **[UI]** Logistik: `quietSuccess` + `only` di aksi beruntun
- [ ] **[UX]** Tombol terkunci + spinner saat menyimpan; Enter = simpan; fokus kembali; HP 390 px (Logistik & Pengawas)
- [ ] **[Test]** Pest: `storeMany` (RBAC sama dengan `store`, satu baris gagal → semua batal); partial reload `only` mengembalikan prop yang diminta saja
- [ ] **[Ukur]** Angka "sesudah" — target < 300 ms, overlay tidak muncul
- [ ] **[Dok]** `design-standards.md` §2 (pola simpan cepat, kapan pop-up), `frontend-standards.md` §7 (`projectVisit`)
- [ ] **[Build]** `npm run build`, Pint, suite Pest
