# Panduan Cek Revisi Sprint 29 — dari Revisi User ke Layar

Tiap baris revisi Anda (2026-10-20) → apa yang saya pahami → apa yang dibuat
→ **cara mengeceknya** dengan data demo. Satu baris = satu hal yang bisa
dicentang.

## Persiapan (sekali)

1. Data demo di database terpisah (data dev tidak tersentuh):
   ```
   set DB_DATABASE=daiku_interior_sim&& php artisan migrate:fresh --seed
   set DB_DATABASE=daiku_interior_sim&& php artisan serve --port=8011
   ```
   Buka **http://localhost:8011**. Semua password `password`.
2. Akun: `qa` (Pengawas utama) · `qa2` (Pengawas kedua) · `pm` · `adminproyek`
   · `fieldstaff` (Tukang) · `logistics` · `finance` · `hr` · `ceo`.
3. Proyek demo yang dipakai (semua ber-Pengawas `qa`):

   | Proyek | Tahap | Dipakai untuk |
   |---|---|---|
   | **Bambang Sutrisno** | Technical Meeting | upah disusun bersama bahan, bahan custom → Logistik, TM Selesai |
   | **Siska Amelia** | Persiapan | DP upah 10 % menunggu PM |
   | **Hendra Gunawan** | Produksi | 2 Pengawas, 2 Kepala Tim, 2 timeline, pengecekan %, pencairan, daily target approval |
   | **Dewi Lestari** | Pemasangan | QA per item RAB, temuan |

---

## Revisi → yang dibuat → cara cek

