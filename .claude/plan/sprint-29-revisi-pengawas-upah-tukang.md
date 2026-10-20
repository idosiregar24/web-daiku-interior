# Sprint 29 — Revisi Pengawas, Upah Tukang per Item RAB, Admin Proyek

> Status: **rencana terkunci — siap dikerjakan per sub** (2026-10-20, 4 ronde
> tanya-jawab + "sudah oke"). Belum ada kode. Sub-plan per file di
> [`sprint-29/`](sprint-29/README.md) — perintah: "Kerjakan Sprint 29 Sub 1".
> Sumber: revisi user 2026-10-20 (§1), 3 file Excel proses bisnis (§2),
> jawaban per ronde (§1b), alur kerja (§3), keputusan (§4), kecepatan & UX
> (§5), sisa pertanyaan (§6 — semuanya punya usulan, tidak ada yang menahan).
> Di luar PRD; mengubah keputusan Sprint 24 (D3 syarat TM Selesai, D4 Jasa
> Pengawas → Upah Tukang, D5 syarat DP, D6/D9 daily target & geser, D10
> laporan mingguan, D11 progres, D14 satu Pengawas, E4/E5 upah & bobot),
> Sprint 27 (P1, P3) dan menambah role non-PRD **Admin Proyek**.
>
> **Hubungan dengan Sprint 28** (rencana, belum dikerjakan): K2 (antrean
> "minta dicek" untuk **PM**) diganti F5 di sini; K13 (tolak geser ke Minggu)
> tetap berlaku. Kerjakan Sprint 28 dulu, kecuali K2.

## 1. Revisi user (2026-10-20, utuh)

```
- Untuk pengawas bisa lebih dari 1
- Jasa Pengawas -> Upah tukang
- Langkah 3 dan 4 diselesai TM
- Stok barang tidak usah di tampilkan di pengawas
- Penukaran task per timeline bersifat bebas
- pemberian upah disusun pada saat penyusunan bahan
- QA Form harus berdasarkan per item yang dilihat dari RAB
- Pengecekan Daily target bersifat approval saja untuk pengawas -> jika ada
  task yang perlu diperbaiki task bisa dikembalikan ke tukang
- Form pengecekan mingguan boleh di isi sebelum jumat
- QC Juga bisa ngisi status task
- Tiap Karyawan bisa input daily activity (Custom saja)
- KPI
- Pelajarin Per RAB
- Ada perubahan di bagian Pengawas, jadi pengawaslah yang nentuin berapa upah
  (untuk perhitungan upah ikutin template di file simulasi presentasi) jadi
  pada saat penginputan nilai jasa oleh pengawas, di sebelahnya ada RAB dan
  harga per itemnya, kemudian dia inputkan nilai jasa nya jadi si pengawas
  nanti masukin berapa jasa yang akan digunakan untuk membuat item itu,
  truss nanti akan dihitung nilai dan bobotnya, nah nanti nilainya di
  persenannya digunakan di bagian penggajian oleh si kepala desain. Nah nanti
  kepala desain dapat hasil berapa nominal yang bisa di cairkan per minggu
  berdasarkan persentase progress yang dikerjakan.
- Nah untuk pembagian upah yang bisa di cairkan itu ada perhitungannya.
```

Tambahan ronde 5 (2026-10-20): "Untuk proses tambah barang yang akan digunakan
dari Logistik, pastikan form-nya tidak menunggu loading agar tidak lama. Untuk
semua perubahan pastikan UX-nya dapat. Lihat bagian-bagian mana yang apabila
diisi butuh loading dan mengganggu kecepatan bekerja." → §5.

### 1b. Jawaban user (2026-10-20)

