# Simulasi Eksekusi Proyek — dari Buka Proyek sampai Selesai

Skenario uji alur eksekusi proyek (Sprint 24): satu proyek diikuti dari
PM membuka proyek, Gambar Kerja, Technical Meeting, Persiapan, Produksi,
Berangkat ke Lokasi, Pemasangan, QA per timeline, sampai **Completed** dan
pelunasan. Tiap langkah menyebut **siapa yang login**, **menu & tombol**,
**isian**, dan **hasil yang harus terlihat**.

Untuk alur sebelum proyek dibuka (lead → survey → desain → RAB Proyek) lihat
[`simulasi-alur-klien.md`](simulasi-alur-klien.md).

---

## 0. Persiapan

1. Siapkan data demo (menghapus data lama):
   ```
   php artisan migrate:fresh --seed
   ```
2. Jalankan aplikasi beserta worker & scheduler (supaya notifikasi muncul
   langsung):
   ```
   composer dev
   ```
   Buka `http://127.0.0.1:8010`.
3. Akun — login dengan **username** (atau email `{role}@daikuinterior.com`),
   kata sandi semua `password`:

   | Peran | Username |
   |---|---|
   | PM | `pm` |
   | Pengawas (QA/QC) | `qa` |
   | Kepala Desain | `kepaladesain` |
   | Arsitek | `designer` |
   | Tukang | `fieldstaff` |
   | Finance | `finance` |
   | Marketing | `marketing` |
   | Logistik | `logistics` |
   | CEO | `ceo` |

> **Tips:** buka beberapa jendela browser (biasa, Incognito, browser lain)
> supaya bisa login sebagai beberapa peran sekaligus. Antrean tiap peran juga
> terlihat di menu **Perlu Tindakan** (angka merah di menu = jumlah antrean).

### Titik mulai

Lead **Kevin Halim** sudah punya RAB Proyek yang **disetujui klien** dan
menunggu Buka Proyek oleh `pm` (skema bayar: DP 40% di muka, Pelunasan 60%
saat proyek selesai).

### Jalan pintas — proyek demo per tahap

Bila hanya ingin menguji satu tahap, data demo sudah menyediakan:

| Proyek | Tahap saat ini |
|---|---|
| Proyek Rina Marlina | Gambar Kerja — menunggu Kepala Desain |
| Proyek Bambang Sutrisno | Technical Meeting — timeline sudah ada |
| Proyek Siska Amelia | Persiapan — DP pengawas menunggu keputusan PM |
| Proyek Hendra Gunawan | Produksi — laporan mingguan menunggu PM |

---

## Gambaran alur

```
PM           : Buka Proyek (Kepala Tim, Pengawas, tanggal)         → GAMBAR_KERJA
Kepala Desain: Tugaskan Arsitek
Arsitek      : Simpan link → Gambar Kerja Selesai                  → TM
PM           : Jadwalkan TM (tanggal → Pengawas & peserta diberi tahu)
PM           : kartu TM — Timeline, notulen, peserta, jasa pengawas, DP %
Pengawas     : kartu TM — Bahan (cari barang, jumlah, Enter) di samping acuan item RAB
PM           : TM Selesai (header halaman, pop-up konfirmasi)               → PERSIAPAN
Pengawas     : Daily Target (+ upah) → Ajukan DP Pengawas
PM           : Setujui DP                                          → PRODUKSI (Start Produksi)
Finance      : Bayar DP Pengawas
Tukang       : ✔ Selesai / kendala tiap hari (= laporan harian)
Pengawas     : Laporan Mingguan (Jumat) → PM putuskan → Finance bayar
PM           : progres ≥ 70% → Setujui Berangkat ke Lokasi         → PEMASANGAN
Pengawas     : Temuan Finishing → Task Perbaikan → Tukang ✔
PM           : Tandai Selesai timeline → Pengawas: Setujui QA (tiap timeline)
Sistem       : semua syarat terpenuhi                              → COMPLETED
Marketing/Finance: invoice pelunasan · Pengawas: Ajukan Pelunasan jasa → PM → Finance
```

---

## Tahap 1 — Buka Proyek

