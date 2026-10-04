# Sprint 11 — Approval Quotation oleh PM · Master Satuan · Material Gudang & Custom

> Status: **keputusan dikunci 2026-10-04 (kecuali 2 pertanyaan tertunda T1/T2
> di §6), belum ada kode.** Permintaan user 2026-10-04. Sprint ini
> independen dari Sprint 10 (SDM) — bisa dikerjakan lebih dulu.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`.
> Ketiga fitur ini **menyimpang dari PRD** (§4.3/§6.2/§7.1 dual approval,
> §4.8 material) — setiap penyimpangan dicatat di `plan/README.md`
> "Catatan penyesuaian", dan `rules/security-standards.md` §4 (contoh
> "CEO lalu PM") ikut diperbarui.

Permintaan:
1. Quotation dari Estimator **cukup di-ACC PM** (tidak lagi CEO → PM).
2. Ada **master satuan barang**.
3. Material proyek ada **dua jenis**: dari Logistik (stok gudang) dan
   **Custom** (barang yang tidak ada di Logistik). Logistik berfungsi
   seperti **toko/gudang internal**: proyek beli 19 lembar triplek,
   terpakai 17, **sisa 2 masuk stok Logistik** dan bisa dipakai proyek
   berikutnya.

---

## 1. Keputusan (dikunci 2026-10-04)

1. **Quotation cukup ACC PM.** CEO **hanya menerima notifikasi** (saat
   submit dan saat PM memutuskan), tanpa tombol approval dan tanpa batas
   nilai yang mewajibkan ACC CEO.
2. **Siapa PM yang boleh ACC — TERTUNDA** (user akan menanyakan langsung
   ke pihak Daiku). Sementara rancangan memakai "role PM mana saja"
   (sama dengan gate PM sekarang); gate dibuat di satu tempat
   (`QuotationService`) supaya mudah diganti bila jawabannya lain.
3. **Satuan boleh pecahan** (2,5 m, 1,5 kg). Semua kolom qty material,
   stok, pergerakan stok, dan item RAB menjadi `DECIMAL(12,2)`. Tidak ada
   pembatasan pecahan per satuan.
4. **Tidak ada konversi satuan.** Satu barang satu satuan. Master satuan
   (dus, pcs, kg, lembar, batang, meter, m², sak, set, unit, dll.) di
   **Data Master → Satuan**, CRUD **khusus SUPERADMIN** (sama dengan
   seluruh Data Master sekarang). Role lain hanya memilih dari dropdown.
5. **Barang gudang yang dipakai proyek dibebankan ke proyek itu, dengan
   harga yang ditetapkan Logistik** (harga barang di katalog gudang,
   bukan harga beli proyek asal). Harga disalin ke pergerakan stok saat
   barang keluar, supaya perubahan harga katalog kemudian tidak mengubah
   biaya proyek yang sudah tercatat.
6. **Pencatatan pembelian di Finance — TERTUNDA** (user akan menanyakan
   langsung). Opsinya: (a) pembelian proyek otomatis membuat transaksi
   `BELI_BAHAN`, atau (b) Finance tetap mencatat sendiri lalu ditautkan.
   Sementara rancangan menyiapkan kolom `finance_transaction_id`
   (nullable) di baris pembelian; task R-C Finance menunggu jawaban.
7. **Pembelian & pemakaian dicatat oleh Logistik dan PM** (PM hanya
   untuk proyek miliknya).
8. **Proyek tidak bisa COMPLETED** selama masih ada sisa material yang
   belum dibereskan (diretur atau dicatat susut).

9. **Retur tidak mengurangi biaya proyek asal** (dikunci 2026-10-04 —
   disengaja, Daiku mengambil margin lebih besar). Proyek A menanggung
   harga beli seluruh 19 lembar; proyek B yang mengambil 2 lembar sisa
   dibebankan harga gudang. Laporan biaya per proyek **tidak** boleh
   "dikoreksi" otomatis untuk menghilangkan hitungan ganda ini.
10. **Katalog barang tidak boleh dobel** (dikunci 2026-10-04) — lihat
    mekanisme lengkap di §5.5. Barang custom yang diretur wajib dipetakan
    ke barang katalog yang ada, atau didaftarkan baru oleh Logistik lewat
    pemeriksaan duplikat yang sama.

11. **Kategori material jadi master** (dikunci 2026-10-04) —
    `material_categories` menggantikan teks bebas `materials.category`;
    dikelola di Data Master (SUPERADMIN), begitu juga daftar sinonim nama
    barang. Diperlukan oleh mekanisme anti-dobel §5.5.
12. **Sisa material**: pilihan **retur ke gudang**, **susut** (alasan
    wajib, tidak masuk stok), atau **diserahkan ke klien** (mis. sisa kaca
    ukuran klien; catatan wajib, tidak masuk stok). Retur barang CUSTOM
    wajib dipetakan ke katalog / didaftarkan Logistik dulu.
13. **Barang di luar katalog wajib lewat pengajuan ke Logistik**
    (dikunci 2026-10-04, arahan user). PM dan Estimator **tidak bisa**
    membuat barang baru maupun baris custom secara langsung — mereka
    **mengajukan**, Logistik memutuskan dan menginput:

    ```
    PM / Estimator: Ajukan barang
      (nama, spesifikasi, satuan, qty, estimasi harga, alasan, link foto opsional)
          │  notifikasi → LOGISTICS
          ▼
    Logistik meninjau — layar tinjau menampilkan barang katalog yang mirip
    + riwayat pengajuan serupa di proyek lain:
      ├─ Pakai barang yang ada   → baris jadi GUDANG / PEMBELIAN dengan barang katalog itu
      ├─ Daftarkan ke katalog    → barang baru lewat cek duplikat §5.5 → baris jadi PEMBELIAN
      ├─ Setujui sebagai custom  → barang khusus proyek (tidak masuk katalog), baris CUSTOM
      └─ Tolak                   → alasan wajib, pengaju mendapat notifikasi
          ▼
    Setelah DISETUJUI: catat pembelian → pemakaian → sisa (retur / susut / serahkan klien)
    ```

    - **Definisi custom** = barang khusus satu proyek yang tidak akan
      dipakai ulang (kaca potong ukuran, handle pesanan, panel cetak
      motif). Barang umum yang belum ada di katalog → "Daftarkan ke
      katalog", bukan custom.
    - **Data wajib pengajuan**: nama, spesifikasi, satuan, qty, estimasi
      harga, alasan tidak memakai barang katalog. Vendor & link foto
      opsional.
    - **Tidak boleh membeli sebelum disetujui**, termasuk saat mendesak.
      Pengajuan yang belum ditinjau 1 hari kerja → pengingat ke Logistik;
      CEO menerima ringkasan pengajuan tertunda.
    - **Logistik boleh mengubah** qty, harga, spesifikasi saat menyetujui;
      nilai awal pengaju vs nilai disetujui tersimpan dan terlihat.
    - Satu pintu: hanya Logistik (dan SUPERADMIN) yang menginput barang ke
      katalog dan menyetujui custom. Tanda "custom dipakai di ≥ 3 proyek"
      **tidak dibuat** — riwayat pengajuan serupa sudah tampil di layar
      tinjau.
    - Diaudit: `logistics.material_request_approved/rejected`.
14. **Laporan biaya material proyek dipisah**: Gudang · Pembelian ·
    Custom.

---

## 2. Kondisi sekarang (dari kode)

| Area | Sekarang |
|---|---|
| Approval quotation | `DRAFT → SUBMITTED →(CEO approve) CEO_REVIEW →(PM approve) SENT_TO_CLIENT`. `QuotationService::GATES` (CEO lalu PM), reject siapa pun → kembali DRAFT versi baru (`quotation_revisions`). Notifikasi submit ke CEO + PM. Audit `quotation.ceo_*` / `quotation.pm_*`. |
| Satuan | Teks bebas di tiga tempat: `materials.unit`, `quotation_items.unit` (maks 20 char), dan snapshot JSON `quotation_revisions.items`. |
| Material | `materials` = katalog + stok (`stock` integer, harga beli/jual, min stok). `stock_movements` IN/OUT (OUT wajib ke proyek aktif). `project_materials` = rencana vs terpakai per proyek (`qty_planned`, `qty_used`), hanya barang katalog, unik per (proyek, barang). Belum ada "beli untuk proyek", "barang custom", maupun "retur sisa". Semua qty bilangan bulat. Data Master = SUPERADMIN saja. |

---

## 3. Fitur A — Quotation cukup ACC PM

**Alur baru:** `DRAFT →(submit Estimator) SUBMITTED →(PM approve) SENT_TO_CLIENT`;
PM reject → DRAFT versi berikutnya (mekanisme revisi tetap sama).

- `QuotationService::GATES`: gate `CEO` dihapus; gate `PM` membaca dari
  `SUBMITTED`. Pesan error & docblock disesuaikan.
- Status `CEO_REVIEW` tidak dipakai untuk data baru. **Data lama:**
  quotation yang sedang `CEO_REVIEW` (CEO sudah ACC, menunggu PM) →
  migrasi ke `SUBMITTED` (sama-sama "menunggu PM"). Riwayat approval CEO
  di `quotation_approvals` tetap apa adanya (append-only). Enum case
  `CeoReview` dipertahankan untuk membaca histori.
- Notifikasi: submit → PM (minta ACC) + CEO (info); keputusan PM →
  Estimator + CEO (info).
- UI: panel "Keputusan CEO" dihapus dari `Quotation/Show.tsx`; dashboard
  quotation (antrean, turnaround) disesuaikan; `StatusChip` tetap
  mengenal `CEO_REVIEW` untuk histori.
- Route `quotations.ceoDecision` dihapus + test lama diganti.

## 4. Fitur B — Master Satuan

- Tabel `units`: `code` (unik, mis. `pcs`, `dus`, `kg`, `lbr`, `btg`,
  `m`, `m2`, `sak`, `set`, `unit`, `ls`), `name` ("Pieces", "Dus",
  "Kilogram", "Lembar", ...), `is_active`, `sort_order`.
- **Data Master → Satuan**, CRUD SUPERADMIN. Satuan yang sudah dipakai
  material/item RAB hanya bisa dinonaktifkan, tidak dihapus (mengikuti
  aturan Data Master yang ada: kategori/sumber lead).
- `materials.unit` → `unit_id` (wajib); `quotation_items.unit` →
  `unit_id` (wajib); baris material proyek custom juga `unit_id`. Semua
  input satuan = dropdown satuan aktif.
- **Migrasi data lama:** kumpulkan teks unik dari `materials` +
  `quotation_items`, normalisasi (trim, huruf kecil, alias umum:
  "Lembar"/"lembar"/"lbr" → `lbr`), buat baris `units`, backfill FK,
  hapus kolom teks. Snapshot JSON `quotation_revisions.items` **tidak
  diubah** (histori tetap seperti saat dibuat); snapshot baru menyimpan
  `unit_code`.
- PDF quotation & export Excel memakai master satuan.

## 5. Fitur C — Material Gudang (Logistik), Pembelian Proyek, Custom

### 5.1 Konsep

```
                  ┌──────────────────────────────┐
  beli (vendor) ─▶│ PROYEK A: beli 19 lbr triplek │── pakai 17 ──▶ habis di proyek
                  └──────────────┬───────────────┘
                                 │ sisa 2 → retur
                                 ▼
                  ┌──────────────────────────────┐
                  │ LOGISTIK (stok gudang)         │── ambil 2 ──▶ PROYEK B
                  │ harga ditetapkan Logistik      │   (dibebankan harga gudang)
                  └──────────────────────────────┘
