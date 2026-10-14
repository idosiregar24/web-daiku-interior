# Sprint 23 — Revisi: Alur RAB, Buka Proyek oleh PM, Timeline, Desain via WA, "Menunggu Siapa"

> Status: **kode selesai 2026-10-14** (Sub 01–12) — 2129 test lulus,
> `npm run build` & Pint bersih, 10 migrasi sudah jalan di DB dev. Tersisa:
> `/security-review` (unggah foto bukti bayar + route publik `tolak`), uji
> alur lengkap di browser per role, deploy (migrasi baru). Hasil & keputusan
> tambahan: §6.
>
> Sumber: daftar revisi user 2026-10-13 (16 poin + tambahan R17–R23, §1) + jawaban
> Q1–Q13 di hari yang sama (§4). R18 (Desain via WA) **membatalkan** tombol
> Sprint 22 (Desain Siap Dikirim, Kirim ke Klien) dan tombol Minta Revisi. Alur eksekusi proyek dari papan tulis (Gambar Kerja →
> TM → Pengawas → Produksi → QC) dipisah ke
> [`sprint-24-alur-eksekusi-proyek.md`](sprint-24-alur-eksekusi-proyek.md) —
> sprint ini hanya menyiapkan datanya (Kepala Tim, Pengawas, tanggal, Timeline
> bertanggal).
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Sprint ini
> **mengubah** keputusan Sprint 12 #7 (CEO menyetujui RAB Proyek), #10
> (Estimator mengirim ke Marketing), #17 (Marketing mengirim desain ke klien)
> dan #19 (CEO yang Buka Proyek), serta Sprint 22 — golden rule #11 di
> `CLAUDE.md` ikut diperbarui di Sub 11.

## 1. Daftar revisi (kata-kata user)

| # | Revisi user | Kelompok |
|---|---|---|
| R1 | Ada POP UP kalau berhasil simpan dll (Success) | A. UX global |
| R2 | Animasi loading muncul di tengah layar | A. UX global |
| R3 | Status (tambahkan divisinya) → diperjelas: **semua** info status menyebut prosesnya menunggu siapa (QA, Marketing, Estimator, …) | A. UX global |
| R4 | Khusus m lari → bisa pilih ambil nilai dari P atau T/L | B. RAB Builder |
| R5 | Ada titik pemisah di harga | A. UX global |
| R6 | RAB tidak perlu ada section terpisah | B. Review RAB |
| R7 | RAB yang sudah diceklis tidak perlu lagi | B. Review RAB |
| R8 | CEO tidak perlu menyetujui RAB Proyek → hanya dapat notif & bisa menambah catatan | C. Alur RAB |
| R9 | Setelah approve PM tidak ada lagi tombol "Kirim ke Marketing" — langsung ke Marketing | C. Alur RAB |
| R10 | Halaman penawaran: opsi **Tidak Setuju** + catatan → ke Marketing → Marketing menambah yang kurang → ke Estimator | C. Alur RAB |
| R11 | Verifikasi Pembayaran: tampilkan ini pembayaran DP atau termin | D. Keuangan |
| R12 | PM yang Buka Proyek | E. Buka Proyek |
| R13 | Buka Proyek: Kepala Tim (pilih staf **atau** isi sendiri), Leader/Pengawas (QA) | E. Buka Proyek |
| R14 | Buka Proyek: Tanggal Buka Proyek (beda dengan tanggal mulai) | E. Buka Proyek |
| R15 | Buka Proyek: Tanggal Selesai (opsional) | E. Buka Proyek |
| R16 | Milestone ganti nama jadi **Timeline**; timeline punya tanggal mulai & selesai | F. Timeline |
| R17 | Status saat konfirmasi pembayaran oleh Finance menyebut pembayaran apa, mis. "DP untuk proyek ini" | D. Keuangan |
| R18 | Tahap desain (setelah Kepala Desain membagi tugas): tombolnya **hanya "Desain Disetujui Klien"** — semua proses bisnis desain berjalan di WA (klien, Marketing, dll). Yang disimpan di sistem hanya **link grup WA** dan **link desain**. Fitur diskusi tetap seperti sekarang. | G. Desain |
| R19 | Selain meter lari: satuan **set** dan **unit** volume langsung = **P × T/L** otomatis / sebagai saran, **tetap bisa diubah** | B. RAB Builder |
| R20 | Di detail lead (klien) ada section **informasi pembayaran**: pembayaran apa saja, mana yang sudah lunas & yang belum, kapan, dll | D. Keuangan |
| R21 | Bukti pembayaran bisa **upload foto** (opsional), nanti dicek Finance | D. Keuangan |
| R22 | Kegiatan / aksi yang butuh tindak lanjut **dinaikkan ke atas** | A. UX global |
| R23 | Saat membuat RAB: tombol Simpan & **Kirim ke PM** di bawah, di antara section RAB dan Termin, dengan info **"Simpan dulu sebagai draft kemudian kirim ke PM"** | B. RAB Builder |

