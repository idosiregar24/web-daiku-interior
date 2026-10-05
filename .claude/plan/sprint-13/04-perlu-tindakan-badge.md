# Sprint 13 · 04 — Perlu Tindakan + Badge Menu

> Induk: [`../sprint-13-navigasi-ux.md`](../sprint-13-navigasi-ux.md).
> Status: **belum dikerjakan** · Prasyarat: 01 · Keputusan: #4, #5, #10 · Default: D2, D4

## Tujuan
Setiap orang melihat "giliran saya" di satu tempat, dan angka di menu
menunjukkan menu mana yang perlu dibuka.

## File yang disentuh
- `app/Services/ActionInboxService.php` (baru)
- `app/Http/Controllers/InboxController.php` + `routes/web.php` (`inbox.index`, semua role ter-auth)
- `app/Http/Middleware/HandleInertiaRequests.php` (lazy prop `navBadges`, menggantikan/menyerap `pendingProjectOpenings` untuk hitungan)
- `app/Services/RoleRedirectService.php` (CEO → `inbox.index`)
- `resources/js/Pages/Inbox/Index.tsx`, `resources/js/Layouts/AppLayout.tsx` (badge + tombol topbar), `resources/js/Pages/Dashboard.tsx` (ringkasan)
- Test: `tests/Feature/Inbox/*`, `tests/Unit/ActionInboxServiceTest.php`

## Rancangan
- `ActionInboxService::for(User): Collection<InboxGroup>` — tiap grup:
  `key`, `label`, `count`, `routeName` (menu tujuan, untuk badge), `items`
  (maks. 5 terbaru: judul, subjudul, umur, URL detail).
- Sumber per role (scope **sama persis** dengan halaman daftarnya — pakai
  ulang scope/Policy yang ada, jangan tulis query baru yang bisa beda):

| Role | Antrean |
|---|---|
| CEO | Quotation WAITING_CEO · RAB Proyek siap Buka Proyek · `BudgetOverrunRequest` menunggu · perubahan gaji PENDING · evaluasi SUBMITTED |
| PM / Asisten PM | Quotation SUBMITTED (proyek/lead yang ia tangani) · pengajuan barang MENUNGGU_PM · lembur PENDING · QA ditolak di proyeknya |
| Estimator | Quotation DIMINTA & yang dikembalikan untuk revisi |
| Marketing | Follow-up jatuh tempo · quotation READY_TO_SEND · termin perlu invoice |
| Finance | Invoice MENUNGGU_VERIFIKASI · lembur PENDING_FINANCE · termin OVERDUE |
| Kepala Desain | Desain MENUNGGU_PENUGASAN |
| Logistik | Pengajuan barang DIAJUKAN |
| QA | Form QA PENDING |
| HR | Evaluasi & KPI yang menunggu HR |
| Tukang | Task hari ini · form harian belum diisi (dipakai Sub 09) |

- **Badge**: `navBadges: Record<routeName, number>` sebagai Inertia lazy
  prop, cache 60 detik per user (`Cache::remember("inbox:{id}")`), dibuang
  oleh service yang memproses item (mis. setelah verifikasi invoice).
  Hub menjumlahkan badge tab-tabnya; tab menampilkan angkanya sendiri.
- **Halaman Perlu Tindakan**: daftar grup (`SectionCard` per grup, item
  bisa diklik, "Lihat semua" ke halaman daftar dengan filter status).
  `EmptyState` "Semua beres" bila kosong.
- Topbar: tombol berangka di sebelah lonceng. Dashboard: ringkasan 3 grup teratas.
- CEO mendarat di Perlu Tindakan setelah login (#10).

## Checklist
- [ ] **[Backend]** `ActionInboxService` + sumber per role, memakai scope/Policy yang ada
- [ ] **[Backend]** Lazy prop `navBadges` + cache + invalidasi di service pemroses
- [ ] **[UI]** Halaman Perlu Tindakan + menu di grup Utama + tombol topbar
- [ ] **[UI]** Badge di menu, hub, dan tab; ringkasan di Dashboard
- [ ] **[Backend]** CEO mendarat di `inbox.index`
- [ ] **[Test]** Unit per sumber (jumlah = jumlah di halaman daftar untuk user yang sama), Asisten PM hanya proyeknya, role tanpa antrean = kosong; feature `inbox.index` semua role 200, tamu 302