| Ronde | Topik | Jawaban (inti) |
|---|---|---|
| 1 | TM (langkah 3–4) | "Fase untuk upah dan daily target biarkan Pengawas saja, Pengawas yang isi bagian itu dan TM bisa dibuat statusnya selesai" |
| 1 | "Kepala desain" | = **Kepala Tim dari tukang** |
| 1 | Rumus | "Pelajari excel ini" → `sprint-29/` |
| 2 | Kepala Tim | Satu proyek bisa beberapa Kepala Tim ("bisa jadi") |
| 2 | Nominal diajukan | "Berapa progress yang sudah dikerjakan dan dihitung berdasarkan upah itu" |
| 2 | Sel 22% di Penawaran | Abaikan — format RAB sistem sudah cocok |
| 2 | Progres proyek | Progres berbobot Excel ("Iya") |
| 3 | Pencairan | **PM menyetujui** pencairan upah; **ada DP upah**; Pengawas yang mengajukan dan menyerahkan uang ke Kepala Tim |
| 3 | Siapa mengisi upah & daily target | Hanya Pengawas; lainnya "berikan hak akses saja" |
| 3 | Bukti serah ke Kepala Tim | Tidak wajib |
| 3 | Pengawas > 1 | Setara; Pengawas tambahan opsional |
| 3 | Geser/tukar task | "Bebas tanpa harus di timeline yang ditentukan untuk task tersebut" |
| 3 | Daily activity | Dibaca CEO, PM, HR |
| 3 | "Pelajarin per RAB" | = persentase progres per item RAB ("Ya") |
| 4 | Jasa Pengawas → Upah Tukang | **"Itu penamaannya saja yang diganti"** — alur Jasa Pengawas tetap; satu proyek punya jumlah upah tukang **per item** |
| 4 | Waktu isi upah & daily target | "Tidak harus di hari H atau di saat TM" |
| 4 | Daily target | Tukang/Kepala Tim gaptek → **Pengawas bisa mencentang task selesai**; tukang yang bisa pakai HP tetap bisa klik Selesai; pengecekan harian Pengawas = approval input tukang, atau Pengawas sendiri yang menandai selesai |
| 4 | **Admin Proyek** (baru) | "Seorang admin yang mengurus semua proses khusus di bagian projek saja, masalah penginputan dll" |
| 4 | Bahan (baru) | Bahan yang dimasukkan Pengawas **bisa custom**, diajukan ke Logistik untuk dipesan, dan **kelihatan statusnya sudah sampai mana** |
| 4 | QA | Setuju **QA per item RAB** (opsi b) |
| 4 | KPI | "Akan dicek oleh HR untuk melihat task yang diajukan di kemarin hari" |
| 4 | DP upah | **10%**, ditentukan **PM**; **DP yang disetujui = tanda Start Produksi** |
| 5 | Rencana | "Sudah oke" → dipecah jadi sub-plan |
| 5 | Kecepatan | Form tambah barang Logistik tanpa menunggu loading; UX semua perubahan; audit bagian yang lambat → §5 |

## 2. Pelajaran dari 3 file Excel (`sprint-29/`)

### File A — `Penawaran Bang Binsar.xlsx` (RAB + upah)

1. **RAB klien**: Item · P · T/L · Volume · Satuan · Harga · Subtotal. Volume
   mengikuti aturan sistem (m/lari = P, m² = P × T/L — `qty_source`).
2. **Tabel upah** (Dapur Bersih): kolom yang sama + **Satuan Upah** (diketik)
   dan **Subtotal Upah = satuan upah × volume**.

   | Item | Volume | Subtotal RAB | Satuan upah | Subtotal upah |
   |---|---|---|---|---|
   | Kabinet bawah | 3,16 m/lari | 10.428.000 | 500.000 | 1.580.000 |
   | Kabinet atas kanan | 2,45 m² | 6.982.500 | 500.000 | 1.225.000 |
   | Kabinet atas kiri | 1,75 m/lari | 4.987.500 | 500.000 | 875.000 |
   | Cover kulkas | 4,25 m² | 12.112.500 | 300.000 | 1.275.000 |
   | Cover atas HPL | 4,4 m² | 7.920.000 | 300.000 | 1.320.000 |
   | Meja bar | 1,3 m/lari | 4.940.000 | 1.100.000 | 1.430.000 |
   | Rak piring, LED, power supply, saklar | — | — | *kosong* | 0 |
   | **Total upah** | | | | **7.705.000** |

   Barang jadi tanpa upah. Penerima ditulis "Putra". (Sel tanpa label
   `Σ × 22%` — diabaikan, jawaban user.)

### File B — `PROGRES PERSENTAS Bulan AGUSTUS 28-2.xlsx` (pengecekan)

Per area: Uraian · Volume · Satuan · **Vendor** (= Kepala Tim: Dapur Bersih →
PUTRA, Dapur Kotor → YUSUF) · **Skore** · **CEK 1 … CEK 5** (tanggal di header:
03 Jul, 10 Jul, 14 Agu, 28 Agu — tidak tiap Jumat) · Skore Akhir · Persentasi.

- Skore = subtotal RAB item dalam juta, **hanya item berupah**.
- CEK n = **tambahan %** item pada pengecekan itu (diisi Pengawas); Persentasi
  item = Σ CEK.
- Persentase Vendor = Σ (Skore × %) ÷ Σ Skore → PUTRA **69,48 %**, YUSUF **87,14 %**.

### File C — `SIMULASI PERSENTASI.xlsx` (simulasi harian)

- `Harga = Nilai × 1.000.000`, **`Bobot = Nilai ÷ ΣNilai × 100`**; kolom per hari
  = % yang ditambahkan; Σ (progres × bobot) = progres proyek **67,70 %**.