## 2. Kondisi kode sekarang (temuan)

| # | Kondisi | Relevan untuk |
|---|---|---|
| T1 | Pesan sukses sudah ada sebagai **toast kecil kanan atas** (`useFlashToasts` + sonner `Toaster position="top-right"` di `AppLayout`). Mudah terlewat. | R1 |
| T2 | Loading hanya **garis progress tipis di atas** (Inertia `progress`, `app.tsx`). Ada reload latar belakang (lonceng 60 dtk, `router.reload`) yang tidak boleh memunculkan loading. | R2 |
| T3 | `StatusChip` hanya menampilkan label status. Siapa yang memegang giliran hanya tersirat di antrean "Perlu Tindakan" (`ActionInboxService`). | R3 |
| T4 | Kolom P, T/L, Volume di `RabBuilder` **diisi manual semua**. Satuan `m/lari` & `m2` ada di `Unit::DEFAULTS`. | R4 |
| T5 | Semua input uang `type="number"` → tampil `1500000` tanpa titik (±21 file). | R5 |
| T6 | Saat SUBMITTED, RAB tampil **dua kali**: kartu "Rincian RAB" (read-only) **dan** kartu "Review PM / Asisten PM" (`QuotationReviewPanel`) yang mendaftar ulang item untuk ✔/✘. | R6 |
| T7 | PM wajib menandai **semua** item ✔/✘ di setiap versi (`QuotationService::review()` menolak bila `marks ≠ items`), termasuk item yang sudah ✔ di versi sebelumnya. | R7 |
| T8 | Alur sekarang: SUBMITTED → PM ACC → **WAITING_CEO** (RAB Proyek) → APPROVED_INTERNAL → Estimator tekan **"Kirim ke Marketing"** → READY_TO_SEND. 23 file menyebut WAITING_CEO/APPROVED_INTERNAL (inbox `quotation-ceo`, dashboard RAB, notifikasi, test). | R8, R9 |
| T9 | Link klien (`penawaran/{token}`) hanya bisa **Setuju**. Penolakan klien dicatat manual oleh Marketing (`clientReject()`) dan langsung jadi DRAFT versi baru — tanpa langkah Marketing menambah catatan. | R10 |
| T10 | Verifikasi Pembayaran **sudah** punya kolom Jenis (`INVOICE_TYPE_LABEL`), tapi hanya "Termin" — tanpa termin ke berapa / persen. Invoice punya `termin_id`. | R11 |
| T11 | Buka Proyek: `role:CEO`; form: Nama, PM, Asisten PM, Tanggal Mulai. `projects.end_date` ada tapi tidak diisi saat buka. Belum ada Kepala Tim / Pengawas / Tanggal Buka. QA tidak ditugaskan per proyek. PM yang meng-ACC RAB tercatat di `quotation_approvals` (`approver_role = PM`). | R12–R15 |
| T12 | `milestones` hanya punya `target_date`. Kata "Milestone" tampil di ±16 file + notifikasi (`milestone_overdue`), PDF, QA. | R16 |
| T15 | Bukti bayar invoice hanya **link** (`payment_proof_url`, opsional) + catatan, diisi Marketing lewat "Tandai Klien Sudah Bayar" (`SubmitInvoiceProofRequest`); dikirim ulang menimpa yang lama. Belum ada unggah foto. | R21 |
| T16 | Kartu aksi ada di **bawah** halaman: di detail RAB urutannya Rincian RAB → Skema Pembayaran → Catatan Klien → Review → Keputusan Klien → **Langkah Berikutnya** (`QuotationNextStepCard`) → Riwayat. Orang yang harus bertindak perlu menggulir dulu. | R22 |
| T17 | "Simpan RAB" + "Kirim ke PM" sudah di bawah kartu Rincian RAB (sebelum Skema Pembayaran), tanpa keterangan. "Kirim ke PM" bisa ditekan walau ada perubahan **yang belum disimpan** — yang terkirim versi tersimpan terakhir, perubahan di layar hilang. | R23 |
| T13 | Status invoice di antrean Finance hanya "Menunggu Verifikasi"; jenis pembayaran & proyek ada di kolom lain, tidak di status / notifikasi / item "Perlu Tindakan". | R17 |
| T14 | Desain setelah penugasan: DESAIN → arsitek **"Desain Siap Dikirim"** (Sprint 22) → Marketing **"Kirim Desain ke Klien"** (WAITING_ACC_DESAIN, dialog WA) → **"Minta Revisi"** (REVISI_DESAIN, dihitung) / **"Desain Disetujui Klien"** (ACC_DESAIN, hanya dari WAITING_ACC_DESAIN). Form "Brief Desain" berisi PIC, Jenis Project, Asisten, Status, Target Hari, Tanggal Mulai, Catatan Brief, Problem, Link Desain. Belum ada kolom link grup WA. Dialog penugasan Kepala Desain (PIC, asisten, tanggal mulai, target hari) dipakai KPI keterlambatan desain. | R18 |

