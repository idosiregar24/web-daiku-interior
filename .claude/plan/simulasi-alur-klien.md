# Simulasi Alur Lengkap — dari Klien Masuk sampai Proyek Selesai

Skenario uji coba sistem Daiku Interior: satu klien diikuti dari pertama
menghubungi, survey, desain, RAB proyek, pengerjaan, QA, sampai pelunasan.
Ikuti langkahnya berurutan; tiap langkah menyebut **siapa yang login**,
**menu & tombol** yang diklik, **isian** (siap disalin), dan **hasil yang
harus terlihat**.

---

## 0. Persiapan

1. Siapkan data demo (sekali saja, menghapus data lama):
   ```
   php artisan migrate:fresh --seed
   ```
2. Buka aplikasi: `http://web-daiku-interior.test` (atau
   `http://127.0.0.1:8010` bila memakai `php artisan serve --port=8010`).
3. Akun yang dipakai — semua kata sandinya `password`:

   | Peran | Email |
   |---|---|
   | Marketing | marketing@daikuinterior.com |
   | Estimator | estimator@daikuinterior.com |
   | PM | pm@daikuinterior.com |
   | Asisten PM | asistenpm@daikuinterior.com |
   | CEO | ceo@daikuinterior.com |
   | Finance | finance@daikuinterior.com |
   | Kepala Desain | kepaladesain@daikuinterior.com |
   | Arsitek | designer@daikuinterior.com |
   | Tukang | fieldstaff@daikuinterior.com |
   | QA | qa@daikuinterior.com |
   | Logistik | logistics@daikuinterior.com |

> **Tips:** buka beberapa jendela browser (biasa, Incognito, browser lain)
> supaya bisa login sebagai beberapa peran sekaligus. Halaman klien
> (link penawaran) sebaiknya dibuka di jendela Incognito **tanpa login**.
>
> Setiap peran juga bisa melihat pekerjaannya di menu **Perlu Tindakan**
> (angka merah di menu menunjukkan jumlah antrean).

---

## Gambaran alur

```
Marketing  : Tambah Lead → Follow-up → Jadwalkan Survey → Buat RAB Jasa Desain
Estimator  : Susun RAB → Kirim ke PM
PM         : Periksa item ✔/✘ → Setujui
Estimator  : Kirim RAB Final ke Marketing
Marketing  : Kirim ke Klien (link) ─────────────▶ Klien: Setujui Penawaran
Marketing  : Terbitkan Invoice → Kirim Bukti Bayar
Finance    : Verifikasi Pembayaran  → desain terbuka
Kep. Desain: Tugaskan Desain → Arsitek unggah link desain
Marketing  : Kirim Desain ke Klien → (revisi) → Konfirmasi ACC Klien
             → RAB Proyek otomatis diminta ke Estimator
Estimator  : Susun RAB Proyek + skema bayar → PM → CEO → Marketing → Klien setuju
CEO        : Buka Proyek → termin dibuat otomatis
Marketing  : Invoice DP → Finance verifikasi
PM         : Alokasi Dana → Milestone → Task → Realisasi
Tukang     : Kerjakan task + Form Harian (sebelum 21.00)
PM         : Tandai milestone selesai → QA: Setujui QA
Marketing  : Invoice termin & pelunasan → Finance verifikasi → Proyek Selesai
```

---

## Data klien contoh

Dipakai di seluruh simulasi:

| Isian | Nilai |
|---|---|
| Nama Klien | Rina Kusuma |
| Kontak | 0812 6677 8899 |
| Kota | Pekanbaru |
| Alamat | Jl. Tuanku Tambusai No. 45, Pekanbaru |
| Link Google Maps | https://maps.app.goo.gl/contohKafeRina |
| Sumber | Instagram |
| Kategori | KOMERSIAL |
| Layanan | Interior Kafe |
| Prioritas | HOT |
| Detail Pesanan | Interior kafe 2 lantai, luas ±120 m², konsep industrial hangat, butuh bar, area duduk 40 kursi dan mural dinding. |

---

## Tahap 1 — Lead masuk & follow-up

