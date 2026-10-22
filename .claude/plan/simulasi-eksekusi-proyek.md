# Simulasi Eksekusi Proyek — dari Buka Proyek sampai Selesai

Skenario uji alur eksekusi proyek (Sprint 24, direvisi Sprint 29): satu
proyek diikuti dari PM membuka proyek, Gambar Kerja, Technical Meeting,
Persiapan (Upah Tukang per item RAB, DP upah), Produksi (daily target,
pengecekan % per item, pencairan upah), Berangkat ke Lokasi, Pemasangan,
**QA per item RAB**, sampai **Completed** dan pelunasan. Tiap langkah
menyebut **siapa yang login**, **menu & tombol**, **isian**, dan **hasil
yang harus terlihat**.

Untuk alur sebelum proyek dibuka (lead → survey → desain → RAB Proyek) lihat
[`simulasi-alur-klien.md`](simulasi-alur-klien.md). Alur yang sama diuji
otomatis di `tests/Feature/Projects/SupervisionJourneyTest.php` (angka File
A/B di `sprint-29/*.xlsx`).

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
   | Pengawas kedua | `qa2` |
   | Admin Proyek | `adminproyek` |
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
| Proyek Bambang Sutrisno | Technical Meeting — Upah Tukang baru sebagian, Rencana Bahan draf (katalog + custom) → **Ajukan ke Logistik (2 bahan)** |
| Proyek Siska Amelia | Persiapan — bahan Diajukan ke Logistik; DP upah tukang menunggu keputusan PM |
| Proyek Hendra Gunawan | Produksi — dua Pengawas (`qa`, `qa2`); pengecekan minggu-1 (25 %) sudah dibayar, minggu ini **draf +15 %** belum dikirim; 2 daily target menunggu dicek, 1 dikembalikan ke tukang; bahan **Dipesan** |
| Proyek Dewi Lestari | Pemasangan — semua item 100 %: **QC Item** (1 Lolos, 1 Perlu perbaikan, 1 Menunggu QA); Temuan Finishing terbuka |

Akun `qa` juga punya **Aktivitas Harian** hari ini, kemarin (HR minta
klarifikasi) dan hari sebelumnya (Sesuai) — data dari
`Demo\PengawasPreviewSeeder`.
---

## Gambaran alur