- **Sel biru muda = jadwal kerja per item** (dari warna sel): tiap item blok
  4 hari berurutan (2–5 Okt, 3–6, 4–7 … 16–19 Okt). Item baris 4–14 punya blok
  kedua di akhir Oktober — Q26.
- Header kuning "DP + Marking 1" (25–29 Sep) dan hijau "Marking 2" (30 Sep –
  2 Okt), **sebelum** pekerjaan pertama — Q27.
- Pengecekan diisi 9, 14, 20, 25 Okt (± seminggu sekali).

### File D — `Upah Tim Interior DAIKU.xlsx` (pembagian upah di dalam tim)

Ditambahkan user 2026-10-20 (ronde 5). Menjawab "pembagian upah yang bisa
dicairkan itu ada perhitungannya".

**Sheet "Master Persentasi"** — bobot default per **tingkatan karir**:

| Tingkatan | Bobot |
|---|---|
| Ketua Tim | 24 |
| Tukang 1 | 17 |
| Tukang 2 | 15 |
| Helper 1 | 9 |
| Helper 2 | 7 |
| Perusahaan | 5 |

Lalu per tim, anggota + bobotnya (boleh berbeda dari master — Tim Putra
"T. Tukang 2" = 14, Tim Evan Tukang 1 = 18, Helper 1 = 10/11), dan
**Persentase orang = bobot ÷ Σ bobot tim × 100**:

| Tim Putra (Σ 77) | Bobot | % | Tim Evan (Σ 72) | Bobot | % | Tim Yusup (Σ 76) | Bobot | % |
|---|---|---|---|---|---|---|---|---|
| Ketua Tim | 24 | 31,17 | Ketua Tim | 24 | 33,33 | Ketua Tim | 24 | 31,58 |
| Tukang 1 | 17 | 22,08 | Tukang 1 | 18 | 25,00 | Tukang 1 | 18 | 23,68 |
| Tukang 1 | 17 | 22,08 | Tukang 2 | 15 | 20,83 | Helper 1 | 11 | 14,47 |
| T. Tukang 2 | 14 | 18,18 | Helper 1 | 10 | 13,89 | Helper 1 | 11 | 14,47 |
| Perusahaan | 5 | 6,49 | Perusahaan | 5 | 6,94 | Helper 2 | 7 | 9,21 |
| | | | | | | Perusahaan | 5 | 6,58 |

**Sheet "Persentase Upah Projek"** — per tim: **Upah** (Tim Putra
**Rp 7.705.000** = total upah File A), kolom **DP · Termin 1 … Termin 8**
berisi **% dari total upah** yang cair di tiap pencairan, dan **Sisa**:

```
Bagian orang di satu pencairan = Upah tim × % pencairan × % orang
Tim Putra: DP 10 % = 770.500 · Termin 1 13 % = 1.001.650 · Termin 2 22 % = 1.695.100
           · Termin 3 22 % = 1.695.100 · Sisa 33 % = 2.542.650
Contoh DP: Ketua Tim 770.500 × 31,17 % = 240.156 · Tukang 1 170.110 · Tukang 1 170.110
           · Tukang 2 140.091 · Perusahaan 50.032
```

Cocok dengan rumus pencairan (%-nya = tambahan progres yang dicairkan) dan
DP 10 % = Rp 770.500. **Perusahaan** ikut mendapat bagian (bobot 5) — Q34.
Upah Tim Evan & Tim Yusup diketik Rp 10.000.000 (contoh, DP saja).

### Rumus yang dikunci

```
Item berupah     : item RAB dengan satuan upah > 0
Subtotal upah_i  = satuan upah_i × volume_i                              (File A)
Total upah       = Σ subtotal upah_i            → "Nilai Upah Tukang" proyek (dulu nilai jasa pengawas)
Bobot_i          = subtotal RAB_i ÷ Σ subtotal RAB item berupah × 100     (File B/C)
Progres_i        = Σ % pengecekan item i  (0–100, diisi Pengawas)
Progres proyek   = Σ (Bobot_i × Progres_i) ÷ 100                          → daftar, 70 %, dashboard
Upah boleh cair  = Σ (subtotal upah_i × Progres_i ÷ 100)                  → nominal pengajuan
Cair kali ini    = upah boleh cair − yang sudah diajukan/dibayar (DP + pencairan, kecuali ditolak)
DP upah          = 10 % × total upah (PM boleh mengubah)
```

Contoh Putra: progres 69,48 %; upah boleh cair = 70 % × 6.275.000 + 65 % ×
1.430.000 = **Rp 5.322.000**; dengan DP 10 % (Rp 770.500) sudah dibayar →
cair kali ini Rp 4.551.500 (bila belum ada pencairan lain).

