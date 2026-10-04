# Sprint 12 — Revisi Alur Bisnis: Survey · Desain · RAB · Pembayaran · Alokasi Dana

> Status: **keputusan dikunci 2026-10-04 lewat diskusi bertahap dengan user
> (hasil rapat internal Daiku + foto papan tulis alur + contoh Excel
> "RAB_KOPI OZ ARIFIN"), belum ada kode.** Beberapa default kecil masih
> menunggu konfirmasi — lihat §5. Rancangan detail & checklist per sesi kerja: §6.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`.
> Sprint ini **menyimpang dari PRD** di banyak tempat (§4.1 CRM, §4.2
> Desain, §4.3/§6.2/§7.1 approval quotation, §4.7 termin, §7.1 RBAC) —
> setiap penyimpangan dicatat di `plan/README.md` "Catatan penyesuaian".
>
> **Sprint ini mengubah Sprint 11** (belum dikerjakan): Fitur A Sprint 11
> diganti sub-plan 04, dan pengajuan barang Sprint 11 (keputusan #13)
> diperluas untuk Tukang — lihat §4.

---

## 1. Alur bisnis baru (end-to-end)

```
Lead masuk (TikTok / IG / ...)
  │  Marketing: follow-up bertingkat FU-1, FU-2, ... (bebas; > 4 → saran "Lost")
  │
  ├─(opsional) SURVEY ──────────────────────────────────────────────────────┐
  │   Marketing jadwalkan survey (bisa survey ulang)                         │
  │   Di Pekanbaru → gratis, langsung jalan                                  │
  │   Di luar Pekanbaru → Marketing minta RAB Jasa Survey ke Estimator        │
  │       → PM/Asisten PM ACC → Marketing kirim link → client Setujui        │
  │       → Marketing keluarkan invoice → Finance verifikasi bayar → survey  │
  │                                                                          │
  ├─(opsional) DESAIN ───────────────────────────────────────────────────────┤
  │   Marketing minta RAB Jasa Desain ke Estimator                           │
  │       → PM/Asisten PM ACC → Marketing kirim link → client Setujui        │
  │       → [notif tim desain: "disetujui, belum bayar"]                     │
  │       → Marketing keluarkan invoice → Finance verifikasi bayar           │
  │       → [notif tim desain: "siap dikerjakan"]                            │
  │   Kepala Desain: tunjuk PIC arsitek (boleh diri sendiri) + asisten,       │
  │                  tetapkan timeline                                       │
  │   Arsitek: susun desain → unggah ke sistem                               │
  │   Marketing: kirim desain ke client ──▶ revisi? Marketing minta revisi   │
  │              (dihitung) ──▶ ulang sampai client OK → Desain ACC          │
  │   (Survey & Desain: urutan bebas, masing-masing boleh dilewati)           │
  │                                                                          │
  └─ RAB PROYEK (selalu terakhir; boleh tanpa desain — client bawa desain) ◀─┘
      Estimator susun RAB (bagian pekerjaan, dimensi, volume, harga, diskon,
                          pembulatan) + skema DP/termin (maks. 6)
      → PM/Asisten PM: tandai tiap item ✔/✘ → ACC   (✘ → kembali ke Estimator)
      → CEO ACC                                     (tolak → kembali ke Estimator,
                                                      dihitung ke KPI PM)
      → Estimator: "Kirim RAB Final ke Marketing"
      → Marketing: kirim link ke client → client Setujui (RAB + skema bayar)
      → CEO: pop-up "Buka Proyek" → proyek dibuat + termin otomatis dari skema
      → Marketing keluarkan invoice DP/termin (tanggal / milestone / proyek selesai)
      → Finance verifikasi pembayaran (Finance menerima RAB Fix + invoice)
      → PM: alokasi dana — kelompokkan item RAB ke pos (nama bebas)
      → PM: catat realisasi per item (qty riil × harga modal)
            melebihi anggaran pos → diblokir → CEO approve → lanjut