## 3. Keputusan

### A. UX global

| # | Keputusan |
|---|---|
| K1 | **Popup sukses di tengah layar** (R1): komponen global `SuccessDialog` membaca `flash.success` lewat `useFlashToasts` — semua controller yang sudah `->with('success')` ikut otomatis. Ikon centang beranimasi + pesan + tombol "OK", tutup otomatis ±3 dtk. **Error tetap toast merah** (tidak menutupi form yang mau diperbaiki). |
| K2 | **Loading di tengah layar** (R2): `GlobalLoadingOverlay` di `AppLayout` (`router.on('start'/'finish')`), muncul hanya bila request > ±300 ms, teks "Menyimpan…" (POST/PUT/PATCH/DELETE) / "Memuat…" (GET). Reload latar belakang (lonceng, inbox, polling — kunjungan dengan `only`/`preserveState` tanpa interaksi user) **tidak** memunculkannya. Garis progress atas tetap. Hormati `prefers-reduced-motion`. |
| K3 | **"Menunggu siapa" di semua status** (R3): setiap status yang masih berjalan menyebut pemegang gilirannya, mis. "Menunggu Review · PM / Asisten PM", "Menunggu Verifikasi · Finance", "Siap Dikirim · Marketing", "Dikirim · Klien". Satu peta per domain di `resources/js/lib/waitingOn.ts` (RAB, Lead, Desain, Invoice, Termin, Pengajuan Barang, Lembur, QA, Buka Proyek, Perubahan Gaji, Task, …); `StatusChip` mendapat prop `waitingOn`. Status selesai/batal tidak diberi pemegang. Daftar lengkap status → pemegang dibuat & dicek di Sub 01. |
| K4b | **Tombol aksi di paling atas** (R22, Q18): di setiap halaman detail (RAB, Lead, Desain, Proyek, Invoice/Termin, …) **tombol aksi** — mis. Cetak/Unduh Invoice, Terbitkan Invoice, Kirim ke Klien, Salin Link / Kirim via WhatsApp, Klien Menolak, Tandai Klien Sudah Bayar, Buka Proyek, Desain Disetujui Klien — dipindah dari kartu-kartu di bawah ke **bagian atas halaman**: baris aksi di `PageHeader` (tombol utama menonjol, sisanya outline / menu "Lainnya"; lebar penuh di HP) + keterangan singkat langkahnya tepat di bawah judul. Kartu di bawah tinggal berisi informasi (rincian, riwayat). **Pengecualian**: tombol yang menyimpan isian form tetap menempel di form-nya — "Simpan sebagai Draft / Kirim ke PM" (K5b) dan keputusan review per item (K6). Aksi per baris di tabel tetap di barisnya. |
| K4 | **Titik pemisah ribuan** (R5): komponen bersama `MoneyInput` (tampil `1.500.000`, nilai form tetap angka polos → Zod & Form Request tidak berubah). Ganti **semua** input uang di sistem. |

### B. RAB Builder & review