### Langkah 1 · PM membuka proyek
- **Login:** `pm` (PM yang meng-ACC RAB Proyek itu).
- Pop-up **Buka Proyek** muncul — atau **Perlu Tindakan → Proyek siap
  dibuka**, atau **Proyek → Menunggu Dibuka**.
- **Isi:**
  - Nama Proyek: `Interior Rumah Kevin Halim`
  - Kepala Tim: pilih karyawan **atau** ketik nama, mis. `Tim Pak Darto`
  - **Pengawas (QA/QC):** QA Daiku Interior
  - Tanggal Buka: hari ini · Tanggal Mulai: hari ini · Tanggal Selesai: +14 hari
- **Hasil:** proyek **Active**, tahap **Gambar Kerja**; termin DP & Pelunasan
  dibuat otomatis; Kepala Desain, Pengawas, Finance, Logistik mendapat
  notifikasi.

### Langkah 2 · (opsional) Tagihan DP
- **Marketing:** proyek → tab **Finance** → termin DP → **Terbitkan Invoice**
  → **Tandai Klien Sudah Bayar**.
- **Finance:** **Verifikasi Pembayaran**.
- **Hasil:** termin DP **Paid**; tab **Alokasi Dana** terbuka untuk PM.

---

## Tahap 2 — Gambar Kerja

### Langkah 3 · Kepala Desain menugaskan arsitek
- **Login:** `kepaladesain` → **Desain → Gambar Kerja** → proyek Kevin Halim
  → **Tugaskan Arsitek** (PIC = Arsitek, tanggal mulai, target hari).

### Langkah 4 · Arsitek menyelesaikan gambar kerja
- **Login:** `designer` → buka gambar kerja proyek → simpan link
  (mis. `https://drive.google.com/drive/folders/gambar-kerja-kevin`) →
  **Gambar Kerja Selesai** (tanpa persetujuan klien).
- **Hasil:** proyek pindah ke tahap **Technical Meeting**; PM & Pengawas
  mendapat notifikasi.

---

## Tahap 3 — Technical Meeting (TM)

Semua isian TM ada di **satu kartu**: proyek → tab **Eksekusi** → kartu
**Technical Meeting** (langkah 1–4 bernomor, ✓ per langkah, hitungan
"n/6 syarat" di pojok kartu).

### Langkah 5 · PM menjadwalkan TM
- **Login:** `pm` → kartu TM → langkah **1 · Jadwal & Rapat** → isi
  **Tanggal TM** (mis. +2 hari) → **Simpan TM** (jasa pengawas boleh masih
  kosong).
- **Hasil:** Pengawas dan peserta yang punya akun mendapat notifikasi
  **"Jadwal Technical Meeting"** (pindah tanggal → "Jadwal TM Diubah").
- **Login:** `qa` → **Perlu Tindakan** → antrean **"Technical Meeting ·
  giliran Pengawas"**: "TM Rab, 21 Okt 2026 · Bahan belum direncanakan" →
  klik → langsung ke bagian bahan di kartu TM.

### Langkah 6 · PM menyusun timeline (di kartu TM)
- Langkah **2 · Pembagian Timeline** → **Tambah Timeline** (tersimpan
  langsung; ubah ✏ / hapus 🗑 di baris, hapus lewat pop-up konfirmasi):
  1. `Produksi Kabinet` — hari ini s.d. +6 hari
  2. `Pemasangan & Finishing` — +7 s.d. +14 hari
- Tab **Timeline** (Gantt) tetap bisa dipakai — isinya sama.

### Langkah 7 · Pengawas merencanakan bahan (di kartu TM)
- **Login:** `qa` → kartu TM → **Bahan yang Digunakan**: kiri = **acuan
  item RAB** (uraian + volume, tanpa harga), kanan = rencana bahan.
- Ketik nama/kode barang → pilih (↓/↑ + Enter atau klik) → isi **Jumlah**
  → Enter. Sumber otomatis **Gudang** bila stok ada, **Beli** bila kosong.
  Contoh: Multiplek 18mm, 10 lbr; HPL, 8 lbr.