```

---

## 2. Keputusan (dikunci 2026-10-04)

### CRM
1. **Tanggal pertama masuk sistem** (`created_at`, otomatis) dan **tanggal
   pertama dihubungi** (diisi Marketing) disimpan terpisah.
2. **Follow-up bertingkat** FU-1, FU-2, FU-3, ... masing-masing punya
   tanggal jadwal dan catatan hasil. Jumlah **bebas**; setelah FU ke-4
   sistem menampilkan saran "Pertimbangkan tandai Lost" (tidak memblokir).
3. **Survey dijadwalkan Marketing**, bisa lebih dari sekali (survey ulang).
   Survey **di Pekanbaru gratis** (tanpa RAB). **Di luar Pekanbaru wajib
   RAB Jasa Survey dan client bayar dulu** sebelum survey berangkat.
4. **Alamat client**: alamat umum (teks, mis. "Jl. Tegal Sari ...") +
   **link Google Maps**.
5. Tombol "Deal Desain" → **"Ajukan Desain/Survey"**; seluruh tombol sistem
   diubah ke gaya **kata kerja + objek** (sub-plan 14).

### Quotation / RAB
6. **Tiga jenis quotation**: `SURVEY` (Jasa Survey), `DESAIN` (Jasa
   Desain), `PROYEK` (RAB pembangunan). Survey & Desain **opsional, urutan
   bebas**; RAB Proyek selalu terakhir dan **tidak mewajibkan desain ACC**
   (client bisa membawa desain sendiri).
7. Ketiganya **diminta Marketing → disusun Estimator → di-ACC
   PM/Asisten PM**. Khusus **RAB Proyek** berurutan: **PM → CEO**.
8. **PM menandai tiap item ✔ cocok / ✘ kurang cocok** (+ catatan untuk ✘).
   Ada ✘ → RAB kembali ke Estimator (versi baru). Isi RAB tetap diketik
   Estimator; PM tidak mengubah item.
9. **KPI Estimator** = (a) % item ✔ pada pengecekan pertama, (b) jumlah RAB
   dikembalikan. **RAB yang dikembalikan CEO dihitung ke KPI PM**
   ("ketelitian review") — PM meloloskan item yang salah.
10. Setelah ACC internal lengkap, **Estimator** menekan "Kirim RAB Final ke
    Marketing"; **Marketing** yang mengirim ke client.
11. **Format RAB mengikuti Excel Daiku**: item dikelompokkan per **bagian
    pekerjaan**; kolom No, Item, Dimensi P × T/L, Volume, Satuan, Harga,
    Subtotal; di bawahnya Total, **Diskon**, **Pembulatan**, dan **skema
    DP/termin**. Nama item teks bebas.
12. **Skema DP/termin disusun Estimator** (bukan PM seperti sekarang),
    maksimal **6 termin**, **ikut tampil dan ikut disetujui client** di
    halaman RAB. Setiap termin dipicu **tanggal tertentu** atau
    **milestone/proyek selesai**.

### Persetujuan client lewat link
13. Marketing mengirim **link halaman RAB** (satu template untuk ketiga
    jenis) via WhatsApp/salin link. Client membuka **tanpa login**, menekan
    **"Setujui Penawaran"** → pop-up konfirmasi dengan **centang wajib**
    ("Saya telah membaca dan menyetujui penawaran ini") → tercatat waktu,
    IP, perangkat. **Hanya tombol Setujui**; revisi tetap lewat WhatsApp
    Marketing.
14. Link hanya berlaku untuk **versi terbaru** dan sampai **"berlaku
    sampai"**; versi lama menampilkan "Penawaran ini sudah diperbarui";
    setelah disetujui halaman jadi "Telah disetujui pada ...". Data internal
    (harga modal, margin, tanda ✔/✘, catatan internal) **tidak pernah**
    tampil.

### Desain
15. Role tambahan **`KEPALA_DESAIN`** (di atas role DESIGNER; boleh lebih
    dari satu orang). Hak khusus: menerima desain masuk, **menunjuk PIC
    arsitek (termasuk dirinya sendiri) + asisten arsitek**, menetapkan
    timeline.
16. Tim desain dinotifikasi **dua kali**: saat client menyetujui RAB Jasa
    Desain dan saat pembayarannya diverifikasi. **Desain terkunci sampai
    pembayaran diverifikasi Finance.**
17. **Marketing** yang mengirim desain ke client dan **meminta revisi** ke
    arsitek. **Jumlah revisi dicatat** (tanpa batas, tidak masuk KPI
    arsitek).
18. **Komunikasi dua arah Arsitek ↔ Estimator** (thread diskusi + notifikasi)
    — desain OK menjadi dasar RAB, RAB bisa memantul balik ke arsitek.

### Proyek, invoice, Finance
19. Client menyetujui RAB Proyek → **CEO mendapat pop-up "Buka Proyek"**
    (pilih PM, Asisten PM, tanggal mulai) → proyek dibuat otomatis, termin
    dibuat dari skema yang disetujui client.
20. **Semua invoice dikeluarkan Marketing** — Jasa Survey, Jasa Desain, DP,
    dan setiap termin. Saat pemicu termin tercapai, Marketing mendapat
    pengingat untuk mengeluarkan invoice.
21. **Finance memverifikasi** apakah pembayaran benar masuk. Finance
    menerima **RAB Fix + invoice final** per proyek.
22. Role **`ASISTEN_PM`**: boleh ACC RAB (ketiga jenis) dan ACC pengajuan
    barang. **Tidak boleh** mengisi alokasi maupun realisasi.

### Alokasi dana (Model A — seperti Excel)
23. Alokasi **terbuka setelah pembayaran pertama diverifikasi Finance**;
    PM bisa mengalokasikan kapan saja dan bertahap ("bebas dinamis").
24. **PM mengelompokkan item RAB ke pos** dengan **nama bebas** (Interior,
    Listrik, Percetakan, Mural, ...). Item tidak wajib terbagi habis;
    **peringatan bila total pos > total RAB**.
25. Alokasi boleh diubah PM; **cukup tercatat di riwayat**.
26. **Diskon** hanya pengurang di ringkasan, tidak dibebankan ke pos.
27. **Realisasi per item** dicatat **PM** (qty riil × harga modal, + vendor
    opsional) — seperti kolom harga modal 250.000 vs 280.000 di pos Listrik.
    Finance melihat **anggaran vs realisasi per pos**.
28. **Realisasi melebihi anggaran pos** → peringatan → **diblokir** →
    notifikasi CEO → **CEO approve** → PM bisa lanjut.
29. **Pekerjaan tambah** (blok "Penambahan" di Excel) = **RAB tambahan**
    lewat alur yang sama (Estimator → PM → CEO → link client), lalu itemnya
    bisa masuk ke pos.

### Visibilitas, logistik, vendor
30. **Marketing boleh melihat**: milestone, progress, termin, invoice,
    status bayar. **Disembunyikan dari Marketing**: alokasi, realisasi/harga
    modal, margin, transaksi kas Finance — diblok di server, bukan hanya
    disembunyikan di UI.
31. **Pengajuan barang oleh Tukang**: nama barang + jumlah + catatan →
    **ACC PM/Asisten PM proyek** → **Logistik** mencocokkan ke katalog dan
    menginput barangnya → diambil dari gudang atau dibeli (alur Sprint 11).
32. **Master Vendor** dikelola **SUPERADMIN + CEO** (nama, kontak, alamat, jenis MATERIAL/JASA, rekening
    bank), dipakai ulang di: realisasi per item, pembelian material, hutang
    supplier.

---

## 3. Kondisi sekarang (dari kode)

| Area | Sekarang | Yang berubah |
|---|---|---|
| Lead | `client_name, contact, city, follow_up_date` (satu tanggal), `status` FOLLOW_UP → DEAL_DESAIN → CLOSING / LOST | + tanggal pertama dihubungi, alamat, link Maps; follow-up & survey jadi tabel anak |
| Desain | `designs.pic_id` (satu PIC dipilih saat dibuat), status BRIEF → DESAIN → WAITING_ACC_DESAIN → REVISI_CLIENT → ACC_DESAIN (+ status pipeline lain) | + menunggu bayar, penugasan oleh Kepala Desain, asisten, hitungan revisi |
| Quotation | Satu jenis, dibuat dari desain (`QuotationService::createFromDesign`). `DRAFT → SUBMITTED →(CEO) CEO_REVIEW →(PM) SENT_TO_CLIENT`; client deal lewat `LeadService::confirmDeal()` → APPROVED. Item: `description, qty, unit, unit_price, total_price` | 3 jenis, urutan **PM lalu CEO** (proyek), review per item, bagian/dimensi/diskon/pembulatan, skema bayar, link client |
| Termin | Dibuat **PM** per proyek (`TerminService::create`), tanpa batas; Finance `markPaid`/`recordPayment` | Dari skema Estimator (maks. 6), invoice oleh Marketing, verifikasi Finance |
| Proyek | Dibuat dari lead DEAL_DESAIN/CLOSING (`ProjectService::createFromLead`), satu `pm_id` | Dibuka CEO lewat pop-up setelah client setuju; + `assistant_pm_id` |
| Visibilitas | `ProjectPolicy::view` → Marketing boleh buka proyek | Data finance tidak dikirim ke Marketing |
| Hutang supplier | `supplier_debts.supplier_name` teks bebas | → `vendor_id` |
| KPI | Sprint 10 punya indikator sumber `AUTO`/`MANUAL` | + indikator otomatis Estimator & PM |

---

## 4. Perubahan pada Sprint 11

| Sprint 11 | Perubahan |
|---|---|
| **Fitur A** "Quotation cukup ACC PM" | **Dibatalkan, diganti sub-plan 04.** PM-only hanya untuk Survey/Desain; RAB Proyek **PM lalu CEO** (urutan dibalik dari kode sekarang). |
| **Fitur B** Master Satuan | Tetap — Sprint 11 Sub 1 (RAB baru butuh `unit_id` & qty DECIMAL). |
| **Master Vendor** (keputusan #32) | **Dipindah ke Sprint 11 Sub 2** — pembelian material membutuhkannya. |
| **Fitur C** keputusan #13 pengajuan barang | **Diperluas** (Sprint 11 Sub 4): pengaju bisa **Tukang** (proyek tempat ia punya task; isian cukup nama + jumlah + catatan), lewat **ACC PM** dulu, baru tinjauan Logistik. Asisten PM ikut boleh ACC setelah sub-plan 11. Pengajuan PM/Estimator tetap langsung ke Logistik. |
| T1 "PM mana yang boleh ACC" | Masih tertunda; sementara semua PM + ASISTEN_PM. |

## 5. Default yang menunggu konfirmasi

| # | Pertanyaan | Default yang dipakai |
|---|---|---|
| D1 | "Maks. 6 termin" termasuk DP? | Ya — total baris skema maks. 6 (DP + 5 termin) |
| D2 | Siapa menugaskan Asisten PM? | CEO saat Buka Proyek; PM proyek boleh mengubah |
| ~~D3~~ | ~~Siapa mengelola master Vendor?~~ | **Dijawab 2026-10-04: SUPERADMIN + CEO** (keputusan #32) |
| D4 | Skema bayar Jasa Survey/Desain | 1 baris, 100% di depan |
| D5 | Label role DESIGNER di UI | "Arsitek" (kode role tetap `DESIGNER`) |
| D6 | Wujud komunikasi Arsitek ↔ Estimator | Thread komentar (teks + link lampiran) + notifikasi |
| D7 | Skema bayar RAB Tambahan (addendum) | Ditambahkan sebagai termin proyek baru bertipe TAMBAHAN; batas 6 dihitung per quotation |

---

## 6. Sub-plan (satu file = satu sesi kerja)

Detail rancangan, file yang disentuh, dan checklist ada di folder
[`sprint-12/`](sprint-12/). Saat mengerjakan, cukup baca **file induk ini
(§1–§5) + satu sub-plan**.

| # | Sub-plan | Isi | Prasyarat | Task |
|---|---|---|---|---|
| 01 | [Fondasi: role baru](sprint-12/01-fondasi-role.md) | ASISTEN_PM, KEPALA_DESAIN | **Sprint 11 selesai** | 3 |
| 02 | [CRM: follow-up, survey, alamat](sprint-12/02-crm-followup-survey.md) | FU bertingkat, survey berulang, alamat + Maps | — | 5 |
| 03 | [Quotation: 3 jenis & struktur RAB](sprint-12/03-quotation-struktur-rab.md) | SURVEY/DESAIN/PROYEK, bagian, dimensi, diskon, pembulatan, skema bayar | 02 | 5 |
| 04 | [Quotation: review & approval](sprint-12/04-quotation-approval-review.md) | ✔/✘ per item, PM → CEO, kirim ke Marketing | 01, 03 | 6 |
| 05 | [Link persetujuan client](sprint-12/05-link-persetujuan-client.md) | Halaman publik + konfirmasi centang | 04 | 6 |
| 06 | [Invoice & verifikasi Finance](sprint-12/06-invoice-verifikasi-finance.md) | Invoice oleh Marketing, verifikasi Finance, survey luar kota | 05 | 5 |
| 07 | [Buka Proyek & termin](sprint-12/07-buka-proyek-termin.md) | Pop-up CEO, termin dari skema, pengingat invoice | 06 | 6 |
| 08 | [Desain: Kepala Desain](sprint-12/08-desain-kepala-desain.md) | Kunci bayar, penugasan, revisi, diskusi | 01, 06 | 5 |
| 09 | [Alokasi Dana Proyek](sprint-12/09-alokasi-dana.md) | Pos bebas, item → pos, peringatan, riwayat | 07 | 4 |
| 10 | [Realisasi & overrun](sprint-12/10-realisasi-overrun.md) | Biaya riil per item, blokir → ACC CEO | 09 | 4 |
| 11 | [Asisten PM & visibilitas](sprint-12/11-asisten-pm-visibilitas.md) | Asisten per proyek (+ ACC pengajuan barang), Marketing tanpa data finance | 07, 09, 10 | 5 |
| 12 | [RAB Tambahan](sprint-12/12-rab-tambahan.md) | Addendum lewat alur yang sama | 05, 07, 09 | 4 |
| 13 | [KPI otomatis](sprint-12/13-kpi-otomatis.md) | Metrik Estimator & PM | 04 | 3 |
| 14 | [Nama tombol & penutup](sprint-12/14-nama-tombol-penutup.md) | Rename tombol (cek user dulu), seeder, docs, security review | 01–13 | 5 |

Kerjakan berurutan **Sub 01 → Sub 14**, setelah **Sprint 11 (Sub 1–6) selesai**.
Sprint 11 Fitur A **tidak** dikerjakan (diganti Sub 04).