| # | Revisi Anda | Yang saya pahami & buat | Cara cek (login → klik → yang harus terlihat) |
|---|---|---|---|
| 1 | Untuk pengawas bisa lebih dari 1 | Proyek bisa punya beberapa Pengawas, **semuanya setara** (ronde 3). | `pm` → Proyek **Hendra Gunawan** → **Ubah Proyek** → kolom Pengawas berisi QA Daiku Interior **dan** Pengawas Kedua. Login `qa2` → Hendra → bisa Setujui target & isi pengecekan seperti `qa`. |
| 2 | Jasa Pengawas → Upah tukang | **Hanya nama yang diganti** (ronde 4); alurnya tetap. | Di mana pun dulu tertulis "Jasa Pengawas" sekarang **"Upah Tukang"**: tab Eksekusi, kartu Syarat DP, menu `finance` → **Keuangan → Upah Tukang**. |
| 3 | Langkah 3 dan 4 diselesai TM | TM bisa **TM Selesai** tanpa menunggu bagian Pengawas (bahan & upah pindah ke tahap Persiapan, diisi Pengawas). | `pm` → **Bambang Sutrisno** → tab **Eksekusi** → kartu Technical Meeting: syarat hanya tanggal & notulen, peserta, timeline, tanggal selesai (bukan bahan/upah). Isi tanggal + notulen + peserta → **Simpan TM** → **TM Selesai** di header. |
| 4 | Stok barang tidak usah ditampilkan di pengawas | Pengawas tidak melihat stok gudang / harga beli; sumber (gudang/beli) ditentukan Logistik. | `qa` → Bambang → kartu TM → **Bahan yang Digunakan**: tidak ada kolom stok. Bandingkan `logistics` → **Material → Pemesanan Bahan**: Logistik melihat stok. |
| 5 | Penukaran task per timeline bersifat bebas | Target bisa digeser / ditukar ke hari mana pun, **tidak dibatasi timeline-nya**; target pindah ke timeline tanggal barunya. Minggu tetap ditolak. | `qa` → **Hendra** (2 timeline: Produksi Furnitur, Pemasangan Furnitur) → tab **Daily Target → Per Hari** → seret kartu ke hari lain, atau menu kartu **Geser Tanggal** → pilih tanggal di rentang "Pemasangan Furnitur" (±12 hari lagi). Tersimpan tanpa ditolak; timeline target ikut pindah. Hari Minggu tetap ditolak. |
| 6 | Pemberian upah disusun pada saat penyusunan bahan | Kartu **Upah Tukang** tampil di tahap TM, berdampingan dengan bahan, diisi Pengawas (boleh kapan saja sampai Start Produksi). | `qa` → **Bambang** → tab **Item RAB** → **Upah Tukang** — separuh item sudah berisi Satuan Upah, separuh kosong. Isi satu → subtotal & total langsung berubah. |
| 7 | QA Form harus berdasarkan per item yang dilihat dari RAB | QA bukan lagi per timeline: tiap **item RAB** yang 100 % diperiksa Pengawas → **Lolos** / **Perlu perbaikan** (jadi temuan → task perbaikan). Proyek selesai bila semua item lolos. | `qa` → **Perlu Tindakan → Item menunggu QA** → Dewi Lestari → tab **Item RAB → QC Item**: 1 Lolos, 1 Perlu Perbaikan (tombol **Jadikan Task Perbaikan**), 1 Menunggu QA (tombol **Periksa** → Lolos/Perlu perbaikan + foto dari kamera HP). |
| 8 | Pengecekan daily target bersifat approval saja untuk pengawas → task bisa dikembalikan ke tukang | Tukang "Selesai" → **Minta dicek** → Pengawas **Setujui** atau **Kembalikan** (catatan wajib, tukang diberi tahu). | `qa` → **Perlu Tindakan → Daily target menunggu dicek** → Hendra → **Daily Target → Per Hari**: 2 kartu "Minta dicek" (Setujui / Kembalikan / **Setujui semua**). Kartu "Lapis HPL meja" sudah **dikembalikan** dengan catatan. `fieldstaff` → **Hari Ini** → melihat catatan pengembalian itu. |
| 9 | Form pengecekan mingguan boleh diisi sebelum jumat | Pengecekan bisa dikirim hari apa pun dalam minggu itu; Jumat = batas. **Sprint 31:** boleh lebih dari sekali seminggu (seperti 20 & 25 Okt di Excel), setelah pengecekan sebelumnya minggu itu diputuskan PM. | `qa` → Hendra → tab **Item RAB → Lembar Progres**: Senin minggu ini sudah ada pengecekan (+10 %, disetujui PM); kolom kuning **Isi hari ini** kosong → **Mulai isi** → isi hanya item yang dikerjakan (item lain biarkan kosong — tidak ikut terkirim) + catatan → **Kirim Pengecekan · Ajukan Rp …** ("Minggu ke-2 · Pengecekan ke-2"). |
| 10 | QC juga bisa ngisi status task | Pengawas bisa **Centang selesai** sendiri (tukang gaptek) → dihitung laporan tukang hari itu, tanpa penalti. | `qa` → Hendra → **Daily Target → Per Hari** → kartu yang belum disentuh tukang → **Centang selesai**. |
| 11 | Tiap karyawan bisa input daily activity (custom saja) | Menu **Aktivitas Harian** untuk semua akun kecuali Tukang: teks bebas, beberapa per hari, boleh pilih proyek; hanya hari ini. HR memeriksa ("Periksa Aktivitas"). | `qa` → menu **Aktivitas Harian**: entri hari ini, kemarin (HR minta klarifikasi + notifikasinya), hari sebelumnya (Sesuai), dan sebulan penuh bulan lalu. `hr` → **SDM → Periksa Aktivitas** → hari kerja kemarin. |
| 12 | KPI | KPI SDM membaca Aktivitas Harian: **% hari kerja mengisi** & **% dinilai Sesuai** (dipilih HR di Template KPI). KPI Pengawas "Form QA diproses" juga menghitung **item QA**. | `hr` → **SDM → Kinerja → KPI** → bulan lalu → **Dodi** (karyawan akun `qa`): Mengisi aktivitas harian **92,31 %**, Aktivitas dinilai Sesuai **95,83 %**. Template: **⚙ Pengaturan → Template KPI → Quality Assurance**. |
| 13 | Pelajarin per RAB | = **persentase progres per item RAB** (ronde 3). Progres proyek = rata-rata berbobot nilai RAB (File C), dipakai di daftar proyek, ringkasan, syarat 70 %. **Sprint 31:** kartu **Rekap Progres per Item** = lembar Simulasi Persentasi sebagai tabel. | `qa`/`pm` → Hendra → **Ringkasan**: "Progres Berbobot **60 %**" (dari pengecekan, bukan dari daily target). Tab **Item RAB → Lembar Progres**: Bobot · Total · Kontribusi · % di sel tanggal pengecekan · baris "Progres proyek" 50 % → 60 %. |
| 14 | Pengawas yang menentukan upah, di sebelahnya RAB & harga per item → nilai & bobot → nominal yang bisa dicairkan per minggu berdasarkan progres | Kartu **Upah Tukang**: Uraian · Volume · Harga RAB · **Satuan Upah** (diketik Pengawas) · Subtotal Upah · **Bobot**. Pengecekan % per item → "**upah boleh cair**" − "sudah cair" = "**cair kali ini**" → PM menyetujui → Finance membayar. DP 10 % disetujui PM = Start Produksi. ("Kepala desain" = **Kepala Tim tukang**, ronde 1.) | `qa` → Hendra → tab **Item RAB** → **Upah Tukang**: harga RAB di samping Satuan Upah, bobot per item. **Item RAB → Lembar Progres** (isian di kolom Hari ini): sebelumnya 50 %, tambahan 25 % → ringkasan cair kali ini. Riwayat: DP & minggu ke-1 sudah **Dibayar**. `finance` → **Keuangan → Upah Tukang**. `qa`/`pm` → **Siska Amelia**: DP 10 % menunggu PM. |
| 15 | Pembagian upah yang bisa dicairkan itu ada perhitungannya | Dibagi **per Kepala Tim** sesuai progres item tiap tim. (Pembagian per orang — file `Upah Tim Interior` — **dibatalkan** atas keputusan Anda: "upah tidak usah sampai ke tukang".) | Hendra punya 2 Kepala Tim (**Tim Putra**, **Tim Yusuf**): kartu Upah Tukang & pembayaran menampilkan rincian per tim, mis. minggu ke-1 Rp 1.980.000 = Tim Putra 1.680.000 + Tim Yusuf 300.000. |