- Ubah jumlah langsung di baris (tersimpan saat keluar kolom / Enter);
  hapus 🗑 lewat pop-up konfirmasi. Bisa diubah sampai **Produksi** dimulai.
- Barang di luar katalog / vendor → link **Buka tab Material**.

### Langkah 8 · PM melengkapi TM & TM Selesai
- **Login:** `pm` → kartu TM:
  - Notulen: `Disepakati urutan kerja kabinet dapur lalu pemasangan; bahan HPL warna walnut.`
  - Peserta (berjajar ke samping): PM Daiku Interior, QA Daiku Interior, ketik `Pak Darto (Kepala Tim)`
  - Langkah **3**: Nilai Jasa Pengawas `5.000.000` · DP `10`% (nominal DP
    tampil langsung) · Tanggal Selesai Proyek +14 hari (default = selesai
    timeline terakhir)
- **Simpan TM** → semua langkah ✓ → tombol **TM Selesai** di **header
  halaman** (atau di bawah kartu) → pop-up **"Selesaikan Technical
  Meeting?"** → **Ya, TM Selesai**.
- Tombol terkunci selama syarat belum lengkap (tampil "TM Selesai 4/6";
  arahkan kursor untuk melihat yang kurang) atau isian kartu belum disimpan.
- **Hasil:** tahap **Persiapan**; Pengawas (dan Asisten PM) mendapat
  notifikasi "TM Selesai — Lengkapi Syarat Produksi"; antrean TM Pengawas
  hilang.

---

## Tahap 4 — Persiapan & DP Pengawas

### Langkah 8b · Pengawas membuat daily target
- **Login:** `qa` → proyek → tab **Task** → **Tambah Daily Target**
  (wajib timeline, tanggal di dalam rentang timeline, isi **Upah**).
  Tukang = Field Staff Daiku Interior:

  | Judul | Timeline | Tanggal | Upah |
  |---|---|---|---|
  | `Potong panel kabinet` | Produksi Kabinet | hari ini | 200.000 |
  | `Rakit badan kabinet` | Produksi Kabinet | +2 hari | 250.000 |
  | `Lapis HPL pintu kabinet` | Produksi Kabinet | +4 hari | 200.000 |
  | `Pasang kabinet di lokasi` | Pemasangan & Finishing | +8 hari | 300.000 |

### Langkah 9 · Pengawas mengajukan DP
- Tab **Eksekusi** → **Ajukan DP Pengawas** (aktif bila bahan ✓ · upah ✓ ·
  tiap timeline punya target).

### Langkah 10 · PM menyetujui DP = Start Produksi
- **Login:** `pm` → tab **Eksekusi** → **Putuskan → Setujui** (nominal
  default 10% × 5.000.000 = 500.000, boleh diubah).
- **Hasil:** tahap **Produksi**; Logistik, Kepala Tim, Pengawas mendapat
  notifikasi.

### Langkah 11 · Finance membayar DP pengawas
- **Login:** `finance` → **Keuangan → Pembayaran Staf → Jasa Pengawas** →
  baris "Bayar Pengawas: DP · Proyek Interior Rumah Kevin Halim" → **Bayar**.
- **Hasil:** transaksi PENGELUARAN kategori **Jasa Pengawas** tercatat.

---

## Tahap 5 — Produksi

### Langkah 12 · Tukang melaporkan target (dari HP)
- **Login:** `fieldstaff` (sebaiknya layar HP) → **Hari Ini** → tombol besar
  **✔ Selesai** di kartu `Potong panel kabinet`.
- Atau ketuk kartu → isi **kendala** → Simpan:
  ```
  Mesin potong sempat mati, lanjut besok pagi.
  ```
- Mengubah status/kendala **adalah** laporan hari itu (tidak ada form
  terpisah). Tukang yang tidak menyentuh satu pun target hari itu sampai
  jam batas → penalti otomatis.
- Ulangi untuk target lain (ubah tanggal target lewat Langkah 13 bila perlu
  supaya bisa dikerjakan hari ini).