```

Setiap baris material proyek punya **sumber**:

| Sumber | Artinya | Barang |
|---|---|---|
| `GUDANG` | Ambil dari stok Logistik | katalog `materials`; stok berkurang; biaya = harga gudang |
| `PEMBELIAN` | Dibeli khusus untuk proyek, barang **ada di katalog** | vendor; biaya = harga beli aktual |
| `CUSTOM` | Barang khusus proyek, **tidak masuk katalog** (disetujui Logistik lewat pengajuan, keputusan #13) | nama + spesifikasi + satuan + harga dari pengajuan yang disetujui |

### 5.2 Siklus satu baris

1. **Rencana** — PM/Estimator/Logistik memilih barang dari katalog
   (sumber GUDANG/PEMBELIAN, langsung DISETUJUI). Barang yang tidak ada di
   katalog → **pengajuan** ke Logistik (status DIAJUKAN), diputuskan
   sesuai keputusan #13; baru setelah DISETUJUI baris bisa diproses.
2. **Penerimaan** —
   - GUDANG: Logistik mengeluarkan barang → stok −, `qty_received` +,
     harga gudang disalin ke pergerakan.
   - PEMBELIAN/CUSTOM: Logistik/PM mencatat qty dibeli + harga beli
     aktual (+ tautan Finance — keputusan #6 tertunda).
3. **Pemakaian** — Logistik/PM mencatat `qty_used` (≤ qty diterima).
4. **Membereskan sisa** (sisa = diterima − terpakai − diretur − susut −
   diserahkan ke klien):
   - **Retur ke gudang:** stok + sisa, pergerakan `RETURN` dengan proyek
     asal. CUSTOM: Logistik memetakan/mendaftarkan ke katalog dulu.
   - **Susut:** alasan wajib, tidak masuk stok.
   - **Serahkan ke klien:** catatan wajib, tidak masuk stok.
   - Proyek tidak bisa COMPLETED selama ada baris dengan sisa > 0.

### 5.3 Data model (draf)

```
units                        code (unik), name, is_active, sort_order
materials (existing)         unit → unit_id; stock, min_stock → DECIMAL(12,2);
                             cost_price = "harga gudang" yang ditetapkan Logistik