### Tambahan dari jawaban ronde 3–5

| # | Jawaban Anda | Cara cek |
|---|---|---|
| A | Admin Proyek: "mengurus semua proses khusus di bagian projek, penginputan" | `adminproyek` → **Perlu Tindakan** ("Proyek menunggu input", "Daily target menunggu dicek") → bisa isi TM, timeline, upah, draf pengecekan, daily target; **tidak** ada tombol TM Selesai, Ajukan DP, Kirim Pengecekan, Lolos QA. |
| B | Bahan bisa custom, diajukan ke Logistik, kelihatan statusnya | `qa` → Bambang → kartu TM → bahan "Engsel soft-close Blum" (custom) → **Ajukan ke Logistik (2 bahan)**. Status di proyek lain: Siska **Diajukan**, Hendra **Dipesan** (perkiraan tiba +3 hari), Dewi **Disetujui**. |
| C | DP upah 10 %, PM, DP disetujui = Start Produksi | `pm` → **Siska Amelia** → tab Eksekusi → **Putuskan → Setujui** → tahap berubah ke **Produksi**. |
| D | Progres ≥ 70 % → berangkat ke lokasi | `qa` → Hendra → isi **+15** di semua item → **Kirim Pengecekan** (progres 75 %) → `pm` mendapat notifikasi "siap berangkat" → tab Eksekusi → **Setujui Berangkat ke Lokasi**. |
| E | Form tanpa menunggu loading | Simpan bahan / upah / centang target: tombol berputar sebentar, **tidak** ada layar "Menyimpan…" dan tidak ada pop-up untuk isian berulang. |

### Tambahan dari file Simulasi Persentasi (Sprint 31)

| # | Di Excel | Cara cek |
|---|---|---|
| F | Tabel item × tanggal, Total %, Kontribusi | `qa` → Hendra → tab **Item RAB → Lembar Progres**. |
| G | Pengecekan 2× seminggu (20 & 25 Okt) | `qa` → Hendra → **Item RAB → Lembar Progres** → "Minggu ke-2 · Pengecekan ke-2". |
| H | Sel biru muda = jadwal per item, blok kedua = pemasangan | `qa` → Hendra → tab **Item RAB → Lembar Progres**: blok per hari (biru = produksi, hijau = pemasangan), 1 item **Terlambat**; ✎ → **Atur Jadwal** (mulai + lama hari); tombol **Jadwalkan Berurutan** (tangga seperti Excel). **Perlu Tindakan → Item terlambat dari jadwal** (juga untuk `pm`). Siska: 2 item belum dijadwalkan. Dewi: semua Selesai. |

## Yang belum / sengaja tidak dibuat

- **Pembagian upah per orang** (file `Upah Tim Interior DAIKU.xlsx`) — Sub 08b dibatalkan; pembayaran berhenti di Kepala Tim.
- Data dev Anda (`daiku_interior`, :8010) tidak berisi data Sprint 29 — dibuat sebelum fitur ini ada. Gunakan :8011.
- **Kinerja Saya** milik `qa` hanya menampilkan bulan KPI yang sudah ditutup HR; bulan lalu masih terbuka, jadi lihat lewat akun `hr`.