### Langkah 1 · Marketing menambah lead
- **Login:** marketing@daikuinterior.com
- **Menu:** Presales → **CRM / Pipeline** → tombol **Tambah Lead**
- **Isi:** data klien contoh di atas; **Pertama Dihubungi** = tanggal hari ini.
- **Hasil:** lead "Rina Kusuma" muncul di Data Lead dengan status
  **Follow Up**. Klik **Lihat Detail** untuk membuka halaman klien.

### Langkah 2 · Marketing mencatat follow-up
- **Halaman:** detail lead Rina Kusuma → kartu **Follow-up** → **Tambah Follow-up**
- **Isi:** jadwal = besok, catatan:
  ```
  Kirim portofolio kafe & tanyakan jadwal survey lokasi.
  ```
- Setelah dihubungi: **Tandai Follow-up Selesai** → **Hasil follow-up**:
  ```
  Klien tertarik, minta survey lokasi minggu ini.
  ```
- **Hasil:** FU-1 tercatat selesai. (Setelah FU ke-4 sistem menyarankan
  menandai Lost — hanya saran, tidak memblokir.)

---

## Tahap 2 — Survey lokasi

### Langkah 3 · Marketing menjadwalkan survey (dalam Pekanbaru = gratis)
- **Halaman:** detail lead → tombol **Ajukan Desain/Survey** → pilih **Jadwalkan Survey**
- **Isi:** Jadwal Survey = lusa pukul 10.00; Alamat Survey dikosongkan
  (memakai alamat lead); **Lokasi di luar Pekanbaru** tidak dicentang.
- **Hasil:** status lead menjadi **Pengajuan Desain/Survey**; survey
  muncul di kartu **Survey**.

### Langkah 4 · Marketing mencatat hasil survey
- **Halaman:** detail lead → kartu Survey → **Tandai Survey Selesai** → **Hasil survey**:
  ```
  Ruang 2 lantai, plafon 3,2 m, listrik perlu tambah daya. Klien setuju lanjut ke desain.
  ```

> **Variasi luar kota:** bila **Lokasi di luar Pekanbaru** dicentang,
> survey menunggu pembayaran. Marketing harus **Buat RAB → RAB Jasa Survey**,
> lalu RAB itu berjalan seperti Tahap 3 (Estimator → PM → klien → invoice →
> Finance). Survey baru berstatus **Siap Berangkat** setelah Finance
> memverifikasi pembayarannya.

---

## Tahap 3 — RAB Jasa Desain

### Langkah 5 · Marketing meminta RAB Jasa Desain
- **Halaman:** detail lead → tombol **Buat RAB** → pilih **RAB Jasa Desain**
- **Catatan untuk Estimator:**
  ```
  Desain interior kafe 2 lantai ±120 m² + render 3D. Klien minta 2x revisi.
  ```
- **Referensi (opsional):** Tambah Link → `https://pinterest.com/contoh-kafe-industrial`
  dan unggah 1–2 foto lokasi.
- **Hasil:** RAB muncul di kartu **Riwayat RAB** (bagian RAB Jasa Desain)
  dengan status **Diminta**; Estimator mendapat notifikasi.

### Langkah 6 · Estimator menyusun RAB
- **Login:** estimator@daikuinterior.com
- **Menu:** Presales → **Quotation** → buka RAB Jasa Desain Rina Kusuma
  → **Mulai Susun RAB**
- Lihat catatan, link dan foto referensi dari Marketing di halaman ini.
- **Isi item:** **Tambah Bagian Pekerjaan** "Jasa Desain", lalu **Tambah Item**:

  | Item | P | T | Volume | Satuan | Harga |
  |---|---|---|---|---|---|
  | Jasa desain interior + render 3D | | | 1 | ls | 4.500.000 |

- **Simpan RAB** → periksa **Skema Pembayaran** (bawaan: 1 baris 100% di
  muka) → **Simpan Skema Pembayaran**.
- **Catatan surat** (opsional, tampil di PDF & link klien) → **Simpan Catatan**.
- Klik **Kirim ke PM**.
- **Hasil:** status **Submitted** (menunggu PM).