| # | Keputusan |
|---|---|
| K5 | **Volume dari dimensi** (R4, R19): kolom baru `quotation_items.qty_source` (`null` manual / `P` / `TL` / `PxTL`). Satuan **m/lari** → **popup kecil** (popover di sel Volume) menanyakan "Volume diambil dari: **P** atau **T/L**?" saat satuan m/lari dipilih / dimensi diisi; pilihan tersimpan per baris dan bisa diganti lewat penanda kecil di sel Volume. **Semua satuan lain** → Volume = **P × T/L** langsung (Q16). Saran hanya dibuat bila dimensi yang dibutuhkan terisi (m/lari: dimensi yang dipilih; lainnya: P **dan** T/L) — baris tanpa dimensi tetap diisi manual. Volume otomatis **tetap bisa diubah** (R19): begitu Estimator mengetik volume sendiri, baris itu jadi manual (`qty_source = null`) dan tombol kecil "↺ Hitung dari P/T/L" mengembalikannya. Server tidak menimpa volume — hanya menyimpan sumbernya (untuk penanda "otomatis" di tabel). |
| K5b | **Tombol Simpan & Kirim ke PM** (R23): tetap di bawah kartu Rincian RAB, **di antara RAB dan Skema Pembayaran (termin)**, sebagai satu baris aksi: **"Simpan sebagai Draft"** + **"Kirim ke PM"** dengan keterangan *"Simpan dulu sebagai draft, kemudian kirim ke PM."* "Kirim ke PM" **nonaktif selama ada perubahan yang belum disimpan** (RAB) — teks keterangan berubah jadi "Ada perubahan yang belum disimpan". |
| K6 | **Review menyatu di tabel RAB** (R6): kartu "Review PM / Asisten PM" dihapus; kolom ✔/✘ + catatan muncul **di dalam** tabel "Rincian RAB" saat giliran PM, tombol "Setujui RAB" / "Kembalikan ke Estimator" di bawahnya. Bagian pekerjaan (A. Dapur, B. Kamar…) **tetap**. |
| K7 | **Item yang sudah ✔ tidak dicek ulang** (R7): di versi revisi, item yang **identik** (bagian, nama, P, T/L, volume, satuan, harga) dengan item ✔ di versi sebelumnya otomatis ✔ ("✔ versi N", terkunci). PM hanya menandai item baru / berubah / yang dulu ✘. **Item ✔ yang diubah = dicek ulang.** `review()` hanya mewajibkan tanda untuk item yang tidak terbawa; tanda terbawa disimpan sebagai baris review dengan `carried_from_version`. |

### C. Alur persetujuan RAB (mengubah Sprint 12 #7, #10)

```
DIMINTA → DRAFT → SUBMITTED (review PM / Asisten PM)
  ├─ ada ✘ / dikembalikan → DRAFT versi berikutnya (Estimator)
  └─ disetujui → READY_TO_SEND (langsung ke Marketing, semua tipe RAB)
                 └─ RAB Proyek: CEO dapat notifikasi (info) + boleh menulis catatan
READY_TO_SEND → SENT_TO_CLIENT (Marketing kirim link)
SENT_TO_CLIENT ├─ klien Setuju (link)                → CLIENT_APPROVED
               └─ klien Tidak Setuju (link) + catatan → CLIENT_REJECTED (baru, giliran Marketing)
CLIENT_REJECTED ├─ Marketing tambah catatan "yang kurang" → "Teruskan ke Estimator" → DRAFT versi berikutnya
                └─ Marketing Batalkan (klien mundur)
```

| # | Keputusan |
|---|---|
| K8 | **CEO tidak lagi menyetujui** (R8): PM ACC → langsung READY_TO_SEND. `WAITING_CEO` & `APPROVED_INTERNAL` jadi status lama (`@deprecated`, seperti CEO_REVIEW); migrasi data memindahkan baris yang masih di status itu ke READY_TO_SEND (+ audit). Tahap review `CEO` di `QuotationItemReview` tidak dipakai lagi; inbox `quotation-ceo` dihapus. |
| K9 | **Catatan CEO = informasi saja** (Q6): CEO **tidak bisa** mengembalikan RAB ke Estimator. Notifikasi info ke CEO saat **RAB Proyek** di-ACC PM. Tabel baru append-only `quotation_notes` (quotation, versi, penulis, catatan); catatan tampil di halaman RAB untuk Estimator, PM, Marketing + notifikasi ke mereka. Tidak memblokir alur. |
| K10 | **Tanpa tombol "Kirim ke Marketing"** (R9): `sendToMarketing()` & route-nya dihapus; notifikasi `QuotationReadyToSend` ke Marketing dikirim saat PM ACC. |
| K11 | **Tidak Setuju di link klien** (R10, Q7): untuk **semua tipe RAB**. Tombol "Tidak Setuju" + **catatan wajib** di `penawaran/{token}` (tetap `throttle:30,1`, respons whitelist, audit IP/perangkat seperti Setuju). Status → `CLIENT_REJECTED`, notifikasi P1 ke Marketing pemilik lead + antrean Marketing "Ditolak klien". Marketing menambah catatan → "Teruskan ke Estimator" (= `closeVersion`, DRAFT versi baru; catatan klien + Marketing tampil sebagai temuan untuk Estimator). Tombol manual "Klien Menolak" yang lama tetap ada untuk penolakan lewat WA/telepon. |

### D. Keuangan