## 3. Alur kerja yang ditangkap

```
0. Buka Proyek (PM yang ACC RAB) ── PM · Kepala Tim · Pengawas (≥1, setara) · tanggal
1. GAMBAR_KERJA ── Kepala Desain → Arsitek → "Gambar Kerja Selesai"
2. TM (PM) ── jadwal & notulen · peserta · timeline · Tanggal Selesai → "TM Selesai"
              (tidak menunggu upah / bahan / daily target)
3. PERSIAPAN (Pengawas — atau Admin Proyek; tidak harus di hari TM)
   ├ Bahan ........ katalog atau custom (tanpa melihat stok) → "Ajukan ke Logistik"
   ├ Upah Tukang .. tabel item RAB + harga → isi satuan upah → subtotal, bobot, total;
   │                Kepala Tim per item / bagian
   ├ Daily target . task per tukang per hari
   └ Ajukan DP Upah (10 %) ─▶ PM setujui (nominal bisa diubah) = START PRODUKSI
                     ─▶ Finance bayar ke Pengawas ─▶ Pengawas serahkan ke Kepala Tim
4. PRODUKSI
   ├ Logistik: pengajuan bahan → disetujui → dipesan → diterima (status terlihat Pengawas/PM)
   ├ Daily target: tukang klik "Selesai" di HP → Pengawas Setujui / Kembalikan
   │               ATAU Pengawas langsung mencentang selesai (tukang gaptek)
   │               Geser/tukar tanggal bebas
   ├ Pengecekan (± mingguan, boleh sebelum Jumat): % per item RAB
   │   → progres item & proyek → upah boleh cair − sudah cair
   │   → Ajukan ─▶ PM setujui ─▶ Finance bayar ke Pengawas ─▶ Kepala Tim
   └ Progres berbobot ≥ 70 % ─▶ PM "Setujui Berangkat ke Lokasi"
5. PEMASANGAN ── pengecekan lanjut · temuan finishing · QA per item RAB
6. SELESAI ── item 100 % + QA lolos → pengajuan terakhir (pelunasan) → COMPLETED

Sepanjang: karyawan (selain tukang) menulis Aktivitas Harian (teks bebas)
           → CEO, PM, HR membaca; HR memeriksa aktivitas kemarin → KPI.
```

## 4. Keputusan

### A — Banyak Pengawas

| # | Keputusan |
|---|---|
| A1 | Tabel `project_supervisors` (`project_id`, `user_id`, `assigned_by`, timestamps, UNIQUE pasangan). Data lama: `supervisor_id` → satu baris pivot, lalu kolom dihapus (`down()` mengembalikan pengawas pertama). |
| A2 | `Project::isSupervisedBy()` / `scopeSupervisedBy()` membaca pivot — Policy, antrean, notifikasi ikut. Semua setara. |
| A3 | Buka / Ubah Proyek: Pengawas utama wajib, tambahan opsional (multi-select user role QA); notifikasi ditugaskan/dicopot per orang. |
| A4 | Aksi tercatat atas nama yang menekan. Pengecekan tetap satu per proyek per minggu, siapa saja di antara Pengawas (Q23). |

### B — Upah Tukang — ganti nama + upah per item

Alur Jasa Pengawas Sprint 24 (`supervisor_fees`, `supervisor_payments`: DP /
MINGGUAN / PELUNASAN, DIAJUKAN → DISETUJUI_PM / DITOLAK → DIBAYAR, Finance,
append-only) **dipakai terus**; yang berubah nama dan asal nilainya.