### Langkah 7 · PM memeriksa RAB
- **Login:** pm@daikuinterior.com (atau asistenpm@daikuinterior.com)
- **Menu:** Presales → **Quotation** → buka RAB tersebut
- Tandai setiap item **✔ cocok**, lalu **Setujui**.
- **Hasil:** status **Disetujui Internal** (RAB Jasa Desain tidak perlu CEO).

> **Variasi ditolak:** tandai item **✘** + isi catatan → **Kembalikan ke
> Estimator**. RAB kembali ke Draft (versi baru), Estimator memperbaiki
> lalu **Kirim ke PM** lagi.

### Langkah 8 · Estimator meneruskan ke Marketing
- **Login:** Estimator → buka RAB → **Kirim RAB Final ke Marketing**
- **Hasil:** status **Siap Dikirim**; Marketing mendapat notifikasi.

### Langkah 9 · Marketing mengirim ke klien
- **Login:** Marketing → buka RAB (dari Quotation atau Riwayat RAB di detail lead)
- Klik **Kirim ke Klien** → **Salin link klien** (atau **Kirim via WhatsApp**).
- **Unduh PDF** untuk melihat surat penawaran resmi: kop surat, nomor
  surat format `…/OFF/Daiku/X/2026`, tabel RAB, terbilang, catatan, tanda tangan.
- **Hasil:** status **Sent To Client**; masa berlaku 14 hari.

### Langkah 10 · Klien menyetujui lewat link
- **Buka link** di jendela Incognito (tanpa login).
- Klien melihat surat penawaran yang sama dengan PDF, bisa **Unduh PDF**.
- Klik **Setujui Penawaran** → centang *"Saya telah membaca dan menyetujui
  penawaran ini"* → konfirmasi.
- **Hasil:** halaman menampilkan "Telah disetujui pada …"; di sistem status
  RAB **Disetujui Klien**; desain dibuat otomatis dengan status
  **Menunggu Pembayaran**.

### Langkah 11 · Marketing menerbitkan invoice
- **Login:** Marketing → buka RAB Jasa Desain → **Terbitkan Invoice**
  → **Jatuh Tempo** = 3 hari lagi.
- **Unduh PDF** invoice: nomor format `…/INV/Daiku/X/2026` (urutan nomor
  dipakai bersama surat penawaran).
- Setelah klien transfer: **Keuangan → Penagihan → Invoice** → **Kirim
  Bukti Bayar** → **Link bukti transfer**:
  ```
  https://drive.google.com/contoh-bukti-transfer-rina
  ```
- **Hasil:** invoice **Menunggu Verifikasi**; Finance mendapat notifikasi.

### Langkah 12 · Finance memverifikasi pembayaran
- **Login:** finance@daikuinterior.com
- **Menu:** Keuangan → Penagihan → **Verifikasi Pembayaran** → **Verifikasi Pembayaran**
- **Isi:** Rekening penerima = BCA 5835; Tanggal uang masuk = hari ini.
- **Hasil:** invoice **Terverifikasi**, pemasukan tercatat otomatis di
  Transaksi; desain berubah ke **Menunggu Penugasan** dan tim desain
  mendapat notifikasi.

> **Variasi bukti tidak cocok:** **Tolak Pembayaran** + alasan → invoice
> kembali ke Marketing.

---

## Tahap 4 — Desain

### Langkah 13 · Kepala Desain menugaskan arsitek
- **Login:** kepaladesain@daikuinterior.com
- **Menu:** Presales → **Desain** → baris desain Rina Kusuma → **Tugaskan Desain**
- **Isi:** PIC = Designer Daiku Interior (boleh diri sendiri), asisten
  opsional, tanggal mulai = hari ini, target = 14 hari.
- **Hasil:** status desain **Desain**, deadline terhitung otomatis.

### Langkah 14 · Arsitek mengunggah desain
- **Login:** designer@daikuinterior.com
- **Menu:** Desain → buka desain → isi **Link Desain (Drive / Figma)**:
  ```
  https://drive.google.com/contoh-desain-kafe-rina-v1
  ```
- Klik **Simpan Brief**.
- Diskusi dengan Estimator bisa lewat thread di halaman desain (**Kirim Pesan**).