| # | Keputusan |
|---|---|
| K12 | **Jenis pembayaran jelas** (R11): di Verifikasi Pembayaran (tabel + kartu HP) dan di dialog Verifikasi, chip jenis: "DP", "Termin 2 dari 4 · 30%", "Pelunasan", "Jasa Desain", "Jasa Survey", "Pekerjaan Tambahan" + nama proyek. Dari `invoice.termin` (eager-load), tanpa kolom baru. |
| K12d | **Foto bukti bayar** (R21): di dialog "Tandai Klien Sudah Bayar" (invoice & baris termin) Marketing bisa **mengunggah foto bukti — opsional**, maks. 3 foto (jpg/png/webp, ≤ 5 MB), di samping link & catatan yang sudah ada. Disimpan di disk **privat** (bukan publik), diperkecil ≤ 2000 px + EXIF dibuang (pola `PortfolioPhotoService`), dibuka lewat route ber-izin (Finance, CEO, Marketing — Policy invoice). Tabel baru `invoice_payment_proofs` (invoice, path, pengunggah, waktu, percobaan ke-) **append-only**: bila Finance menolak lalu Marketing mengirim ulang, foto lama tetap tersimpan sebagai riwayat. Finance melihat foto (pratinjau, klik untuk perbesar) di antrean Verifikasi & dialog Verifikasi. |
| K12c | **Section "Informasi Pembayaran" di detail lead** (R20): satu kartu di `crm.leads.show` berisi **ringkasan** (total tagihan, sudah lunas, menunggu konfirmasi Finance, belum dibayar, terlambat) dan **daftar semua pembayaran klien itu**: Jasa Survey, Jasa Desain, DP, Termin n dari N · %, Pelunasan, Pekerjaan Tambahan — per baris: no. invoice, nominal, jatuh tempo / jadwal, status + menunggu siapa (**Belum ditagih** · Marketing → **Belum dibayar** · Klien → **Menunggu konfirmasi** · Finance → **Lunas**), tanggal lunas, rekening tujuan, tautan PDF. Termin yang belum ber-invoice ikut tampil sebagai "Belum ditagih" (dari `termins` proyek lead itu). Data dibangun sekali di service (mis. `InvoiceService::leadPayments(Lead)`), dipakai ulang bila nanti perlu di Detail Proyek. Kartu HP untuk tiap baris. **Yang melihat: CEO, Marketing, dan PM proyek lead itu** (Q17) — Arsitek & Estimator tidak menerima props-nya sama sekali (difilter di controller, bukan hanya disembunyikan). |
| K12b | **Status konfirmasi menyebut pembayaran apa** (R17): status invoice MENUNGGU_VERIFIKASI tampil sebagai "Menunggu Konfirmasi Pembayaran **DP · Proyek Kopi Oz** · Finance" (jenis + proyek, atau nama klien bila belum ada proyek, mis. Jasa Desain). Kalimat yang sama dipakai di judul notifikasi ke Finance, item "Perlu Tindakan" Finance, dan judul dialog Verifikasi — dibangun sekali (satu helper label invoice), bukan per halaman. |

### E. Buka Proyek (mengubah Sprint 12 #19)

| # | Keputusan |
|---|---|
| K13 | **PM yang meng-ACC RAB Proyek itu yang Buka Proyek** (R12, Q8): antrean "Proyek siap dibuka" + notifikasi ke PM tersebut (diambil dari `quotation_approvals` versi yang disetujui klien; Asisten PM yang meng-ACC → PM proyeknya belum ada, jadi antrean jatuh ke semua PM). Route `role:PM` + Policy "hanya PM peng-ACC" (SUPERADMIN tetap lewat god-mode). CEO tidak lagi membuka, hanya dapat notifikasi saat proyek dibuka. |
| K14 | **Form Buka Proyek**: Nama Proyek\*, PM\* (default diri sendiri), Asisten PM, **Kepala Tim**\* (pilih dari **semua karyawan aktif** atau **ketik nama sendiri** — orang luar/vendor), **Pengawas (QA/QC)** (user ber-role QA), **Tanggal Buka Proyek**\* (default hari ini), Tanggal Mulai\*, **Tanggal Selesai** (opsional, ≥ tanggal mulai). |
| K15 | Kolom baru `projects`: `team_lead_id` (FK users, nullable) + `team_lead_name` (string, nullable — salah satu wajib), `supervisor_id` (FK users, nullable), `opened_on` (date). `end_date` sudah ada. Tampil di Detail Proyek & bisa diubah lewat Edit Proyek. Peran Pengawas dalam pekerjaan (daily target, laporan mingguan, DP pengawas) dibangun di **Sprint 24**. |

### F. Timeline (R16)