```
PM           : Buka Proyek (Kepala Tim, satu atau lebih Pengawas, tanggal) → GAMBAR_KERJA
Kepala Desain: Tugaskan Arsitek
Arsitek      : Simpan link → Gambar Kerja Selesai                          → TM
PM / Admin   : kartu TM — jadwal, Timeline, notulen, peserta, DP %
Pengawas     : kartu TM — Bahan (katalog / custom) → Ajukan ke Logistik
PM           : TM Selesai (header halaman, pop-up konfirmasi)               → PERSIAPAN
Pengawas     : Upah Tukang per item RAB + Daily Target → Ajukan DP Upah
PM           : Setujui DP Upah                                             → PRODUKSI (Start Produksi)
Finance      : Upah Tukang → Bayar DP
Tukang       : ✔ Selesai / kendala tiap hari → "Minta dicek"
Pengawas     : Setujui / Kembalikan / Centang selesai (Daily Target › Per Hari)
Pengawas     : Pengecekan % per item (kapan saja, paling lambat Jumat) = ajukan pencairan
PM           : Putuskan pengecekan (boleh menurunkan) → Finance bayar
PM           : progres berbobot ≥ 70% → Setujui Berangkat ke Lokasi        → PEMASANGAN
Pengawas     : Temuan Finishing → Task Perbaikan → Tukang ✔
Pengawas     : item 100 % → QC Item: Lolos / Perlu perbaikan
Sistem       : semua item lolos QA + syarat lain                           → COMPLETED
Marketing/Finance: invoice pelunasan · Pengawas: pelunasan upah → PM → Finance
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
  - **Pengawas (QA/QC):** QA Daiku Interior **dan** Pengawas Kedua (boleh
    lebih dari satu — semuanya setara)
  - Tanggal Buka: hari ini · Tanggal Mulai: hari ini · Tanggal Selesai: +14 hari
- **Hasil:** proyek **Active**, tahap **Gambar Kerja**; termin DP & Pelunasan
  dibuat otomatis; item RAB menjadi item kerja proyek (untuk Upah Tukang);
  Kepala Desain, kedua Pengawas, Finance, Logistik mendapat notifikasi.

### Langkah 2 · (opsional) Tagihan DP
- **Marketing:** proyek → tab **Keuangan** → termin DP → **Terbitkan Invoice**
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
**Technical Meeting** (langkah bernomor, ✓ per langkah). Sejak Sprint 29 Sub
03, **TM Selesai** tidak lagi menunggu bagian Pengawas (bahan, upah) — itu
syarat DP di tahap Persiapan.

### Langkah 5 · PM menjadwalkan TM
- **Login:** `pm` → kartu TM → **Tanggal TM** (mis. +2 hari) → **Simpan TM**.
- **Hasil:** Pengawas dan peserta yang punya akun mendapat notifikasi
  **"Jadwal Technical Meeting"** (pindah tanggal → "Jadwal TM Diubah").
- **Variasi (Sub 07):** `adminproyek` boleh mengisi tanggal, notulen,
  peserta, dan timeline atas nama PM — tetapi **tidak** menekan TM Selesai.

### Langkah 6 · PM menyusun timeline (di kartu TM)
- **Pembagian Timeline** → **Tambah Timeline** (tersimpan langsung; ubah ✏ /
  hapus 🗑 di baris, hapus lewat pop-up konfirmasi):
  1. `Produksi Kabinet` — hari ini s.d. +6 hari
  2. `Pemasangan & Finishing` — +7 s.d. +14 hari
- Tab **Timeline & QC** (Gantt) tetap bisa dipakai — isinya sama.

### Langkah 7 · Pengawas merencanakan bahan (di kartu TM)
- **Login:** `qa` → kartu TM → **Bahan yang Digunakan**: kiri = **acuan
  item RAB** (uraian + volume), kanan = rencana bahan.
- Ketik nama/kode barang → pilih (↓/↑ + Enter atau klik) → isi **Jumlah**
  → Enter; beberapa baris boleh diisi berurutan tanpa menunggu ("n bahan
  tersimpan ✓"). Barang di luar katalog → baris **custom** (nama + satuan).
- **Ajukan ke Logistik (n bahan)** → Logistik memutuskan & memesan di
  **Logistik → Pemesanan Bahan** (status per baris: Direncanakan · Diajukan ·
  Disetujui / Ditolak · Dipesan · Lengkap).
- Bisa diubah/dihapus selama masih **Direncanakan / Diajukan**; sesudahnya
  lewat Logistik.

### Langkah 8 · PM melengkapi TM & TM Selesai
- **Login:** `pm` → kartu TM:
  - Notulen: `Disepakati urutan kerja kabinet dapur lalu pemasangan; bahan HPL warna walnut.`
  - Peserta: PM Daiku Interior, QA Daiku Interior, ketik `Pak Darto (Kepala Tim)`
  - DP upah `10`% · Tanggal Selesai Proyek +14 hari (default = selesai
    timeline terakhir). Nilai **Upah Tukang** = Σ upah per item (Langkah 8b),
    tidak diketik di sini.
- **Simpan TM** → semua langkah ✓ → **TM Selesai** di **header halaman** →
  pop-up **"Selesaikan Technical Meeting?"** → **Ya, TM Selesai**.
- **Hasil:** tahap **Persiapan**; Pengawas (dan Asisten PM) mendapat
  notifikasi "TM Selesai — Lengkapi Syarat Produksi".

---

## Tahap 4 — Persiapan & DP Upah Tukang

### Langkah 8b · Pengawas mengisi Upah Tukang per item RAB
- **Login:** `qa` (atau `qa2` / `adminproyek`) → tab **Item RAB** →
  **Upah Tukang**: tiap item RAB (uraian, volume, harga RAB) → isi **Satuan
  Upah** (tersimpan per baris) dan **Kepala Tim** ("Terapkan ke bagian ini"
  untuk satu bagian sekaligus). Item tanpa upah (barang jadi) dibiarkan kosong.
- **Hasil:** subtotal upah = satuan upah × volume; **bobot** tiap item =
  nilai RAB item ÷ Σ nilai RAB item berupah; total = nilai Upah Tukang
  proyek (contoh File A: Rp 7.705.000).
- `pm` hanya melihat — tidak bisa mengisi (403).

### Langkah 8c · Pengawas membuat daily target
- Tab **Daily Target** → **Tambah Daily Target** (wajib timeline). Kolom
  **Upah** tidak ada lagi — upah ada di item RAB. Tukang = Field Staff:

  | Judul | Timeline | Tanggal |
  |---|---|---|
  | `Potong panel kabinet` | Produksi Kabinet | hari ini |
  | `Rakit badan kabinet` | Produksi Kabinet | +2 hari |
  | `Lapis HPL pintu kabinet` | Produksi Kabinet | +4 hari |
  | `Pasang kabinet di lokasi` | Pemasangan & Finishing | +8 hari |

### Langkah 9 · Pengawas mengajukan DP upah
- Tab **Eksekusi** → kartu **Syarat DP Upah Tukang**: bahan · upah tukang
  (minimal satu item berupah) · daily target (tiap timeline minimal satu) ·
  DP %; yang belum lengkap menyebut apa yang kurang + **Lengkapi →**.
- Lengkap semua → **Ajukan DP Upah · Rp x** (10 % × total upah).
- `adminproyek` tidak punya tombol ini (403).

### Langkah 10 · PM menyetujui DP = Start Produksi
- **Login:** `pm` → tab **Eksekusi** → **Putuskan → Setujui** (nominal boleh
  diubah; rincian per Kepala Tim ikut).
- **Hasil:** tahap **Produksi**; timeline pertama berjalan; Logistik, Kepala
  Tim, Pengawas mendapat notifikasi. Satuan Upah yang sudah diisi terkunci.

### Langkah 11 · Finance membayar DP
- **Login:** `finance` → **Keuangan → Upah Tukang** → baris "DP · Proyek
  Interior Rumah Kevin Halim" (rincian per Kepala Tim) → **Bayar**.
- **Hasil:** transaksi PENGELUARAN kategori **Jasa Pengawas** tercatat; uang
  diserahkan Pengawas ke Kepala Tim.

---

## Tahap 5 — Produksi

### Langkah 12 · Tukang melaporkan target (dari HP)
- **Login:** `fieldstaff` (sebaiknya layar HP) → **Hari Ini** → **✔ Selesai**
  di kartu `Potong panel kabinet` → status **Minta dicek** (menunggu
  Pengawas).
- Atau ketuk kartu → isi **kendala** → Simpan:
  ```
  Mesin potong sempat mati, lanjut besok pagi.
  ```
- Mengubah status/kendala **adalah** laporan hari itu. Tukang yang tidak
  menyentuh satu pun target hari itu sampai jam batas → penalti otomatis.

### Langkah 12b · Pengawas memeriksa daily target
- **Login:** `qa` atau `qa2` → **Perlu Tindakan → Daily target menunggu
  dicek**, atau tab **Daily Target › Per Hari**: kartu **Minta dicek** →
  **Setujui** (→ Selesai) atau **Kembalikan** (catatan wajib → tukang
  diberi tahu); **Setujui semua (n)** per hari.
- Tukang gaptek: **Centang selesai** langsung di kartu → dihitung laporan
  tukang hari itu (tidak kena penalti).
- **Cek:** tab **Daily Target › Activity Target** — "Actual activity (daily
  target)" bertambah. Ini dasar penalti, **bukan** progres proyek.

### Langkah 13 · Pengawas menggeser jadwal
- Tab **Daily Target → Per Hari** → **seret kartu** ke hari lain (di HP:
  ketuk kartu → **Pindah ke sini**) — bebas, tidak dibatasi timeline
  (target pindah ke timeline di tanggal barunya); hari **Minggu** ditolak;
  target **Done** terkunci 🔒. Alternatif: **Geser Tanggal** / **Tukar Hari**.

### Langkah 14 · Pengecekan % per item = pengajuan pencairan
- **Login:** `qa` → tab **Item RAB** → **Lembar Progres** (atau kartu Pengecekan di
  Eksekusi → **Isi Pengecekan**) → kolom **Hari ini** per item (di HP: kartu per item): Uraian · Kepala Tim · Sebelumnya · **Tambahan %**
  (tombol cepat +10/+20/+50/100 %) · Progres baru. Draf tersimpan otomatis
  (`adminproyek` boleh mengisi draf, tidak boleh mengirim).
- Ringkasan langsung: progres berbobot, upah boleh cair, sudah cair, **cair
  kali ini**, rincian per Kepala Tim. Contoh File B: semua item 70 %, Meja bar
  65 % → progres **69,48 %**, upah boleh cair Rp 5.322.000, cair kali ini
  **Rp 4.551.500** (sesudah DP).
- **Kirim Pengecekan · Ajukan Rp x** → pop-up konfirmasi → PM.
- **Login:** `pm` → **Putuskan** → Setujui (boleh menurunkan; 0 = tidak
  dicairkan) atau Tolak + alasan (% tetap tercatat, bisa diajukan lagi).
- **Login:** `finance` → **Upah Tukang** → "Mingguan minggu ke-1" → **Bayar**.

---

## Tahap 6 — Berangkat ke Lokasi

### Langkah 15 · Progres berbobot ≥ 70%
- Progres proyek = **progres berbobot** dari pengecekan Pengawas (Σ bobot ×
  progres item), bukan daily target. Pengecekan berikutnya yang membawa
  progres ≥ 70 % → PM mendapat notifikasi sekali "siap berangkat ke lokasi";
  muncul di **Perlu Tindakan**.

### Langkah 16 · PM menyetujui berangkat
- **Login:** `pm` → tab **Eksekusi** → **Setujui Berangkat ke Lokasi**
  (terkunci selama progres < 70%) → pop-up konfirmasi.
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
- **Login:** `fieldstaff` → **Hari Ini** → **✔ Selesai** pada task perbaikan
  → Pengawas **Setujui**.
- **Hasil:** temuan otomatis **Selesai**. (Selama temuan masih terbuka,
  penyelesaian proyek tertahan.)

---

## Tahap 8 — QA per item RAB (Sprint 29 Sub 12)

### Langkah 19 · PM menandai timeline selesai
- **Login:** `pm` → tab **Timeline & QC** → `Produksi Kabinet` → **Tandai
  Selesai** → pop-up konfirmasi.
- **Hasil:** timeline langsung **Selesai** (tidak ada QA Form lagi);
  timeline berikutnya berjalan. Ulangi untuk `Pemasangan & Finishing`.

### Langkah 20 · Pengawas memeriksa item yang 100 %
- Pengecekan terakhir membawa item ke **100 %** → **Perlu Tindakan → "Item
  menunggu QA · n item · Proyek X"** → tab **Item RAB → QC Item**.
- **Lolos banyak sekaligus:** centang item → **Lolos QA (n)** → pop-up.
- **Periksa** satu item → **Lolos** / **Perlu Perbaikan** + catatan, **foto
  dari kamera HP**, atau link.
- **Perlu perbaikan** → temuan tercatat → **Jadikan Task Perbaikan** →
  tukang selesai & disetujui (atau **Sudah Diperbaiki**) → item kembali
  **Menunggu QA** → Periksa lagi.
- Hanya Pengawas proyek (`qa`, `qa2`) yang memutuskan; `adminproyek`, `pm`,
  QA lain hanya melihat. PM mendapat notifikasi "QA Item RAB".
- Coba di proyek demo **Dewi Lestari**.

---

## Tahap 9 — Proyek selesai & pelunasan

### Langkah 21 · Proyek Completed otomatis
Setelah item terakhir lolos QA, proyek **Completed** bila:
- semua item berupah 100 % dan **Lolos QA**,
- tidak ada temuan finishing terbuka,
- tidak ada sisa material,
- tidak ada pengajuan barang yang belum diputuskan.

> Proyek lama yang timeline-nya sudah pernah lewat QA Form tetap memakai QA
> per timeline (Tandai Selesai → menu **QA/QC** → Setujui QA).

> ⚠️ Bila Logistik sempat mengeluarkan bahan, catat pemakaian / retur /
> susut di tab **Material** — kalau tidak, proyek tertahan dan PM + Logistik
> menerima notifikasi "Proyek Tertahan: Material".

### Langkah 22 · Pelunasan termin klien
- Termin **Pelunasan** jatuh tempo → **Marketing:** Terbitkan Invoice →
  Tandai Klien Sudah Bayar → **Finance:** Verifikasi Pembayaran.

### Langkah 23 · Pelunasan upah tukang
- Pengecekan yang membawa semua item ke 100 % otomatis menjadi pengajuan
  **Pelunasan** (sisa = total upah − DP − pencairan sebelumnya); atau `qa` →
  kartu **Upah Tukang** → **Ajukan Pelunasan · Rp x**.
- **Login:** `pm` → **Putuskan → Setujui**.
- **Login:** `finance` → **Upah Tukang** → **Bayar**.
- **Hasil:** total dibayar = total Upah Tukang (tidak bisa melebihi).

---

## Aturan berbasis waktu — jalankan manual

Job terjadwal ini bisa dipicu tanpa menunggu jamnya, lewat
`php artisan tinker`:

```php
dispatch_sync(new App\Jobs\TaskOverdueJob);             // 00:00 — target bergulir ke hari kerja berikutnya / Terlambat bila lewat timeline
dispatch_sync(new App\Jobs\DailyPenaltyJob);            // jam batas — penalti tukang yang tidak melaporkan target hari ini
dispatch_sync(new App\Jobs\WeeklyReportReminderJob);    // Jumat 15:00 — pengingat pengecekan mingguan ke Pengawas
dispatch_sync(new App\Jobs\ProjectDeadlineReminderJob); // 08:00 — notifikasi H-30% ke PM (sejak 70% durasi proyek)
```

---

## Uji penolakan (harus gagal / terkunci)

| Coba | Login | Yang harus terjadi |
|---|---|---|
| **TM Selesai** sebelum checklist lengkap | `pm` | Tombol terkunci; server tetap menolak bila dipaksa |
| **TM Selesai**, Ajukan/Setujui DP, Lolos QA item | `adminproyek` | 403 (Admin Proyek hanya menginput) |
| Isi Satuan Upah | `pm` | 403 |
| Ubah Satuan Upah yang sudah diisi setelah Start Produksi | `qa` | Ditolak ("dikunci setelah Start Produksi") |
| Pengecekan membuat progres item > 100 % / koreksi minus tanpa catatan | `qa` | Ditolak |
| Kirim pengecekan | `adminproyek` | 403 (draf boleh) |
| Ubah / hapus rencana bahan setelah Diajukan disetujui | `qa` | Lewat Logistik |
| Ubah / hapus rencana bahan proyek lain | QA lain | 403 |
| Daily target hari Minggu | `qa` | Ditolak |
| Ubah judul / tanggal task | `fieldstaff` | Tidak bisa |
| Nominal pencairan di atas sisa upah | `pm` | Ditolak |
| **Berangkat ke Lokasi** saat progres < 70% | `pm` | Tombol terkunci |
| Lolos QA item yang belum 100 % / keputusan QA item proyek lain | `qa` / QA lain | Ditolak / 403 |
| Isi TM / setujui DP di proyek PM lain | PM lain | 403 |
| Lihat tab Alokasi Dana, harga RAB & upah | `marketing`, `logistics` | Tidak tampil |

---

## Daftar periksa singkat

- [ ] 1. PM: Buka Proyek (Kepala Tim, dua Pengawas, tanggal)
- [ ] 2. (opsional) Marketing/Finance: invoice DP terverifikasi
- [ ] 3. Kepala Desain: Tugaskan Arsitek (Gambar Kerja)
- [ ] 4. Arsitek: simpan link → Gambar Kerja Selesai
- [ ] 5. PM: jadwalkan TM (tanggal) → Pengawas lihat di Perlu Tindakan
- [ ] 6. PM / Admin Proyek: timeline di kartu TM
- [ ] 7. Pengawas: bahan di kartu TM → Ajukan ke Logistik
- [ ] 8. PM: lengkapi TM → TM Selesai (header, pop-up konfirmasi)
- [ ] 8b. Pengawas: Satuan Upah & Kepala Tim per item RAB
- [ ] 8c. Pengawas: daily target
- [ ] 9. Pengawas: Ajukan DP Upah
- [ ] 10. PM: Setujui DP (Start Produksi)
- [ ] 11. Finance: Bayar DP upah
- [ ] 12. Tukang: ✔ Selesai / kendala dari HP
- [ ] 12b. Pengawas: Setujui / Kembalikan / Centang selesai
- [ ] 13. Pengawas: seret target di Per Hari / Geser Tanggal / Tukar Hari
- [ ] 14. Pengecekan % per item: Pengawas → PM → Finance
- [ ] 15–16. Progres berbobot ≥ 70% → PM: Setujui Berangkat ke Lokasi
- [ ] 17–18. Temuan finishing → task perbaikan → selesai
- [ ] 19. PM: Tandai Selesai timeline (tanpa QA Form)
- [ ] 20. Pengawas: QC Item — Lolos / Perlu perbaikan
- [ ] 21. Proyek Completed
- [ ] 22. Pelunasan termin klien
- [ ] 23. Pelunasan upah tukang
- [ ] Uji penolakan