| # | Keputusan |
|---|---|
| B1 | **Nama**: "Jasa Pengawas" → **"Upah Tukang"** di semua layar, notifikasi, antrean Finance ("Bayar Upah Tukang: DP · Proyek X"), label kategori transaksi. Nilai kode `JASA_PENGAWAS` / nama tabel dipertahankan (riwayat append-only) — hanya label. |
| B2 | **Nilai total = Σ subtotal upah per item** (tidak lagi diketik PM di TM). Tabel baru `project_work_items` (`project_id`, `quotation_item_id` — RAB Proyek + RAB Tambahan disetujui klien, `team_lead_id` / `team_lead_name`, `unit_wage`, `set_by`, timestamps), dibuat otomatis saat Buka Proyek / RAB Tambahan disetujui. Subtotal, bobot, total dihitung di satu service. |
| B3 | **Kartu "Upah Tukang"** (Pengawas / Admin Proyek): tabel item RAB per bagian seperti File A — uraian · volume · satuan · **harga RAB** · subtotal RAB · **Satuan Upah** (`MoneyInput`) · subtotal upah · bobot · Kepala Tim. Bisa diisi sejak TM. **Membuka harga RAB ke Pengawas** (Sprint 24 U4 sengaja tanpa harga) — diminta user, dicatat di `security-standards.md`. |
| B4 | **DP upah**: Pengawas "Ajukan DP Upah" (default **10 %** dari total upah) → **PM menyetujui** (boleh mengubah nominal) = **Start Produksi** → Finance membayar ke Pengawas. |
| B5 | **Pencairan berikutnya**: dari pengecekan % per item (C1) → nominal usulan = upah boleh cair − sudah cair; **PM menyetujui** (boleh menurunkan) / menolak → Finance. **Pelunasan** = pengajuan terakhir saat semua item 100 %. Tidak pernah melebihi total upah (`ensureWithinFee()`, kunci baris proyek). |
| B6 | Penerima = Pengawas yang mengajukan; Pengawas menyerahkan ke Kepala Tim di luar sistem. Pengajuan memuat rincian per Kepala Tim. Bukti serah opsional. |
| B7 | **Progres proyek** = progres berbobot di `Project::actualProgress()` — daftar proyek, 70 % Berangkat ke Lokasi, dashboard, H-30 %. Actual activity tetap di tab Activity Target & penalti. Proyek tanpa item berupah (proyek lama) tetap actual activity. Golden rule #17 diperbarui. |
| B8 | `tasks.rate_per_task` & Pembayaran Staf per task tidak dipakai untuk proyek baru — Q14d. |
| B9 | **Pembagian upah di dalam tim** (File D): master **Tingkatan Karir** (Ketua Tim 24 · Tukang 1 17 · Tukang 2 15 · Helper 1 9 · Helper 2 7 · Perusahaan 5, bisa diubah di ⚙ Pengaturan) + **Tim Tukang** (Kepala Tim + anggota: tukang ber-akun atau nama, tingkatan, bobot — default dari master, boleh diubah per orang). Setiap DP/pencairan menampilkan **rincian per orang** = nominal × bobot orang ÷ Σ bobot tim. Uang tetap dibayar ke Pengawas (B6); rincian jadi pegangan Pengawas/Kepala Tim saat membagi + tercatat di pengajuan. Sub 08b. |

### C — Pengecekan % per item

| # | Keputusan |
|---|---|
| C1 | Laporan mingguan berisi **tambahan % per item RAB** (`work_item_progress`: item, tanggal cek, persen, pencatat, laporan; append-only, Σ per item 0–100) + catatan/foto. Tampil langsung: progres item, progres proyek, upah boleh cair, cair kali ini. Mengirim pengecekan = mengajukan pencairan (B5). |
| C2 | Boleh diisi kapan saja selama PRODUKSI/PEMASANGAN ("paling lambat Jumat"); "Giliran Anda" sejak Senin, warning di hari Jumat; pengingat Jumat 15:00 tetap. |

### D — TM

| # | Keputusan |
|---|---|
| D1 | "TM Selesai" hanya mensyaratkan bagian PM: tanggal & notulen, ≥1 peserta, ≥1 timeline, Tanggal Selesai proyek. |
| D2 | Langkah 3 kartu TM dipecah: Tanggal Selesai tetap di PM; Upah Tukang & Bahan jadi bagian Pengawas, boleh diisi kapan saja sampai Start Produksi. |
| D3 | Syarat DP (kartu Syarat Sprint 27): bahan ≥ 1 · item berupah ≥ 1 · tiap timeline punya daily target. |

### E — Bahan: custom, diajukan ke Logistik, status terlihat

| # | Keputusan |
|---|---|
| E1 | Pengawas tidak menerima `stock` (server tidak mengirim); sumber GUDANG / PEMBELIAN ditentukan server / Logistik. |
| E2 | Planner bahan: katalog **atau "Bahan lain (custom)"** — nama, spesifikasi, jumlah, satuan, foto/link (`MaterialRequestService`, `source = CUSTOM`). |
| E3 | Rencana dikirim sekaligus: **"Ajukan ke Logistik"**. Logistik: katalog gudang → dikeluarkan; pembelian/custom → disetujui/ditolak → **Dipesan** (baru: `ordered_at`, `ordered_by`, vendor, perkiraan tiba) → **Diterima** sebagian/lengkap. |
| E4 | **Status per baris**: Direncanakan · Diajukan · Disetujui / Ditolak (alasan) · Dipesan (perkiraan tiba) · Diterima sebagian · Lengkap · Dikeluarkan dari gudang — satu fungsi status bersama (`materialArrival()` diperluas). |
| E5 | Pengawas boleh mengubah/menghapus sampai **Diajukan**; sesudahnya lewat Logistik. |