| # | Keputusan |
|---|---|
| K16 | **Ganti nama tampilan saja** (Q11): semua teks yang dilihat user "Milestone" → "Timeline" (halaman, dialog, notifikasi, PDF, QA, inbox, breadcrumb). Kode/tabel/route tetap `milestone`. |
| K17 | Kolom baru `milestones.start_date`, **wajib untuk timeline baru** (Q12); `target_date` tampil sebagai "Tanggal Selesai". Validasi mulai ≤ selesai. Data lama diisi otomatis: tanggal selesai timeline sebelumnya +1 hari, atau tanggal mulai proyek untuk timeline pertama. Gantt menggambar batang mulai → selesai. Overdue tetap dari tanggal selesai. |

### G. Desain via WhatsApp (R18 — membatalkan tombol Sprint 22)

Alur baru desain dari RAB Jasa Desain:

```
MENUNGGU_BAYAR → (Finance verifikasi invoice) MENUNGGU_PENUGASAN
  → (Kepala Desain menugaskan — dialog tetap) DESAIN
  → semua komunikasi di grup WA; sistem menyimpan link grup WA + link desain
  → "Desain Disetujui Klien" → ACC_DESAIN (lanjut ke RAB Proyek seperti sekarang)
```

| # | Keputusan |
|---|---|
| K18 | **Yang disimpan hanya link grup WA + link desain**: kolom baru `designs.whatsapp_group_url` (http/https, `wa.me`/`chat.whatsapp.com`), link desain tetap `design_urls`. Kartu "Brief Desain" diganti kartu **"Link Desain"** berisi dua isian itu (+ tombol "Buka Grup WA"). Jenis Project, Status manual, Catatan Brief, Problem **tidak tampil lagi** untuk desain alur Sprint 12 (kolom & data lama tetap, tidak dihapus). Dialog penugasan Kepala Desain (PIC, asisten, tanggal mulai, target hari) **tetap** — KPI keterlambatan desain tetap berjalan. |
| K19 | **Satu tombol: "Desain Disetujui Klien"** — bisa ditekan langsung dari DESAIN (juga dari WAITING_ACC_DESAIN / REVISI_DESAIN untuk data lama), syarat minimal satu link desain tersimpan. Boleh ditekan **Marketing pemegang lead dan tim desain** (PIC, asisten, Kepala Desain) — Q14. **Siapa yang menekan disimpan**: kolom baru `designs.client_acc_by` (FK users) + waktu (`client_acc_at`), tampil "Disetujui klien — dicatat oleh {nama}, {tanggal jam}" di halaman desain & lead. Audit + notifikasi + log pipeline lead seperti `markClientApproved()` sekarang. |
| K19b | **Siapa yang menyimpan link juga dicatat**: `designs.links_updated_by` + `links_updated_at` (link grup WA / link desain), tampil di kartu "Link Desain" ("Terakhir disimpan oleh …"). Link grup WA boleh diisi Marketing pemegang lead atau tim desain — Q15. |
| K20 | **Dihapus** (UI, route, antrean, notifikasi): "Desain Siap Dikirim" + antrean Marketing `design-send` + notifikasi `design_ready_to_send` (Sprint 22), "Kirim Desain ke Klien" + dialog WA (Sprint 22 K6), "Minta Revisi". Kolom `ready_for_client_at`, `ready_note`, `sent_to_client_at`, `revision_count` tetap di tabel (data lama), tidak diisi lagi. Antrean arsitek "Desain sedang Anda kerjakan" tetap. |
| K21 | **Diskusi Desain tidak berubah** (termasuk Marketing pemegang lead ikut menulis, Sprint 22 K8). |

## 4. Jawaban user (2026-10-13)