### Langkah 15 · Marketing mengirim desain & mengurus revisi
- **Login:** Marketing → Desain → buka desain → **Kirim Desain ke Klien**
  → status **Waiting Acc Desain**.
- Klien minta perubahan → **Minta Revisi**:
  ```
  Klien minta warna bar diganti kayu jati gelap dan tambah 6 kursi outdoor.
  ```
  → status **Revisi Desain**, revisi ke-1 tercatat.
- Arsitek memperbarui link (`…-v2`) → **Simpan Brief**; Marketing **Kirim
  Desain ke Klien** lagi.
- Klien setuju → **Konfirmasi ACC Klien**.
- **Hasil:** desain **Acc Desain**; sistem otomatis meminta **RAB Proyek**
  ke Estimator (status **Diminta**).

---

## Tahap 5 — RAB Proyek

### Langkah 16 · Estimator menyusun RAB Proyek
- **Login:** Estimator → Quotation → RAB Proyek Rina Kusuma → **Mulai Susun RAB**
- **Isi item** per bagian pekerjaan:

  | Bagian | Item | P | T | Volume | Satuan | Harga |
  |---|---|---|---|---|---|---|
  | Pekerjaan Persiapan | Bongkar plafon & partisi lama | | | 1 | ls | 3.500.000 |
  | Interior | Meja bar HPL motif kayu | 4 | 1,1 | 1 | unit | 18.000.000 |
  | Interior | Partisi rangka besi + kaca | 6 | 2,4 | 14,4 | m² | 950.000 |
  | Interior | Mural dinding | 5 | 3 | 15 | m² | 350.000 |
  | Listrik | Titik lampu gantung | | | 12 | titik | 450.000 |
  | Listrik | Tambah daya & panel | | | 1 | ls | 6.500.000 |

- **Diskon:** 2.000.000 · **Pembulatan:** sesuaikan ke ribuan terdekat → **Simpan RAB**.
- **Skema Pembayaran** (maks. 6 baris) → **Simpan Skema Pembayaran**:

  | Termin | % | Pemicu |
  |---|---|---|
  | DP | 50 | Di muka (saat disetujui) |
  | Termin 2 | 30 | Milestone selesai: *Pemasangan Interior* |
  | Pelunasan | 20 | Proyek selesai |

- **Kirim ke PM**.

### Langkah 17 · PM lalu CEO menyetujui
- **PM:** tandai semua item ✔ → **Setujui** → status **Menunggu CEO**.
- **CEO** (ceo@daikuinterior.com): Quotation → buka RAB → **Setujui**
  → status **Disetujui Internal**.
- CEO tidak bisa memutuskan sebelum PM menyetujui.

> **Variasi CEO menolak:** RAB kembali ke Estimator, dan tercatat di KPI PM
> ("ketelitian review").

### Langkah 18 · Estimator → Marketing → Klien
- Estimator: **Kirim RAB Final ke Marketing**.
- Marketing: **Kirim ke Klien** → **Salin link klien** → kirim ke klien.
- Klien (Incognito): **Setujui Penawaran** + centang → konfirmasi.
- **Hasil:** RAB Proyek **Disetujui Klien**; status lead **Closing**; CEO
  mendapat pop-up **Buka Proyek**.

---

## Tahap 6 — Buka proyek & tagihan DP

### Langkah 19 · CEO membuka proyek
- **Login:** CEO → pop-up **Buka Proyek** muncul (atau menu Eksekusi →
  **Proyek** → bagian **Menunggu Dibuka**).
- **Isi:** Nama Proyek = `Interior Kafe Rina Kusuma`; Project Manager =
  PM Daiku Interior; Asisten PM = Asisten PM Daiku Interior; Tanggal Mulai = hari ini.
- **Hasil:** proyek **Active**; nilai kontrak = total RAB; 3 termin dibuat
  otomatis dari skema pembayaran.

### Langkah 20 · Marketing menagih DP, Finance memverifikasi
- **Marketing:** Proyek → buka proyek → tab **Finance** → termin DP →
  **Terbitkan Invoice** → lalu **Kirim Bukti Bayar** (Keuangan → Penagihan → Invoice).