### F — Daily target: approval atau centang Pengawas

| # | Keputusan |
|---|---|
| F1 | Tukang (HP): **"Selesai"** → `PENGECEKAN` ("Menunggu dicek Pengawas"); laporan hari itu tercatat. |
| F2 | Pengawas / Admin Proyek / PM: **Setujui** (→ `DONE`, `completed_at` = saat tukang menandai) atau **Kembalikan** (catatan wajib → `ONPROGRESS`, notifikasi tukang). |
| F3 | Pengawas bisa **langsung mencentang selesai** (tukang gaptek) → `DONE`; dihitung sebagai laporan tukang hari itu (pencatat = Pengawas) → tidak kena penalti (Q30). |
| F4 | Centang cepat: Daily Target › Per Hari / Per Tukang — kotak centang per target + "Setujui semua yang menunggu" per tukang per hari. |
| F5 | Antrean **"Daily target menunggu dicek"** untuk Pengawas (pengganti Sprint 28 K2); chip `PENGECEKAN` → "menunggu Pengawas (QA/QC)". |
| F6 | Menu **Tugas** untuk QA (proyek awasannya) dan Admin Proyek. |
| F7 | Geser & Tukar Hari bebas, tidak dibatasi timeline (Minggu tetap ditolak); task pindah ke timeline di tanggal barunya (Q29). |

### G — QA per item RAB

| # | Keputusan |
|---|---|
| G1 | Item yang mencapai 100 % di pengecekan masuk antrean QA Pengawas → **Lolos** / **Perlu perbaikan** (catatan, "Jadikan Task Perbaikan"). |
| G2 | Proyek selesai: semua item berupah 100 % + Lolos QA, tanpa temuan terbuka. QA timeline lama tetap untuk proyek lama. |
| G3 | "Tandai Selesai" timeline tidak lagi memicu form QA untuk proyek baru (Q31). |

### H — Admin Proyek

| # | Keputusan |
|---|---|
| H1 | Role non-PRD **`ADMIN_PROYEK`** ("Admin Proyek"): semua proyek, semua tab kecuali Keuangan klien & anggaran PM. |
| H2 | **Boleh menginput atas nama** PM/Pengawas: TM (jadwal, notulen, peserta, timeline), bahan & pengajuan ke Logistik, upah per item, Kepala Tim, daily target, status & centang task, pengecekan %, temuan, catatan progres. Audit atas nama Admin Proyek. |
| H3 | **Tidak boleh memutuskan**: TM Selesai, setujui DP/pencairan, Berangkat ke Lokasi, Lolos QA, Buka Proyek, RAB, pembayaran. |
| H4 | = "hak akses" ronde 3 — tidak ada pemberian akses per orang. |

### I — Aktivitas Harian & KPI

| # | Keputusan |
|---|---|
| I1 | `daily_activities` (`user_id`, `date`, `description`, opsional `project_id`). Semua karyawan selain Tukang menulis di **"Aktivitas Harian"**; ubah di hari yang sama. Dibaca **CEO, PM, HR**. |
| I2 | HR **"Periksa Aktivitas"** (default kemarin): Sesuai / Perlu klarifikasi (catatan); tidak mengisi → "Tidak mengisi". |
| I3 | Indikator KPI **Otomatis** baru: "% hari kerja mengisi aktivitas harian", "% aktivitas sesuai". Rumus KPI tidak berubah. |

## 5. Kecepatan & UX (ronde 5)

### 5a. Bagian yang butuh loading dan mengganggu kecepatan (audit kode 2026-10-20)

**Akar masalah.** Hampir semua simpan di **Detail Proyek** memakai
`router.post/put/patch` biasa → server `back()` → **seluruh ±40 prop halaman
dihitung ulang** (`ProjectController::show`: `execution` overview,
`activityTarget`, `budget` overview, semua `tasks`, `termins`, `documents`,
`projectMaterials`, **seluruh katalog material dua kali** — `materialOptions`
dengan stok & harga + `catalogOptions` — dst.) dan dikirim lagi ke browser.
Selama itu muncul "Menyimpan…" (> 300 ms), lalu pop-up sukses 3 detik.