| # | Pertanyaan | Jawaban |
|---|---|---|
| Q1 | Popup sukses di tengah, error tetap toast? | Setuju |
| Q2 | Status + divisi — status yang mana? | **Semua** info status: tambah "menunggu siapa" (K3) |
| Q3 | m/lari pilih P atau T/L; m2 otomatis P × T/L? | Setuju |
| Q4 | Kartu review PM digabung ke tabel RAB; bagian pekerjaan tetap? | Setuju |
| Q5 | Item ✔ tidak dicek ulang; item yang diubah dicek ulang? | Setuju |
| Q6 | Catatan CEO menarik RAB? | **Tidak** — informasi saja, tidak bisa mengembalikan ke Estimator |
| Q7 | Tidak Setuju untuk semua tipe RAB, catatan wajib? | Ya, semua |
| Q8 | PM mana yang Buka Proyek? | PM yang meng-ACC RAB Proyek tersebut |
| Q9 | Kepala Tim dari mana? | Semua karyawan untuk saat ini + bisa isi sendiri (orang luar/vendor). Tugas QA/QC → Sprint 24 |
| Q10 | Tanggal Buka vs Tanggal Mulai? | Dua tanggal berbeda. *Asumsi:* Tanggal Mulai tidak boleh sebelum Tanggal Buka — koreksi bila salah |
| Q11 | Rename hanya tampilan? | Ya |
| Q12 | Tanggal mulai timeline wajib, data lama diisi otomatis? | Ya |
| Q13 | Titik ribuan di semua input uang? | Setuju |
| Q14 | Siapa yang boleh menekan **"Desain Disetujui Klien"**? | Keduanya (Marketing pemegang lead + tim desain), **simpan siapa yang menekan** (K19, K19b) |
| Q15 | Siapa yang mengisi **link grup WA**? | Marketing pemegang lead atau tim desain — setuju |
| Q16 | Satuan m, pcs, dll? | **Hanya m/lari** yang ditanya lewat popup kecil (P atau T/L); **semua satuan lain langsung P × T/L** (K5) |
| Q17 | Siapa yang boleh melihat **Informasi Pembayaran** di detail lead? | CEO, Marketing, PM proyek itu — setuju |
| Q18 | "Aksi dinaikkan ke atas" maksudnya? | **Tombol aksi** seperti cetak invoice, kirim ke klien, dll ditaruh di atas halaman (K4b) |

## 5. Sub-plan

| # | Sub | Isi | Catatan |
|---|---|---|---|
| 01 | UX global | `SuccessDialog`, `GlobalLoadingOverlay`, `MoneyInput` (+ ganti ±21 input uang), `waitingOn.ts` + prop `StatusChip` di semua daftar/detail, tombol aksi pindah ke atas halaman detail (K4b) | Overlay tidak boleh muncul untuk reload latar belakang; tombol aksi pindah di banyak halaman detail |
| 02 | Verifikasi & bukti pembayaran | Chip jenis + termin ke-/persen + proyek (tabel, kartu HP, dialog); status/notifikasi/inbox "Menunggu Konfirmasi Pembayaran DP · Proyek X" (K12b); unggah foto bukti opsional + `invoice_payment_proofs` + route ber-izin + pratinjau untuk Finance (K12d) | Unggah file → `/security-review` |
| 03 | RAB Builder | `qty_source` (migrasi, model, Form Request, UI saran volume: popup m/lari P atau T/L, satuan lain P × T/L; tetap bisa diubah); baris aksi "Simpan sebagai Draft" + "Kirim ke PM" + keterangan, Kirim nonaktif bila belum disimpan (K5b) | Data lama `qty_source = null` (manual) |
| 04 | Review menyatu + ✔ terbawa | Hapus kartu review terpisah, kolom review di `RabBuilder`, `carried_from_version`, aturan `review()` | Pencocokan item antar versi (item ditulis ulang tiap simpan) |
| 05 | Alur RAB baru | Hapus tahap CEO & "Kirim ke Marketing", migrasi status, `quotation_notes` + catatan CEO, notifikasi, inbox, dashboard RAB | ±23 file + test Sprint 12 |
| 06 | Tidak Setuju di link klien | Status `CLIENT_REJECTED`, route publik, antrean & aksi Marketing "Teruskan ke Estimator" | Route publik yang menulis data → `/security-review` |
| 07 | Buka Proyek oleh PM | Route + Policy, antrean PM peng-ACC, form baru, kolom `projects` baru, Detail/Edit Proyek | Test RBAC Buka Proyek dibalik (CEO → PM) |
| 08 | Timeline | Teks Milestone → Timeline, `start_date` + isi data lama, form, Gantt | Teks tersebar (notifikasi, PDF) |
| 09 | Desain via WA | `whatsapp_group_url`, kartu "Link Desain", satu tombol "Desain Disetujui Klien", hapus Siap Dikirim / Kirim ke Klien / Minta Revisi + antrean & notifikasinya | Test Sprint 22 dihapus/diganti; kolom `client_acc_by/at`, `links_updated_by/at` |
| 10 | Informasi Pembayaran lead | Service ringkasan pembayaran per lead (invoice + termin belum ditagih), kartu di detail lead + kartu HP, RBAC props (CEO, Marketing, PM proyek) | — |
| 11 | Uji | Pest + `npm run build` + Pint, uji alur di browser | — |
| 12 | Dokumen | Matriks notifikasi Sprint 18, `simulasi-alur-klien.md`, golden rule #11 `CLAUDE.md`, `security-standards.md` §4 (urutan approval), catatan pembatalan di `sprint-22-desain-siap-kirim.md` | — |

Urutan: 01 → 02 → … → 12. Sub 07–08 adalah
fondasi Sprint 24 (Pengawas, Timeline bertanggal) — harus selesai sebelum
Sprint 24 dimulai.

