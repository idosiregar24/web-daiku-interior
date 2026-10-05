# Sprint 13 — Navigasi & UX: sidebar ringkas, Perlu Tindakan, versi HP

> Status: **selesai 2026-10-05** — kecuali uji di HP sungguhan, Lighthouse, dan `/security-review` penuh (lihat Sub 10–12, "Sebagian"). Keputusan dikunci 2026-10-05 lewat
> diskusi UX dengan user (12 ide navigasi desktop disetujui; #8 "sidebar dua
> tingkat" kalah oleh #7 "grup dilipat"; versi HP untuk tukang + PM).
> Beberapa default kecil masih menunggu konfirmasi — lihat §5. Rancangan
> detail & checklist per sesi kerja: §6.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`.
> Sprint ini **tidak mengubah alur bisnis, RBAC, maupun route yang ada** —
> hanya cara orang sampai ke halaman. Setiap halaman tetap punya route &
> gate `role:` sendiri; hub bertab hanya membungkus route yang sudah ada.

---

## 1. Masalah

Sidebar (`NAV_GROUPS` di `Layouts/AppLayout.tsx`) disusun per divisi dan
setiap halaman = satu menu. Hasilnya per role:

| Role | Menu sekarang | Target |
|---|---|---|
| CEO | ±39 | ±25, dan hanya grup aktif yang terbuka |
| PM | ±20 | ±15 |
| Finance | ±17 | ±9 |
| Marketing | 6 | 6 |
| Tukang (HP) | 6–7 lewat hamburger | 4 tombol navigasi bawah |

**Hasil (Sub 12, dihitung dari `NAV_GROUPS` HEAD vs sesudah Sprint 13 —
menu sidebar yang terlihat; tab hub tidak dihitung):**

| Role | Sebelum | Sesudah | Catatan |
|---|---|---|---|
| CEO | 39 | 26 | 4 hub Keuangan, Kinerja SDM, ⚙ Pengaturan (6 tab) |
| PM | 21 | 17 | |
| Asisten PM | 4 | 5 | + Perlu Tindakan |
| Finance | 19 | 13 | Pengaturan = "Alokasi Persentase" |
| Marketing | 7 | 8 | + Perlu Tindakan; Keuangan = "Invoice" |
| Arsitek | 6 | 7 | + Perlu Tindakan |
| Estimator | 8 | 9 | + Perlu Tindakan |
| QA | 5 | 6 | + Perlu Tindakan |
| Logistik | 8 | 8 | |
| SDM | 9 | 8 | Pengaturan [Divisi & Jabatan | Template KPI] |
| Tukang | 7 | 9 (HP: 4 tombol bawah) | + Hari Ini, Perlu Tindakan; "Tugas" |
| SUPERADMIN | 39 | 26 | |

Penyebab terbesar: grup **Operasional (13 menu)** dan **SDM (7 menu)**, serta
menu "setup" yang jarang dibuka bercampur dengan menu harian. Selain itu
orang harus berkeliling menu hanya untuk mengecek apakah ada pekerjaan
yang menunggu dia.

Di HP, tukang mendapat sidebar desktop yang sama di dalam `Sheet`,
daftar Task berupa `DataTable` (geser ke samping), dan Form Harian berupa
dialog di tengah layar.

## 2. Keputusan (dikunci 2026-10-05)

### Desktop — 12 ide disetujui
| # | Ide | Keputusan |
|---|---|---|
| 1 | **Hub bertab** | Menu serumpun digabung jadi satu menu; halamannya tetap route sendiri, tampil sebagai tab (`UnderlineTabsList`). Tab disaring per role; kalau role hanya punya akses ke **1 tab**, menu memakai nama tab itu dan bar tab disembunyikan. |
| 2 | **Pisah harian vs setup** | Satu menu **⚙ Pengaturan** di bawah sidebar, berisi hub bertab semua halaman setup (lihat §2.1). |
| 3 | **Proyek sebagai pusat** | Detail Proyek sudah punya tab Task, Finance/Termin, Alokasi Dana, Dokumen, Material. Lengkapi yang belum (QA, Lembur, Pengajuan Barang) + tautan dari daftar lintas proyek ke tab proyek yang tepat. Sidebar tetap untuk daftar **lintas proyek**. |
| 4 | **Perlu Tindakan** | Antrean "giliran saya" per role, dari satu service. Tampil sebagai halaman sendiri + tombol berangka di topbar + ringkasan di Dashboard. |
| 5 | **Badge angka di menu** | Dari service yang sama dengan #4 — angka di menu = jumlah di Perlu Tindakan. |
| 6 | **Tombol "+ Buat" global** | Dropdown di topbar berisi aksi tambah yang boleh role itu (Lead, Transaksi, Pengajuan Barang, Lembur, …). |
| 7 | **Grup dilipat** | Header grup bisa dibuka-tutup; grup halaman aktif selalu terbuka; pilihan diingat per user. **Dipakai di desktop dan HP.** |
| 8 | ~~Sidebar dua tingkat (rail)~~ | **Tidak dipakai** — user memilih #7 saja (perubahan terkecil, tanpa belajar ulang). |
| 9 | **Navigasi bawah tukang** | Lihat bagian HP. |
| 10 | **Per role** | **Satu struktur menu** (mudah dirawat), tapi **urutan grup** dan **halaman pertama setelah login** disesuaikan per role. CEO mulai dari Perlu Tindakan. |
| 11 | ~~Favorit~~ + terakhir dibuka | **Favorit tidak dibuat** (dijawab user 2026-10-05: "tidak usah"). Yang tersisa hanya daftar "Terakhir dibuka" di command menu — digabung ke Sub 06. |
| 12 | **Pencarian menonjol** | `CommandMenu` jadi kolom cari yang terlihat ("Cari menu, proyek, klien…") dan ikut mencari data: proyek, lead, quotation (dibatasi Policy). |

### 2.1 Struktur menu baru (tampilan CEO — role lain melihat subsetnya)
```
Utama         Dashboard · Perlu Tindakan (n) · Kinerja Saya
Presales      CRM · Desain · Quotation
Eksekusi      Proyek · Task · Form Harian · Lembur · QA
Keuangan      Cash Flow        [Ringkasan | Transaksi]
              Penagihan        [Termin | Invoice | Verifikasi Pembayaran]
              Pembayaran Staf  [Upah Tukang | Penggajian | Pinjaman Tukang | Penalti]
              Kewajiban        [Hutang Supplier | Cicilan Aset]
              Dana Family Gathering
Logistik      Material         [Katalog | Riwayat Stok]
              Pengajuan Barang · Aset Inventaris
SDM           Dashboard SDM · Karyawan · Gaji
              Kinerja          [KPI | Evaluasi | Kedisiplinan]
Eksekutif     Analytics · Audit Trail
─────────────
⚙ Pengaturan  [Pengguna | Data Master | Vendor | Alokasi Persentase |
               Divisi & Jabatan | Template KPI | Situs]
```
Contoh penyaringan: tukang melihat menu **"Penalti"** (bukan "Pembayaran
Staf"); Finance melihat ⚙ Pengaturan berisi tab Alokasi Persentase saja,
jadi menunya bernama "Alokasi Persentase"; HR melihat Pengaturan [Divisi &
Jabatan | Template KPI].

### HP — tukang (FIELD_STAFF)
| # | Keputusan |
|---|---|
| H1 | **Navigasi bawah 4 tombol** di bawah `lg`: Hari Ini · Tugas · Lembur · Lainnya. Tanpa sidebar/hamburger untuk tukang. "Lainnya" = Penalti (+ total bulan ini), Pengajuan Barang, Proyek, Profil, Keluar. |
| H2 | **Layar "Hari Ini"** jadi halaman pertama tukang: kartu task hari ini, status form harian per task, peringatan "n form belum diisi · batas sebelum penalti 21:00". |
| H3 | **Task + Form Harian = satu alur**: ketuk kartu → panel bawah dengan status sebagai tombol besar, kendala/catatan, tombol simpan besar. Endpoint tetap yang sekarang. |
| H4 | **Kartu, bukan tabel**; **panel bawah, bukan dialog tengah**. |
| H5 | **Tanpa foto** (dijawab user: tidak perlu). |
| H6 | **Tanpa mode offline/draf** (dijawab user: sinyal di lokasi aman). |
| H7 | **PWA**: bisa dipasang di layar utama, layar penuh, ikon Daiku. Tanpa offline cache. |
| H8 | **Ringan untuk Android kelas bawah–menengah** (dijawab user): halaman tukang tanpa Recharts/TanStack, tanpa animasi berat, chunk per halaman dicek di output `vite build`. |
| H9 | Bahasa membumi di layar tukang: "Tugas", bukan "Task". |
| H10 | Transparansi penalti: total bulan ini tampil terang di "Lainnya" (dikonfirmasi user: boleh dilihat). |
| H11 | **Hari Minggu** (tidak ada penalti): layar Hari Ini menampilkan **daftar tugas minggu depan**, tanpa peringatan form harian (dijawab user). |

### HP / tablet — PM, Asisten PM, QA, Logistik
| # | Keputusan |
|---|---|
| P1 | PM/Asisten PM membuka aplikasi dari **HP atau tablet**; **QA dan Logistik berganti-ganti** antara HP dan laptop (dijawab user). Semuanya tetap memakai sidebar (hamburger + grup dilipat), bukan navigasi bawah — halaman yang sama harus nyaman di kedua ukuran. |
| P2 | Halaman yang dipakai di lapangan/gudang wajib nyaman di layar kecil: Perlu Tindakan, ACC Lembur, ACC Pengajuan Barang, Detail Proyek (Overview/Progress/Task), daftar & Form QA, tinjauan Pengajuan Barang (Logistik), Material & Riwayat Stok. |
| P3 | `DataTable` mendapat **mode kartu** di layar kecil (opt-in per halaman), dipakai halaman-halaman P2. |

## 3. Kondisi sekarang (dari kode)

| Area | Sekarang | Yang berubah |
|---|---|---|
| Sidebar | `NAV_GROUPS` datar, `useNavGroups()` menyaring per role; dipakai sidebar, `CommandMenu`, dan "Modul Anda" di Dashboard | + `tabs` per item (hub), grup dilipat, urutan per role, favorit, badge |
| Tab halaman | `UnderlineTabsList` dipakai Detail Proyek, Data Master, Payroll, Gaji, Karyawan, Kinerja Saya; Termin memakai `TabsList` kecil | Hub memakai `UnderlineTabsList`; tab internal halaman di dalam hub jadi `TabsList` kecil |
| Halaman awal | `RoleRedirectService` (CEO → `analytics.index`, FIELD_STAFF → `tasks.index`, …) | CEO → Perlu Tindakan, FIELD_STAFF → Hari Ini |
| Antrean | Tersebar: `pendingProjectOpenings` (shared prop CEO), notifikasi, filter status per halaman | Satu `ActionInboxService` |
| Status yang jadi sumber antrean | `QuotationStatus` (DIMINTA, SUBMITTED, WAITING_CEO, READY_TO_SEND), `InvoiceStatus::MenungguVerifikasi`, `OvertimeStatus` (PENDING, PENDING_FINANCE), `MaterialRequestStatus` (MENUNGGU_PM, DIAJUKAN), `SalaryChangeStatus::Pending`, `DesignStatus::MenungguPenugasan`, `QaStatus::Pending`, `ReviewStatus::Submitted`, `BudgetOverrunRequest`, `TerminStatus::Overdue`, follow-up lead jatuh tempo | — (dibaca, tidak diubah) |
| HP | `Sheet` kiri berisi sidebar penuh; `DataTable`; `Dialog` tengah | Navigasi bawah tukang, kartu, panel bawah |
| Penalti form harian | `DailyPenaltyJob` Senin–Sabtu 21:00, pengingat 20:30 | Ditampilkan di Hari Ini (dibaca dari satu konstanta/config, bukan ditulis ulang) |
| Login | `remember` default `false` | Lihat D6 |

## 4. Di luar cakupan
- Mengubah route name, gate `role:`, Policy, atau alur bisnis.
- Notifikasi real-time badge (lokal `BROADCAST_CONNECTION=log`) — badge
  diperbarui tiap kunjungan halaman.
- Mode offline, unggah foto progres (H5, H6).

## 5. Default yang menunggu konfirmasi

| # | Pertanyaan | Default yang dipakai |
|---|---|---|
| D1 | Nama hub Keuangan | Cash Flow · Penagihan · Pembayaran Staf · Kewajiban |
| D2 | Letak Perlu Tindakan | Menu di grup Utama (dengan angka) + tombol berangka di topbar + ringkasan di Dashboard; tidak menggantikan lonceng notifikasi (notifikasi = kejadian, Perlu Tindakan = antrean saat ini). **Dijawab 2026-10-05: CEO mendarat di Perlu Tindakan setelah login (yang harus disetujui dulu, bukan angka).** |
| D3 | Penyimpanan preferensi | Kolom JSON `users.nav_preferences` (grup terlipat) — ikut ke HP/tablet; "Terakhir dibuka" di `localStorage` per perangkat |
| D4 | Kesegaran badge | Dihitung saat kunjungan halaman (Inertia lazy prop), cache 60 detik per user, dibuang saat user sendiri memproses item |
| D5 | Cakupan pencarian | Menu + Proyek + Lead + Quotation (+ Karyawan untuk CEO/HR), maks. 5 per jenis, disaring Policy yang sama dengan halaman daftarnya |
| D6 | Login di HP | "Ingat saya" tercentang secara default (bisa dihapus centangnya) supaya tukang tidak login ulang tiap hari. **Dijawab 2026-10-05: centang otomatis.** |
| D7 | Panel bawah | `Sheet side="bottom"` yang sudah ada — tidak menambah dependency `vaul` (H8) |
| D8 | Batas navigasi bawah | Di bawah `lg` (1024px) untuk FIELD_STAFF — tablet ikut |

---

## 6. Sub-plan (satu file = satu sesi kerja)

Detail rancangan, file yang disentuh, dan checklist ada di folder
[`sprint-13/`](sprint-13/). Saat mengerjakan, cukup baca **file induk ini
(§1–§5) + satu sub-plan**.

| # | Sub-plan | Isi | Prasyarat | Task |
|---|---|---|---|---|
| 01 | [Sidebar: grup dilipat & urutan per role](sprint-13/01-sidebar-lipat-urutan.md) | #7, #10, `nav_preferences` | — | 5 |
| 02 | [Hub bertab: fondasi + Keuangan](sprint-13/02-hub-keuangan.md) | #1 — `tabs` di NavItem, `ModuleTabs`, 4 hub Keuangan | 01 | 5 |
| 03 | [Hub lanjutan + ⚙ Pengaturan](sprint-13/03-hub-logistik-sdm-pengaturan.md) | #1, #2 — Logistik, SDM, Pengaturan | 02 | 4 |
| 04 | [Perlu Tindakan + badge](sprint-13/04-perlu-tindakan-badge.md) | #4, #5 — `ActionInboxService` | 01 | 6 |
| 05 | [Detail Proyek sebagai pusat](sprint-13/05-proyek-pusat.md) | #3 — tab QA/Lembur/Pengajuan, tautan ke tab | — | 4 |
| 06 | [Tombol "+ Buat", pencarian & terakhir dibuka](sprint-13/06-buat-pencarian.md) | #6, #11 (sisa), #12 | 03 | 6 |
| ~~07~~ | ~~[Favorit](sprint-13/07-favorit-terakhir.md)~~ | **Dibatalkan** — favorit tidak dibuat; "Terakhir dibuka" pindah ke 06 | — | 0 |
| 08 | [HP tukang: navigasi bawah & Lainnya](sprint-13/08-hp-tukang-navigasi.md) | H1, H9, H10 | 03 | 4 |
| 09 | [HP tukang: Hari Ini, Tugas + Form Harian](sprint-13/09-hp-tukang-hari-ini.md) | H2, H3, H4 | 08 | 5 |
| 10 | [PWA & ringan](sprint-13/10-pwa-ringan.md) | H7, H8, D6 | 09 | 4 |
| 11 | [HP/tablet PM, Asisten PM, QA, Logistik](sprint-13/11-hp-tablet-pm.md) | P1–P3, `DataTable` mode kartu | 04, 05 | 5 |
| 12 | [Penutup](sprint-13/12-penutup.md) | Docs, seeder, test menyeluruh, uji di HP | 01–11 | 4 |

Kerjakan berurutan **Sub 01 → Sub 12**, **lewati Sub 07** (dibatalkan).
Sub 05 tidak bergantung pada yang lain dan boleh dikerjakan kapan saja.