| # | Bagian | Yang terjadi sekarang | Dampak |
|---|---|---|---|
| L1 | **Tambah kebutuhan bahan** (`ProjectMaterialsPanel`, dipakai Logistik/PM) | Satu bahan per buka dialog; Simpan → tunggu reload penuh → dialog tertutup → pop-up sukses; tambah bahan berikutnya = buka dialog lagi | **Paling lambat** — yang dikeluhkan user |
| L2 | Rencana bahan TM (`TmMaterialPlanner`, Pengawas) | Sudah tanpa pop-up (`quietSuccess`), tetapi tiap Enter = reload penuh; baris baru baru muncul setelah server selesai | Mengetik beruntun tersendat |
| L3 | **Centang Selesai tukang** (Hari Ini, Tugas, `TaskActionSheet`) | Reload penuh per ketukan, tanpa tampilan langsung; di HP sinyal lemah terasa macet, rawan ketuk dua kali | Tukang di lapangan |
| L4 | Buat daily target (`TaskFormDialog`) | Satu target per buka dialog, reload penuh Detail Proyek | Menyusun target seminggu × beberapa tukang = puluhan kali tunggu |
| L5 | Geser target (`TaskWeekBoard`) | Kartu kembali ke tempat lama sampai reload penuh selesai | Terlihat "gagal" |
| L6 | Kartu TM (notulen, peserta, timeline) | Reload penuh per simpan (`preserveState`) | Sedang |
| L7 | Ubah status task PM (`TaskStatusDialog`) | Reload penuh + pop-up | Sedang |
| L8 | Laporan mingguan / temuan | Reload penuh + pop-up | Rendah (jarang) |
| L9 | Halaman Logistik (pengajuan barang, stok masuk/keluar) | Reload halaman daftar + pop-up sukses tiap aksi | Kerja beruntun Logistik terpotong pop-up |
| L10 | Pop-up sukses 3 detik (`SuccessDialog`) di semua simpan | Cocok untuk keputusan penting, mengganggu untuk input berulang | Semua form isian berulang |

### 5b. Pola perbaikan (dipakai semua sub)

| # | Pola |
|---|---|
| K1 | **Server hanya menghitung yang diminta**: prop berat `Projects/Show` dibungkus closure (`fn () => …`) dan katalog material jadi `Inertia::optional()` (dimuat sekali saat form bahan pertama dibuka). Dengan `only`, prop lain tidak dihitung sama sekali. |
| K2 | **Simpan memuat ulang sebagian**: tiap simpan mengirim `only: [prop yang berubah]` (helper `projectReload(['projectMaterials', 'execution'])` + `flash`/`navBadges`) → "Menyimpan…" global tidak muncul (dianggap latar), cukup spinner di tombol. |
| K3 | **Tampil langsung (optimistic)** untuk aksi kecil yang aman: centang selesai, geser kartu, tambah baris bahan — baris langsung tampil dengan tanda "menyimpan…", kembali + pesan bila server menolak. |
| K4 | **Form isian berulang tidak tertutup**: "Simpan & tambah lagi" (default untuk bahan & daily target), fokus kembali ke kolom pertama, Enter = simpan, tanpa pop-up sukses (`quietSuccess` + tanda kecil "Tersimpan ✓"). Pop-up sukses hanya untuk keputusan (setujui, ajukan, selesai). |
| K5 | **Input banyak sekaligus**: bahan — beberapa baris lalu satu "Simpan semua"; daily target — satu target untuk beberapa hari/tukang sekaligus (satu request). |
| K6 | **Tombol terkunci saat menyimpan** (cegah klik ganda), spinner di tombol, bukan layar penuh. |
| K7 | **Ukur**: durasi request sebelum/sesudah dengan Telescope (data demo). Target simpan di Detail Proyek **< 300 ms** (overlay tidak pernah muncul), centang tukang terasa instan. |

### 5c. Standar UX untuk setiap sub (checklist "[UX]" di tiap file sub)

1. **Tanpa menunggu** — pola K1–K6 untuk setiap form baru/diubah di sub itu.
2. **Satu langkah berikutnya jelas** — `PageHeader step`, chip `StatusChip domain` + pemegang giliran (`waitingOn`), antrean Perlu Tindakan untuk giliran baru.
3. **HP dulu untuk lapangan** (Pengawas, Tukang, Logistik): uji 390 px, `ResponsiveDialogContent`, tombol ≥ 48 px, kartu `mobileCard`.
4. **Bahasa membumi** — label Indonesia, "Upah Tukang" bukan "fee", "Menunggu dicek Pengawas" bukan "PENGECEKAN".
5. **Aman** — `ConfirmDialog` untuk yang tidak bisa dibatalkan, `<FormLabel required>`, `MoneyInput` untuk Rupiah, pesan error di kolomnya.
6. **Kosong & salah** — `EmptyState` saat belum ada data, `Notice` yang menjelaskan apa yang kurang + tautan ke tempat memperbaikinya.
7. **Uji browser** per role yang terkena (desktop + HP) sebelum sub dinyatakan selesai.