## 6. Hasil pengerjaan (2026-10-14)

Dikerjakan paralel per domain (RAB · Keuangan · Proyek · Desain · UX global).

| Sub | Status | Catatan / keputusan tambahan |
|---|---|---|
| 01 UX global | ✅ | `SuccessDialog` + `GlobalLoadingOverlay` di `AppLayout`; `MoneyInput` di semua input Rupiah; `StatusChip domain=` + peta `lib/waitingOn.ts` (domain baru: `staffLoan`, `supplierDebt`, `assetInstallment`, `payroll`); `PageHeader step` + `MoreActions`. Aksi per baris & tombol simpan form tetap di tempatnya. |
| 02 Verifikasi & bukti bayar | ✅ | Chip jenis "Termin n dari N · %" (juga RAB Tambahan bertermin); kalimat K12b dari `Invoice::confirmationLabel()` dipakai chip, notifikasi, Perlu Tindakan, dialog; notifikasi & antrean membuka dialog Verifikasi (`?verify=`). Foto: maks. 3, WebP ≤ 2000 px + thumbnail 480 px, disk privat, `invoice_payment_proofs` append-only + kolom tambahan `invoices.proof_attempt` (memisahkan foto kiriman sekarang vs riwayat). Route `finance.invoices.proofs.show`. |
| 03 RAB Builder | ✅ | `qty_source` disimpan hanya bila cocok dengan dimensi (server tidak menimpa volume). Baris lama (`null`) tetap manual sampai "↺ Hitung dari P/T/L". m/lari dikenali dari kode satuan `Unit::METER_LARI`. |
| 04 Review menyatu | ✅ | `QuotationReviewPanel` dihapus → `RabReview` di tabel; ✔ terbawa dicocokkan lewat `item_fingerprint` (review lama tanpa fingerprint → dicek ulang sekali). |
| 05 Alur RAB baru | ✅ | Migrasi `move_quotations_off_ceo_approval` (audit `quotation.ceo_stage_removed`). Notifikasi baru `quotation_project_approved` (P4 info ke CEO) & `quotation_note_added` (P3); `quotation_awaiting_ceo` `@deprecated`. KPI `pm_review_escaped_count` didefinisikan ulang: "RAB di-ACC lalu ditolak klien" (data CEO lama tetap terhitung). |
| 06 Tidak Setuju di link | ✅ | Route `public.quotation.reject` (`penawaran/{token}/tolak`), `quotations.forwardToEstimator`; `quotation_notes.kind` = CEO / CLIENT / MARKETING. Antrean Marketing `quotation-client-rejected`. |
| 07 Buka Proyek oleh PM | ✅ | `project_openings.opener_id` + status `MENUNGGU_PM` (nilai lama `MENUNGGU_CEO` tetap dibaca); PM nonaktif → semua PM boleh. Kepala Tim dari semua akun aktif (kecuali SUPERADMIN) atau ketik nama. `project_opened` juga ke Marketing, Pengawas, Kepala Tim. Belum ada notifikasi saat Pengawas diganti lewat Edit Proyek (hanya audit) → Sprint 24. |
| 08 Timeline | ✅ | Semua teks UI/notifikasi/PDF "Milestone" → "Timeline"; `start_date` wajib untuk timeline baru, data lama diisi otomatis; Gantt & kalender mulai → selesai. |
| 09 Desain via WA | ✅ | Route baru `design.links.update`; `design.markClientApproved` = `role:MARKETING|DESIGNER` + `DesignPolicy::approveForClient`. Route `markReady`/`sendToClient`/`requestRevision` dihapus; tipe notifikasi lamanya `@deprecated` (P4). Link tidak bisa disimpan sebelum penugasan. No. HP klien tidak lagi dikirim ke halaman desain. |
| 10 Informasi Pembayaran lead | ✅ | `InvoiceService::leadPayments()` + `PaymentInfoCard`; juga menampilkan RAB yang sudah disetujui klien tapi belum ditagih. Props hanya untuk CEO, Marketing, SUPERADMIN, PM proyek lead itu. |
| 11 Uji | ✅ sebagian | `php artisan test` 2129 lulus; build & Pint bersih; cek browser RAB Builder (volume otomatis, Kirim ke PM nonaktif saat belum disimpan). Belum: uji alur penuh per role di browser, `/security-review`. |
| 12 Dokumen | ✅ | Golden rule #11, `security-standards.md` §4, `design-standards.md` §2, matriks notifikasi Sprint 18, `simulasi-alur-klien.md`, catatan pembatalan Sprint 22. |