- **Finance:** **Verifikasi Pembayaran** (seperti Langkah 12).
- **Hasil:** termin DP **Paid**; tab **Alokasi Dana** terbuka untuk PM.

---

## Tahap 7 — Pelaksanaan proyek

### Langkah 21 · PM mengatur alokasi dana
- **Login:** PM → proyek → tab **Alokasi Dana** → **Tambah Pos**:
  `Persiapan`, `Interior`, `Listrik`.
- Masukkan item RAB ke pos masing-masing (bagian **Item Belum Dialokasikan**).
- **Hasil:** Ringkasan Alokasi menunjukkan anggaran per pos; perubahan
  tercatat di **Riwayat Alokasi**.

> Marketing dan Asisten PM **tidak** bisa melihat alokasi dana maupun
> realisasi — coba login sebagai Marketing untuk memastikan tab ini tidak ada.

### Langkah 22 · PM membuat milestone & task
- Tab **Milestone** → **Tambah Milestone** (nama + target tanggal):
  1. `Persiapan & Bongkar` — +7 hari
  2. `Pemasangan Interior` — +30 hari  *(nama sama dengan pemicu Termin 2)*
  3. `Finishing & Serah Terima` — +40 hari
- Tab **Task** → **Tambah Task**:
  - Judul Task: `Bongkar plafon lama lantai 1`
  - Tukang: Field Staff Daiku Interior
  - Milestone: Persiapan & Bongkar
  - Jatuh Tempo: +3 hari · Prioritas: HIGH · Rate per Task: 250.000
- **Hasil:** tukang mendapat notifikasi task baru.

### Langkah 23 · Tukang mengerjakan task (dari HP)
- **Login:** fieldstaff@daikuinterior.com (sebaiknya dari HP / layar kecil)
- **Hari Ini** → kartu task → ubah status ke **Dikerjakan** → Simpan.
- **Form Harian wajib** setiap hari kerja (Senin–Sabtu) **sebelum pukul
  21.00** (pengingat 20.30): **Isi Form Harian** → status + kendala/catatan:
  ```
  Bongkar plafon 60% selesai, besok lanjut sisi bar.
  ```
  Tidak mengisi → penalti otomatis.
- Selesai bekerja → status **Minta dicek** → PM memeriksa lalu mengubah ke **Selesai**.
- **Opsional:** **Ajukan Barang** (nama + jumlah + catatan → ACC PM/Asisten
  PM → Logistik) dan **Pengajuan lembur** (→ PM **Setujui Lembur**).
- Tukang tidak bisa mengubah judul, deskripsi, atau jatuh tempo task.

### Langkah 24 · PM mencatat realisasi biaya
- Tab **Alokasi Dana** → item `Titik lampu gantung` → **Catat Realisasi**:
  Qty Riil = 12, Harga Modal / Satuan = 300.000, Vendor (opsional).
- **Coba overrun:** catat realisasi `Tambah daya & panel` dengan harga modal
  di atas anggaran pos → sistem memblokir dan meminta **Alasan untuk CEO**:
  ```
  Harga panel naik dari supplier, perlu MCB tambahan.
  ```
- **CEO:** **Setujui Overrun** (atau **Tolak Overrun**) → PM bisa lanjut.

---

## Tahap 8 — QA & termin berikutnya

### Langkah 25 · PM menyerahkan milestone ke QA
- Semua task milestone *Persiapan & Bongkar* sudah **Selesai** → tab
  **Milestone** → **Tandai Selesai**.
- **Hasil:** milestone **Qa Waiting**; QA Form dibuat otomatis.

### Langkah 26 · QA memeriksa
- **Login:** qa@daikuinterior.com → menu **QA** → buka QA Form proyek ini
- Isi **Checklist Kualitas** (lulus/tidak + catatan) → **Setujui QA**.
- **Hasil:** milestone **Completed**; milestone berikutnya otomatis berjalan;
  PM mendapat notifikasi.

> **Variasi:** **Tolak QA** → milestone kembali ke PM untuk diperbaiki,
> lalu **Tandai Selesai** lagi. Ditolak ≥ 2× tampil di Dashboard QA.