## 6. Pertanyaan tersisa (dipakai usulan bila tidak dijawab)

| # | Pertanyaan | Usulan |
|---|---|---|
| Q14d | Upah per task (`rate_per_task`) berhenti dipakai untuk proyek baru? | Ya |
| Q23 | Pengecekan % per item lebih dari sekali per minggu? | Satu per minggu |
| Q26 | Blok biru muda kedua di SIMULASI — jadwal pemasangan? Jadwal per item RAB di sistem? | Belum — lewat timeline & daily target |
| Q27 | "Marking" — periode pencairan atau penandaan di lokasi? | Tidak memengaruhi sistem |
| Q29 | Task digeser ke luar timeline-nya pindah ke timeline tanggal barunya? | Ya |
| Q30 | Centang Pengawas = laporan tukang hari itu (tanpa penalti)? | Ya |
| Q31 | "Tandai Selesai" timeline tidak lagi memicu form QA (proyek baru)? | Ya |
| Q32 | Admin Proyek semua proyek, batas H2/H3, akun dibuat CEO/SUPERADMIN? | Ya |
| Q33 | KPI "% hari mengisi" & "% sesuai" dari pemeriksaan HR? | Ya |
| Q34 | Bagian **"Perusahaan"** (bobot 5, ±6,5 %) di File D: tetap di kas perusahaan (tidak dibayarkan), atau ikut dibayar? | Tetap di perusahaan — Finance membayar bagian tim saja; bagian perusahaan tercatat di rincian |
| Q35 | Anggota tim = akun tukang di sistem, atau cukup nama? | Keduanya (pilih akun atau ketik nama), seperti Kepala Tim |
| Q36 | Tim (Tim Putra, Tim Evan, Tim Yusup) tetap lintas proyek, atau disusun ulang per proyek? | Tim tersimpan & dipakai ulang; anggota/bobot bisa diubah per proyek |

## 7. Sub-plan

File per sub di [`sprint-29/`](sprint-29/README.md). Satu perintah = satu sub.

| # | Sub | File |
|---|---|---|
| 01 | Kecepatan simpan Detail Proyek + form bahan tanpa menunggu | [01-kecepatan-simpan.md](sprint-29/01-kecepatan-simpan.md) |
| 02 | Pengecekan mingguan sebelum Jumat | [02-pengecekan-sebelum-jumat.md](sprint-29/02-pengecekan-sebelum-jumat.md) |
| 03 | TM Selesai tanpa bagian Pengawas | [03-tm-selesai.md](sprint-29/03-tm-selesai.md) |
| 04 | Daily target: approval & centang Pengawas | [04-daily-target-pengawas.md](sprint-29/04-daily-target-pengawas.md) |
| 05 | Geser & tukar task bebas | [05-geser-bebas.md](sprint-29/05-geser-bebas.md) |
| 06 | Banyak Pengawas | [06-banyak-pengawas.md](sprint-29/06-banyak-pengawas.md) |
| 07 | Admin Proyek | [07-admin-proyek.md](sprint-29/07-admin-proyek.md) |
| 08 | Upah Tukang per item RAB | [08-upah-tukang.md](sprint-29/08-upah-tukang.md) |
| 08b | Tim Tukang & pembagian upah per orang (File D) | [08b-tim-tukang-pembagian-upah.md](sprint-29/08b-tim-tukang-pembagian-upah.md) |
| 09 | DP & pencairan upah dari pengecekan % | [09-dp-pencairan-upah.md](sprint-29/09-dp-pencairan-upah.md) |
| 10 | Progres proyek berbobot | [10-progres-berbobot.md](sprint-29/10-progres-berbobot.md) |
| 11 | Bahan custom → Logistik + status pemesanan | [11-bahan-logistik-status.md](sprint-29/11-bahan-logistik-status.md) |
| 12 | QA per item RAB | [12-qa-per-item.md](sprint-29/12-qa-per-item.md) |
| 13 | Aktivitas Harian & pemeriksaan HR | [13-aktivitas-harian.md](sprint-29/13-aktivitas-harian.md) |
| 14 | KPI dari Aktivitas Harian | [14-kpi-aktivitas.md](sprint-29/14-kpi-aktivitas.md) |
| 15 | Uji alur, kecepatan ulang & dokumen | [15-uji-dokumen.md](sprint-29/15-uji-dokumen.md) |

**Urutan & ketergantungan.** 01 dulu (semua sub berikutnya memakai pola
simpannya). 02–05 berdiri sendiri. 06 sebelum 07–09 (Policy Pengawas). 08 →
08b → 09 → 10 → 12 (item RAB → tim & pembagian → pencairan → progres → QA).
11, 13 → 14 kapan saja setelah 01.