### Langkah 13 · Pengawas menggeser jadwal
- **Login:** `qa` → tab **Task** → **Per Hari** (kolom Senin–Minggu, ‹ ›
  pindah minggu) → **seret kartu** ke hari lain (di HP: ketuk kartu →
  **Pindah ke sini**). Hari di luar timeline target itu pudar ("Di luar
  timeline") dan tidak bisa dituju; target **Done** terkunci 🔒.
- Alternatif: menu baris → **Geser Tanggal**, atau tab **Eksekusi →
  Target vs Actual → Tukar Hari**.
- Tukang mendapat notifikasi; riwayat geser tercatat di task.

### Langkah 14 · Laporan mingguan (Jumat)
- **Login:** `qa` → tab **Eksekusi → Laporan Mingguan** → isi catatan + link
  foto → kirim.
- **Login:** `pm` → **Putuskan** → Setujui + nominal jasa minggu ini
  (mis. 500.000) — atau Tolak + alasan.
- **Login:** `finance` → **Jasa Pengawas** → "Mingguan minggu ke-1" →
  **Bayar**.

---

## Tahap 6 — Berangkat ke Lokasi

### Langkah 15 · Progres ≥ 70%
- Selesaikan minimal 3 dari 4 target (75%) lewat Langkah 12.
- **Hasil:** PM mendapat notifikasi sekali "siap berangkat ke lokasi";
  muncul di **Perlu Tindakan**.

### Langkah 16 · PM menyetujui berangkat
- **Login:** `pm` → tab **Eksekusi** → **Setujui Berangkat ke Lokasi**
  (terkunci selama progres < 70%).
- **Hasil:** tahap **Pemasangan**; Pengawas, Kepala Tim, Logistik mendapat
  notifikasi.

---

## Tahap 7 — Pemasangan & temuan finishing

### Langkah 17 · Pengawas mencatat temuan
- **Login:** `qa` → tab **Eksekusi → Temuan Finishing** → tambah:
  ```
  Celah 3 mm antara pintu kabinet atas dan dinding, perlu list penutup.
  ```
- **Jadikan Task Perbaikan** → tukang Field Staff, tanggal hari ini.

### Langkah 18 · Tukang memperbaiki
- **Login:** `fieldstaff` → **Hari Ini** → **✔ Selesai** pada task perbaikan.
- **Hasil:** temuan otomatis **Selesai**. (Selama temuan masih terbuka, QA
  timeline dan penyelesaian proyek tertahan.)

---

## Tahap 8 — QA per timeline

### Langkah 19 · PM menyerahkan timeline
- **Login:** `pm` → tab **Timeline** → `Produksi Kabinet` → **Tandai Selesai**.
- **Hasil:** timeline **Menunggu Pemeriksaan · Pengawas (QA/QC)**; QA Form
  dibuat otomatis.

### Langkah 20 · Pengawas memeriksa
- **Login:** `qa` (Pengawas proyek ini) → menu **QA/QC** → QA Form timeline
  itu → isi checklist → **Setujui QA**.
- **Variasi:** **Tolak QA** → timeline kembali ke PM → Tandai Selesai lagi.
- Ulangi Langkah 19–20 untuk `Pemasangan & Finishing`.

---

## Tahap 9 — Proyek selesai & pelunasan

### Langkah 21 · Proyek Completed otomatis
Setelah QA timeline terakhir disetujui, proyek **Completed** bila:
- semua timeline lolos QA,
- tidak ada temuan finishing terbuka,
- tidak ada sisa material,
- tidak ada pengajuan barang yang belum diputuskan.

> ⚠️ Bila Logistik sempat mengeluarkan bahan, catat pemakaian / retur /
> susut di tab **Material** sebelum QA terakhir — kalau tidak, proyek
> tertahan dan PM + Logistik menerima notifikasi "Proyek Tertahan: Material".

### Langkah 22 · Pelunasan termin klien
- Termin **Pelunasan** jatuh tempo → **Marketing:** Terbitkan Invoice →
  Tandai Klien Sudah Bayar → **Finance:** Verifikasi Pembayaran.

### Langkah 23 · Pelunasan jasa pengawas
- **Login:** `qa` → tab **Eksekusi** → **Ajukan Pelunasan** (sisa = jasa −
  DP − mingguan).
- **Login:** `pm` → **Putuskan → Setujui**.
- **Login:** `finance` → **Jasa Pengawas** → **Bayar**.
- **Hasil:** total jasa pengawas dibayar = 5.000.000 (tidak bisa melebihi).

---

## Aturan berbasis waktu — jalankan manual

Job terjadwal ini bisa dipicu tanpa menunggu jamnya, lewat
`php artisan tinker`:

```php
dispatch_sync(new App\Jobs\TaskOverdueJob);             // 00:00 — target bergulir ke hari kerja berikutnya / Terlambat bila lewat timeline
dispatch_sync(new App\Jobs\DailyPenaltyJob);            // jam batas — penalti tukang yang tidak melaporkan target hari ini
dispatch_sync(new App\Jobs\WeeklyReportReminderJob);    // Jumat 15:00 — pengingat laporan mingguan ke Pengawas
dispatch_sync(new App\Jobs\ProjectDeadlineReminderJob); // 08:00 — notifikasi H-30% ke PM (sejak 70% durasi proyek)
```

---

## Uji penolakan (harus gagal / terkunci)

| Coba | Login | Yang harus terjadi |
|---|---|---|
| **TM Selesai** sebelum checklist lengkap | `pm` | Tombol terkunci ("TM Selesai n/6"); server tetap menolak bila dipaksa |
| Kosongkan Nilai Jasa Pengawas setelah pernah diisi | `pm` | Ditolak |
| Ubah / hapus rencana bahan setelah Produksi | `qa` | 403 (Logistik sudah memesan) |
| Ubah / hapus rencana bahan proyek lain | QA lain | 403 |
| Daily target di luar rentang timeline (form atau seret di Per Hari) | `qa` | Ditolak / hari tujuan pudar |
| Ubah judul / tanggal task | `fieldstaff` | Tidak bisa |
| Nominal DP / mingguan di atas sisa jasa | `pm` | Ditolak |
| **Berangkat ke Lokasi** saat progres < 70% | `pm` | Tombol terkunci |
| Isi TM / setujui DP di proyek PM lain | PM lain | 403 |
| Lihat tab Alokasi Dana & nilai jasa pengawas | `marketing` | Tidak tampil |
| QA timeline proyek yang bukan diawasinya | QA lain | Tidak bisa |

---

## Daftar periksa singkat

- [ ] 1. PM: Buka Proyek (Kepala Tim, Pengawas, tanggal)
- [ ] 2. (opsional) Marketing/Finance: invoice DP terverifikasi
- [ ] 3. Kepala Desain: Tugaskan Arsitek (Gambar Kerja)
- [ ] 4. Arsitek: simpan link → Gambar Kerja Selesai
- [ ] 5. PM: jadwalkan TM (tanggal) → Pengawas lihat di Perlu Tindakan
- [ ] 6. PM: timeline di kartu TM
- [ ] 7. Pengawas: bahan di kartu TM (acuan item RAB)
- [ ] 8. PM: lengkapi TM → TM Selesai (header, pop-up konfirmasi)
- [ ] 8b. Pengawas: daily target + upah
- [ ] 9. Pengawas: Ajukan DP Pengawas
- [ ] 10. PM: Setujui DP (Start Produksi)
- [ ] 11. Finance: Bayar DP pengawas
- [ ] 12. Tukang: ✔ Selesai / kendala dari HP
- [ ] 13. Pengawas: seret target di Task → Per Hari / Geser Tanggal / Tukar Hari
- [ ] 14. Laporan mingguan: Pengawas → PM → Finance
- [ ] 15–16. Progres ≥ 70% → PM: Setujui Berangkat ke Lokasi
- [ ] 17–18. Temuan finishing → task perbaikan → selesai
- [ ] 19–20. PM: Tandai Selesai → Pengawas: Setujui QA (tiap timeline)
- [ ] 21. Proyek Completed
- [ ] 22. Pelunasan termin klien
- [ ] 23. Pelunasan jasa pengawas
- [ ] Uji penolakan