### Langkah 27 · Termin 2 & pelunasan
- Ulangi Langkah 22–26 untuk milestone **Pemasangan Interior**. Setelah QA
  menyetujuinya, Termin 2 jatuh tempo → Marketing mendapat pengingat →
  **Terbitkan Invoice** → **Kirim Bukti Bayar** → Finance **Verifikasi Pembayaran**.
- Selesaikan milestone **Finishing & Serah Terima** → QA **Setujui QA**.
- **Hasil:** proyek otomatis **Completed** (syarat: semua milestone lolos
  QA, tidak ada sisa material, tidak ada pengajuan barang yang belum
  diputuskan). Termin **Pelunasan** jatuh tempo → invoice → verifikasi Finance.

> **RAB Tambahan (opsional):** klien minta pekerjaan tambah saat proyek
> berjalan → proyek → **Minta RAB Tambahan** → alur sama seperti Tahap 5;
> setelah disetujui klien, nilainya menambah proyek yang sama (bukan proyek baru).

---

## Tahap 9 — Pengecekan akhir

| Cek | Login | Di mana | Yang harus terlihat |
|---|---|---|---|
| Riwayat RAB klien | Marketing / CEO | CRM / Pipeline → detail Rina Kusuma | RAB Jasa Desain & RAB Proyek, masing-masing dengan nomor surat, PDF, link klien |
| Pemasukan | Finance | Keuangan → Cash Flow → Transaksi | Pembayaran jasa desain, DP, termin 2, pelunasan |
| Termin | Finance / CEO | Keuangan → Penagihan → Termin | Semua termin **Paid** |
| Anggaran vs realisasi | PM / CEO / Finance | Proyek → Alokasi Dana | Per pos, termasuk overrun yang disetujui CEO |
| Upah tukang | Finance | Keuangan → Pembayaran Staf → Upah Tukang | Task selesai siap dibayar (**Bayar Upah**) |
| Penalti | Finance / Tukang | Keuangan → Pembayaran Staf → Penalti | Muncul bila Form Harian terlewat |
| Jejak audit | CEO | Eksekutif → Audit Trail | Persetujuan RAB, persetujuan klien, verifikasi invoice, overrun |
| Ringkasan bisnis | CEO | Eksekutif → Analytics | Pipeline, pendapatan, proyek |

---

## Daftar periksa singkat

- [ ] 1. Marketing: Tambah Lead
- [ ] 2. Marketing: follow-up FU-1
- [ ] 3–4. Marketing: jadwalkan & selesaikan survey
- [ ] 5. Marketing: Buat RAB → RAB Jasa Desain
- [ ] 6. Estimator: susun RAB → Kirim ke PM
- [ ] 7. PM: ✔ semua item → Setujui
- [ ] 8. Estimator: Kirim RAB Final ke Marketing
- [ ] 9. Marketing: Kirim ke Klien + Unduh PDF
- [ ] 10. Klien: Setujui Penawaran (link)
- [ ] 11. Marketing: Terbitkan Invoice + Kirim Bukti Bayar
- [ ] 12. Finance: Verifikasi Pembayaran
- [ ] 13. Kepala Desain: Tugaskan Desain
- [ ] 14. Arsitek: unggah link desain
- [ ] 15. Marketing: kirim desain, minta revisi, Konfirmasi ACC Klien
- [ ] 16. Estimator: susun RAB Proyek + skema bayar
- [ ] 17. PM → CEO: Setujui
- [ ] 18. Estimator → Marketing → Klien: Setujui Penawaran
- [ ] 19. CEO: Buka Proyek
- [ ] 20. Marketing/Finance: invoice DP terverifikasi
- [ ] 21. PM: alokasi dana per pos
- [ ] 22. PM: milestone & task
- [ ] 23. Tukang: kerjakan task + Form Harian
- [ ] 24. PM: realisasi + overrun → CEO
- [ ] 25–26. PM: Tandai Selesai → QA: Setujui QA
- [ ] 27. Termin 2, pelunasan, proyek Completed
- [ ] Pengecekan akhir (Tahap 9)