project_materials (existing) + source (GUDANG|PEMBELIAN|CUSTOM)
                             material_id → nullable (wajib kecuali CUSTOM)
                             + custom_name, custom_spec, unit_id, unit_price (harga per satuan aktual)
                             + request_status (DIAJUKAN|DISETUJUI|DITOLAK), request_reason,
                               photo_link, vendor, requested_by, reviewed_by, reviewed_at,
                               review_decision (PAKAI_KATALOG|DAFTAR_KATALOG|CUSTOM|TOLAK),
                               reject_reason, requested_snapshot (JSON: isian awal pengaju)
                             qty_planned, + qty_received, qty_used, + qty_returned, + qty_wasted,
                             + qty_handed_over (DECIMAL; received − used − returned − wasted
                               − handed_over ≥ 0)
                             + waste_reason, handover_note, finance_transaction_id (nullable, #6)
                             index (request_status, created_at) — antrean tinjau Logistik
                             unique(project_id, material_id) dilepas — satu barang bisa
                             punya baris GUDANG dan PEMBELIAN di proyek yang sama
stock_movements (existing)   type: IN | OUT | RETURN; qty → DECIMAL
                             + project_material_id (nullable), + unit_cost (snapshot harga)
quotation_items (existing)   unit → unit_id; qty → DECIMAL(12,2)
```

Stok tetap tidak boleh negatif; semua pergerakan append-only (koreksi lewat
pergerakan balik). Semua aksi dalam `DB::transaction` + `lockForUpdate`
pada baris material/katalog.

### 5.4 Biaya material proyek

Tab Material proyek menampilkan total biaya = Σ (barang gudang × harga
gudang saat keluar) + Σ (pembelian & custom × harga beli aktual). Retur
dan susut ditampilkan per baris; retur **tidak** mengurangi biaya proyek
asal (keputusan #9).

### 5.5 Mencegah barang dobel di katalog

Dobel biasanya terjadi karena nama ditulis berbeda untuk barang yang sama
("Triplek 17mm", "triplek 17 mm", "Plywood 17mm", "TRIPLEK 17MM "). Maka
pencegahannya berlapis — dari struktur data sampai alat perbaikan:

**Lapis 1 — Barang punya identitas terstruktur, bukan satu kolom nama.**

| Kolom | Contoh | Sumber |
|---|---|---|
| Kategori | Kayu & Panel | dropdown master `material_categories` |
| Nama dasar | Triplek | ketik |
| Spesifikasi | 17 mm, 122×244 | ketik (opsional) |
| Merek | Sengon Super | ketik (opsional) |
| Satuan | lbr | dropdown master `units` |

Nama tampil dirakit otomatis: *Triplek 17 mm 122×244 — Sengon Super (lbr)*.
Kode barang dibuat otomatis per kategori (mis. `KYP-0012`), tidak diketik.

**Lapis 2 — Kunci unik di database (dobel persis mustahil).**
Sistem menyimpan `match_key` = gabungan kategori + nama dasar + spesifikasi
+ merek + satuan yang dinormalisasi: huruf kecil, spasi rapat, tanda baca
dibuang, angka-satuan disatukan ("17 mm" = "17mm" = "17 MM"), sinonim
umum diseragamkan (daftar sinonim dikelola SUPERADMIN, mis. plywood →
triplek, multipleks → multiplek). Kolom ini `UNIQUE` — dua barang dengan
kunci sama ditolak oleh database walaupun dua orang menyimpan bersamaan.

**Lapis 3 — Pengecekan "barang mirip" sebelum menyimpan.**
Saat Logistik membuat barang baru (atau mendaftarkan barang custom), sistem
mencari barang yang mirip (kata yang sama, ejaan berdekatan, spesifikasi
sama di kategori yang sama) dan menampilkannya:

```
⚠ Barang serupa sudah ada:
   KYP-0012  Triplek 17 mm 122×244 — Sengon Super (lbr)   stok 2   [Pakai ini]
   KYP-0015  Triplek 18 mm 122×244 (lbr)                   stok 10  [Pakai ini]
   [ Tetap buat barang baru ]  → wajib isi alasan, tercatat di audit log
```

Kecocokan persis (Lapis 2) = ditolak; kemiripan = peringatan + alasan
wajib.

**Lapis 4 — Pencarian katalog dulu, pengajuan belakangan.**
Di form material proyek, PM/Estimator memilih barang lewat kolom pencarian
katalog. Tombol "Ajukan barang (tidak ada di katalog)" baru muncul setelah
mencari, dan saat nama diketik sistem tetap menampilkan saran
"Mungkin maksud Anda: KYP-0012 Triplek 17 mm …" — bila dipilih, baris
langsung memakai barang katalog itu tanpa pengajuan.

**Lapis 5 — Hanya satu pintu: Logistik.**
Barang katalog dan baris custom hanya lahir dari keputusan LOGISTICS (dan
SUPERADMIN) — lewat tinjauan pengajuan (keputusan #13) atau input katalog
langsung. Layar tinjau menjalankan Lapis 2–3 dan menampilkan riwayat
pengajuan serupa di proyek lain. PM/Estimator tidak bisa membuat barang
katalog maupun baris custom secara langsung.

**Lapis 6 — Gabung barang bila tetap lolos dobel.**
Logistik bisa **menggabungkan** barang B ke barang A: stok B dipindah ke A
lewat pergerakan stok (tercatat), baris material proyek & rencana yang
menunjuk B diarahkan ke A, B ditandai `merged_into_id = A` dan nonaktif
(tidak dihapus — riwayat pergerakan lama tetap terbaca). Hanya untuk
barang dengan satuan sama; diaudit (`logistics.material_merged`).

**Data lama:** saat migrasi, barang yang ada dipecah ke kolom terstruktur
(nama lama → nama dasar, sisanya diisi Logistik bertahap) dan `match_key`
dihitung. Barang lama yang kuncinya bentrok **tidak** digagalkan migrasinya
— ditandai "kemungkinan dobel" dan muncul di halaman *Cek Duplikat* untuk
digabung Logistik (Lapis 6).

### 5.6 RBAC

| Aksi | ESTIMATOR | PM | LOGISTICS | CEO |
|---|---|---|---|---|
| Rencana material dari katalog (GUDANG/PEMBELIAN) | C | CRU (proyek miliknya) | CRU | R |
| Ajukan barang di luar katalog | ✔ | ✔ (proyek miliknya) | — | R |
| Tinjau pengajuan (pakai katalog / daftar katalog / custom / tolak, boleh ubah isi) | — | — | ✔ | R + ringkasan tertunda |
| Keluarkan dari gudang | — | — | ✔ | R |
| Catat pembelian (qty + harga) | — | ✔ (proyek miliknya) | ✔ | R |
| Catat pemakaian | — | ✔ (proyek miliknya) | ✔ | R |
| Catat susut / serahkan ke klien | — | ✔ (proyek miliknya) | ✔ | R |
| Retur ke gudang / petakan custom ke katalog | — | — | ✔ | R |
| Tetapkan harga gudang (katalog) | — | — | ✔ | R |
| Master satuan | — | — | — | — (SUPERADMIN saja) |

"Proyek miliknya" = Policy `pm_id`, bukan sekadar role check.

---

## 6. Pertanyaan tertunda (user tanyakan langsung ke Daiku)

| # | Pertanyaan | Dampak bila belum dijawab |
|---|---|---|
| T1 | PM mana yang boleh ACC quotation (semua PM / PM tertentu / PM yang akan memegang proyek)? | Sementara: semua role PM. |
| T2 | Pembelian material proyek otomatis membuat transaksi Finance `BELI_BAHAN`, atau Finance mencatat sendiri lalu ditautkan? | Task "[Finance] Tautan pembelian" belum dikerjakan; kolom FK disiapkan. |
| ~~T3~~ | ~~Retur mengurangi biaya proyek asal?~~ | **Dijawab 2026-10-04: tidak** (keputusan #9). |

---

## 7. Rencana eksekusi

```
Fase 1  ── A Quotation PM  ·  B Master Satuan        (paralel, kecil)
Fase 2  ── C Material + C2 Anti-dobel katalog (butuh units dari B)
Fase 3  ── seeder, test, build, docs
```

### A · Quotation cukup ACC PM
- [ ] **[Quotation]** `QuotationService`: hapus gate CEO, PM membaca dari SUBMITTED, notifikasi PM (aksi) + CEO (info)
- [ ] **[Database]** Migrasi data `CEO_REVIEW` → `SUBMITTED` (reversibel; histori approval tidak diubah)
- [ ] **[Quotation]** Hapus route + action `ceoDecision`, UI keputusan CEO di `Show.tsx`, sesuaikan dashboard & antrean
- [ ] **[Test]** Submit → PM approve → SENT_TO_CLIENT; PM reject → DRAFT v+1; CEO tidak punya aksi approval tapi menerima notifikasi
- [ ] **[Docs]** Catatan deviasi PRD §4.3/§6.2/§7.1 di `plan/README.md`, perbarui `security-standards.md` §4

### B · Master Satuan
- [ ] **[Database]** Tabel `units` + seeder satuan umum (pcs, dus, kg, lbr, btg, m, m2, sak, set, unit, ls)
- [ ] **[MasterData]** Data Master → Satuan (CRUD SUPERADMIN, nonaktif bila terpakai)
- [ ] **[Database]** Migrasi `materials.unit` & `quotation_items.unit` → `unit_id` (normalisasi + backfill + hapus kolom teks); qty item RAB → DECIMAL
- [ ] **[UI]** Dropdown satuan di form Material & RAB quotation; PDF/Excel memakai master
- [ ] **[Test]** CRUD satuan (SUPERADMIN saja, role lain 403), backfill migrasi, qty pecahan

### C · Material Gudang / Pembelian / Custom
- [ ] **[Database]** `materials` stok DECIMAL; `project_materials` (+source, custom, unit, harga, kolom pengajuan & tinjauan, qty received/returned/wasted/handed_over); `stock_movements` (+RETURN, project_material_id, unit_cost, qty DECIMAL)
- [ ] **[Logistics]** `ProjectMaterialService`: rencana dari katalog, keluarkan dari gudang (harga gudang disalin), catat pembelian, pemakaian, retur, susut, serahkan ke klien — transaksi + lock, stok tidak negatif, pakai ≤ diterima, hanya baris DISETUJUI
- [ ] **[Logistics]** `MaterialRequestService`: ajukan (PM/Estimator) → tinjau Logistik (pakai katalog / daftar katalog / custom / tolak + alasan), boleh ubah isi dengan snapshot isian awal, notifikasi pengaju, audit
- [ ] **[Logistics]** Halaman antrean pengajuan Logistik: barang mirip + riwayat pengajuan serupa di proyek lain, form keputusan
- [ ] **[Logistics]** Pengingat pengajuan belum ditinjau 1 hari kerja (job terjadwal, idempotent) + ringkasan tertunda untuk CEO
- [ ] **[Logistics]** Retur barang custom: petakan ke katalog / daftarkan barang baru + harga gudang (lewat cek duplikat §5.5), lalu stok masuk
- [ ] **[Projects]** Tab Material proyek: tabel per sumber (Gudang · Pembelian · Custom), status pengajuan, sisa per baris, tombol Retur/Susut/Serahkan klien, total biaya per sumber
- [ ] **[Projects]** Blokir COMPLETED bila masih ada sisa belum dibereskan atau pengajuan masih DIAJUKAN (pesan menyebut barangnya)
- [ ] **[Logistics]** Riwayat stok: kolom asal retur (proyek), filter jenis RETURN
- [ ] **[Finance]** Tautan pembelian ↔ transaksi `BELI_BAHAN` — **menunggu T2**
- [ ] **[Test]** Alur beli 19 → pakai 17 → retur 2 → proyek B ambil 2 (dibebankan harga gudang); pengajuan → 4 keputusan Logistik; baris DIAJUKAN tidak bisa dibeli/dipakai; PM/Estimator tidak bisa membuat custom langsung; custom → retur → katalog; PM hanya proyek miliknya; stok tidak negatif; blokir COMPLETED

### C2 · Anti-dobel katalog (§5.5)
- [ ] **[MasterData]** Master `material_categories` (+ prefix kode) dan daftar sinonim nama barang — CRUD SUPERADMIN
- [ ] **[Database]** `materials`: kategori FK, nama dasar, spesifikasi, merek, kode otomatis, `match_key` UNIQUE, `merged_into_id`; migrasi data lama (bentrok → tandai "kemungkinan dobel", tidak gagal)
- [ ] **[Logistics]** `MaterialCatalogService`: normalisasi `match_key`, cari barang mirip, buat barang (tolak persis, mirip → alasan wajib + audit)
- [ ] **[UI]** Form barang terstruktur + panel "Barang serupa sudah ada"; pencarian katalog dulu di form material proyek, saran "Mungkin maksud Anda" sebelum mengajukan
- [ ] **[Logistics]** Halaman Cek Duplikat + gabung barang B → A (stok via pergerakan, referensi diarahkan, B nonaktif, audit)
- [ ] **[Test]** Variasi penulisan ("17mm"/"17 MM"/sinonim) ditolak sebagai dobel; simpan bersamaan → satu yang lolos; gabung barang memindahkan stok & referensi; PM/Estimator tidak bisa membuat barang katalog

### Penutup
- [ ] **[Setup]** DemoDataSeeder: quotation alur PM-only, satuan, baris GUDANG/PEMBELIAN/CUSTOM + retur
- [ ] **[Setup]** `npm run build`, `pint --test`, `php artisan test`, update `plan/README.md`
