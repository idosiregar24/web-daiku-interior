# Sprint 19 — Konfirmasi Bayar & Jadwal Survey Tanpa Pindah Menu

> Status: **kode selesai 2026-10-08** (Sub 01–05 + Sub 06 test/build/dokumen; K1–K4 dijawab user). Tersisa: uji alur di server live & deploy (Sub 06).
> Sumber: masukan user setelah uji alur di server live (lead #46, RAB #42).
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Tidak ada
> route publik baru dan tidak ada perubahan role gate. Alur Sprint 12/17
> tetap: Finance satu-satunya yang memverifikasi pembayaran, dan survey
> luar Pekanbaru tetap baru SIAP setelah RAB Jasa Survey diverifikasi.

## 1. Masukan & hasil penelusuran

| # | Masukan user | Kondisi kode sekarang | Sub |
|---|---|---|---|
| M1 | "Tandai klien sudah bayar": link bukti bayar jadi opsional | `SubmitInvoiceProofRequest` mewajibkan `payment_proof_url` (`required`, `url:http,https`). Kolom DB sudah `nullable`. | 01 |
| M2 | Setelah Finance verifikasi, Marketing tidak dapat notifikasi | Notifikasi **dibuat** (`InvoiceService::verify()`, ke penerbit + Marketing lead), tetapi tipenya `invoice_verified` = **P3 (senyap)**. Di server, worker antrean mati sampai 2026-10-08, jadi push tidak pernah terkirim. **Celah:** P1 `lead_survey_ready` hanya muncul bila survey sudah dibuat **sebelum** bayar. Bila RAB Jasa Survey dibayar dulu, Marketing hanya mendapat P3. | 02 |
| M3 | Dari detail RAB ada jalan ke lead untuk menjadwalkan survey tanpa pindah menu | `Quotation/Show.tsx` hanya punya link ke lead untuk RAB Proyek ("Buka proyek dari halaman lead"). Form survey (`SurveyFormFields`) hanya ada di halaman lead. | 03 |
| M4 | Jadwal survey dibuat → CEO & PM dapat notifikasi | Belum ada. `LeadService::scheduleSurvey()` / `updateSurvey()` / `cancelSurvey()` tidak memberi tahu siapa pun. Lead belum punya PM di tahap survey. | 04 |
| M5 | Di lead, "RAB versi 1" bisa diklik ke detail RAB | Kartu "Riwayat RAB" sudah ber-link. Kartu tahap **"Quotation"** (`CRM/Show.tsx` `StageRow`) hanya membaca `lead.quotation` = RAB **Proyek**, jadi lead yang baru punya RAB Survey/Desain melihat teks tanpa link. | 05 |

## 2. Keputusan (dijawab user 2026-10-08)

| # | Pertanyaan | Jawaban |
|---|---|---|
| K1 | PM mana yang diberi tahu soal jadwal survey? | **Semua PM** (role `PM`, aktif). Tidak ada kolom PM pendamping. Asisten PM tidak ikut. |
| K2 | Tombol "Jadwalkan Survey" di detail RAB | **Dialog langsung di halaman RAB**, disimpan lewat route survey lead yang sudah ada. Link "Buka halaman lead" tetap ada. |
| K3 | Prioritas "Pembayaran Terverifikasi" | **P2 (berbunyi)** untuk semua invoice. Ditambah **P1 "Jadwalkan Survey"** untuk RAB Jasa Survey yang belum punya survey terbuka. |
| K4 | Lead → detail RAB | **Kartu tahap "Quotation"** menampilkan RAB yang sedang berjalan, apa pun jenisnya. |

## 3. Sub-plan

| # | Sub | Isi | Task |
|---|---|---|---|
| 01 | Tandai klien sudah bayar | Link bukti opsional + catatan pembayaran | 7 |
| 02 | Notifikasi verifikasi pembayaran | `invoice_verified` → P2, P1 baru `survey_to_schedule` + antrean | 7 |
| 03 | Jadwalkan survey dari detail RAB | Kartu "Klien & langkah berikutnya" + dialog survey | 7 |
| 04 | Notifikasi jadwal survey | CEO + semua PM saat dijadwalkan / diubah / dibatalkan / siap | 7 |
| 05 | Kartu tahap RAB di lead | RAB aktif apa pun jenisnya + link detail | 4 |
| 06 | Uji & dokumentasi | Test, build, uji alur di server | 5 |

Cara menyuruh: **"Kerjakan Sprint 19 Sub 1"** … **Sub 6**. Sub 01, 02 dan 05
saling lepas. Sub 03 memakai P1 dari Sub 02 (link notifikasinya membuka
dialog). Sub 04 boleh dikerjakan sebelum atau sesudah Sub 03.

---

## Sub 01 — Tandai klien sudah bayar (M1) ✅ 2026-10-08

> Catatan pelaksanaan:
> - Migrasi `2026_10_08_123621_add_payment_note_to_invoices_table` (`payment_note` setelah `payment_proof_url`, `down()` drop kolom). Route & gate tidak berubah (`finance.invoices.proof`, `role:MARKETING|FINANCE`); nama method/route `submitProof`/`proof` dipertahankan.
> - `submitProof(Invoice, array, User)`: URL & catatan di-trim, kosong → `null`. Semua pemanggil ikut diubah (controller, `DemoDataSeeder`, `Demo/WorkflowScenarioSeeder`, test lama). Pesan "invoice terkunci" jadi "Pembayaran hanya bisa ditandai…".
> - Notifikasi Finance: + " Tanpa link bukti — cocokkan dengan mutasi rekening." bila tanpa link, + " Catatan: …" bila ada catatan. Tipe/prioritas tidak disentuh (Sub 02).
> - UI: komponen baru `InvoicePaymentInfo` (`InvoiceDialogs.tsx`) — link bukti atau "Tidak ada link bukti — cocokkan dengan mutasi rekening." + catatan; dipakai di kolom Status daftar Invoice/Verifikasi (MENUNGGU_VERIFIKASI; TERVERIFIKASI hanya bila ada link/catatan, tanpa peringatan) dan di dialog Verifikasi Pembayaran. Ikon tombol `Upload` → `Banknote`.
> - Teks ikut disesuaikan: antrean "Invoice menunggu konfirmasi bayar", deskripsi antrean Finance "Cocokkan pembayaran dengan mutasi rekening.", flash sesudah terbit invoice, judul halaman Verifikasi, `LeadTimeline` ("menunggu konfirmasi bayar klien"), label penolakan "Ditolak Finance: …". Notifikasi `reject()` ("Bukti Bayar Ditolak") **tidak** diubah — di luar `submitProof()`.
> - Tes baru di `tests/Feature/Finance/InvoiceTest.php` (blok "Tandai klien sudah bayar"): tanpa link sukses + pesan & audit, link non-http ditolak (3 kasus), catatan tersimpan/ter-audit/terkirim & tampil di props `finance.invoices.index` + `.verification`, catatan >500 ditolak. RBAC lama (`only Marketing and Finance attach proofs`) tetap lulus.


Tujuan: Marketing/Finance bisa menandai invoice "sudah dibayar klien"
walau belum ada link bukti, misalnya klien hanya mengabari lewat telepon.
Finance tetap mencocokkan dengan mutasi rekening sebelum verifikasi.

- [x] Migrasi `add_payment_note_to_invoices_table`: `payment_note` `string(500)` nullable.
      Model `Invoice` `$fillable` ditambah.
- [x] `SubmitInvoiceProofRequest`: `payment_proof_url` → `nullable|string|max:500|url:http,https`.
      Tambah `payment_note` `nullable|string|max:500`.
- [x] `InvoiceService::submitProof()` menerima array `{payment_proof_url?, payment_note?}`
      (bukan string). URL kosong disimpan `null`. Audit log mencatat keduanya.
      Notifikasi Finance menyebut "tanpa link bukti, cek mutasi rekening" bila URL kosong.
- [x] UI (`InvoiceDialogs.tsx` / `InvoiceProofButton`): tombol & judul dialog
      **"Tandai Klien Sudah Bayar"**, kolom "Link bukti bayar" tanpa bintang,
      kolom baru "Catatan pembayaran" (mis. "Transfer BCA a.n. Budi, 12 Okt").
      Zod mengikuti aturan server.
- [x] Dialog verifikasi Finance (`Finance/Invoices/Index.tsx`): tampilkan catatan.
      Bila link kosong, tampilkan "Tidak ada link bukti, cocokkan dengan mutasi rekening".
- [x] Antrean & teks yang menyebut "bukti bayar" disesuaikan bila perlu
      ("Invoice menunggu konfirmasi bayar").
- [x] Test: kirim tanpa link (sukses, status MENUNGGU_VERIFIKASI), link bukan http
      ditolak, catatan tersimpan & tampil ke Finance, RBAC tetap `MARKETING|FINANCE`.

## Sub 02 — Notifikasi verifikasi pembayaran (M2) ✅ 2026-10-08

> Catatan pelaksanaan: scope baru `Quotation::surveyToSchedule(?User)` (RAB SURVEY tidak batal/ditolak,
> ada invoice TERVERIFIKASI, `lead_survey_id` kosong atau survey-nya BATAL; survey SELESAI tetap tertaut
> sehingga tidak muncul lagi) dipakai listener, antrean `survey-schedule` dan panel Sub 03.
> `MarkSurveyReadyOnInvoiceVerified` mengirim P1 `survey_to_schedule` hanya bila setelah penautan
> tetap tidak ada survey, jadi satu pembayaran = satu P1. Teks `invoice_verified` + langkah berikutnya
> (`InvoiceService::nextStepAfterPayment()`). Test: `tests/Feature/CRM/SurveySchedulingTest.php`;
> contoh P3 di `WebPushTest` diganti `quotation_approved`.

- [x] `NotificationType::InvoiceVerified` → `priority()` **P2 (ActionRequired)**.
      Perbarui matriks di `sprint-18-notifikasi.md` §3.
- [x] Tipe baru `survey_to_schedule` (P1, kategori Lead & Klien), judul
      **"Survey Lunas — Jadwalkan Sekarang"**. Dikirim di listener
      `MarkSurveyReadyOnInvoiceVerified` bila invoice `JASA_SURVEY` terverifikasi
      dan lead **tidak** punya survey terbuka (DIJADWALKAN / MENUNGGU_BAYAR / SIAP).
      Penerima: Marketing pemegang lead.
- [x] Bila survey sudah ada (MENUNGGU_BAYAR → SIAP), tetap `lead_survey_ready` (P1).
      Pastikan Marketing tidak menerima dua P1 untuk satu pembayaran.
- [x] `NotificationTarget`: `survey_to_schedule` → `quotations.show` RAB Survey itu
      dengan `?survey=new` (membuka dialog Sub 03). Sebelum Sub 03 selesai: ke lead.
- [x] `ActionInboxService`: antrean baru **"Survey lunas, belum dijadwalkan"**
      (lead dengan invoice Jasa Survey TERVERIFIKASI dan tanpa survey terbuka),
      `routeName` → menu CRM. Wajib ada karena test arsitektur Sprint 18 meminta
      setiap tipe P1 punya antrean.
- [x] Teks `invoice_verified` menyebut langkah berikutnya per jenis
      (Survey: "jadwalkan survey", Desain: "desain dibuka", DP: "menunggu CEO buka proyek").
- [x] Test: P2 untuk `invoice_verified`, P1 `survey_to_schedule` hanya bila belum ada
      survey terbuka, tidak dobel P1, antrean muncul & hilang setelah survey dibuat,
      `NotificationCatalogTest` / `NotificationAuditTest` hijau.

## Sub 03 — Jadwalkan survey dari detail RAB (M3, K2) ✅ 2026-10-08

> Catatan pelaksanaan: props `canOpenLead` (role yang boleh membuka lead) + `surveyPanel`
> (`QuotationController::surveyPanel()`, CEO/Marketing, hanya RAB SURVEY). Komponen
> `Components/modules/quotation/QuotationNextStepCard.tsx` (kartu + `ResponsiveDialogContent` +
> `SurveyFormFields`, `is_outside_pekanbaru` selalu true, POST/PUT ke `crm.surveys.*`). Link lama
> "Buka proyek dari halaman lead" di kartu "Disetujui Klien" **dibiarkan** (tidak dipindah) —
> kartu baru menambah link "Buka halaman lead" untuk semua jenis RAB.

- [x] `QuotationController::show` mengirim prop `surveyPanel` (hanya untuk role
      yang boleh menulis survey, `CEO|MARKETING`): status survey terbuka lead
      (urutan, tanggal, alamat, status), `canSchedule`, `canReschedule`, alamat
      & Maps default lead.
- [x] Kartu **"Klien & langkah berikutnya"** (`SectionCard`) di `Quotation/Show.tsx`
      untuk **semua** jenis RAB: nama klien, link **"Buka halaman lead"**
      (`crm.leads.show`). Link lama "Buka proyek dari halaman lead" dipindah ke sini.
- [x] RAB `SURVEY` + invoice-nya TERVERIFIKASI + tanpa survey terbuka:
      tombol utama **"Jadwalkan Survey"** membuka `ResponsiveDialogContent` berisi
      `SurveyFormFields`, POST ke `crm.surveys.store` (route & Form Request yang ada).
      `is_outside_pekanbaru` terkunci benar karena RAB Jasa Survey = luar Pekanbaru.
- [x] Survey sudah ada (MENUNGGU_BAYAR / SIAP / DIJADWALKAN): tampilkan jadwal +
      status bayar, tombol **"Atur Ulang Jadwal"** → `crm.surveys.update`.
- [x] RAB Survey belum lunas: info "Jadwal survey bisa dibuat setelah pembayaran
      diverifikasi Finance" (tanpa tombol).
- [x] Query `?survey=new` membuka dialog otomatis (dari notifikasi P1 Sub 02).
      Setelah tersimpan: `preserveScroll`, toast sukses, dialog tertutup, kartu terbarui.
- [x] Test: prop hanya untuk CEO/Marketing (Estimator/PM tidak menerima), simpan dari
      halaman RAB membuat survey di lead yang benar, `scheduleSurvey()` menautkan &
      langsung SIAP bila sudah lunas (perilaku Sprint 17 Sub 02 tetap).

## Sub 04 — Notifikasi jadwal survey ke CEO & semua PM (M4, K1) ✅ 2026-10-08

> Catatan pelaksanaan: `LeadService::announceSurvey()` (CEO + PM aktif, tanpa pelaku). Survey yang
> dijadwalkan sudah lunas (langsung SIAP) hanya mengirim `survey_scheduled`, tidak dobel dengan
> `survey_confirmed` (`markSurveyReady(..., announce: false)`). `updateSurvey()` menerima `$actor`
> (controller ikut diubah) dan hanya memberi tahu bila tanggal/alamat berubah.

- [x] Tipe baru (kategori Lead & Klien), penerima **CEO + semua PM aktif**
      (`notifyRoles(['CEO', 'PM'])`), pelaku tidak diberi tahu dirinya sendiri:
  - `survey_scheduled` **P2**: "Survey dijadwalkan: {klien}, {tanggal jam}, {alamat}".
  - `survey_rescheduled` **P2**: menyebut jadwal lama → baru (hanya bila tanggal/alamat berubah).
  - `survey_cancelled` **P2**: dengan alasan.
  - `survey_ready`: saat MENUNGGU_BAYAR → SIAP, CEO & PM diberi tahu **P3**
    ("survey #n jadi berangkat {tanggal}").
- [x] Dipanggil dari `LeadService::scheduleSurvey()`, `updateSurvey()`, `cancelSurvey()`,
      `markSurveyReady()` di dalam transaksi (afterCommit sudah dijamin `NotificationService`).
- [x] Survey **luar Pekanbaru yang masih MENUNGGU_BAYAR**: `survey_scheduled` dikirim
      dengan keterangan "menunggu pembayaran", supaya CEO/PM tahu rencananya.
- [x] `NotificationTarget` → `crm.leads.show` (lead survey itu). CEO dan PM sudah
      boleh membuka lead (`role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM`), jadi tidak ada
      perubahan akses. Cek ulang `LeadController::show` tidak menyaring lead untuk PM.
- [x] `metadata` memuat `lead_id`, `lead_survey_id` (tag push: perubahan jadwal
      menggantikan notifikasi lama di HP).
- [x] Lonceng: tipe baru terdaftar di `NotificationType::category()` / `priority()`;
      push ikut aturan Sprint 18 (P2 berbunyi, P3 senyap).
- [x] Test: penerima tepat (CEO + PM, bukan Asisten PM/Marketing lain), reschedule
      tanpa perubahan tidak mengirim, batal mengirim dengan alasan, katalog/audit hijau.

## Sub 05 — Kartu tahap RAB di halaman lead (M5, K4)

> Catatan pelaksanaan (2026-10-08): prop-nya jadi **`activeQuotations`** (daftar, satu
> per jenis — bukan satu `activeQuotation`), dibangun dari relasi `quotations` yang sudah
> di-load (tanpa query tambahan; kolom `valid_until` ditambahkan ke eager load-nya).
> Field: `id, type, title, version, status, total_amount, valid_until`; dikirim ke semua
> role yang boleh membuka lead (datanya sama dengan "Riwayat RAB"). Kartu "RAB" menampilkan
> item pertama (judul, link "Versi n · Rp …", chip status, masa berlaku, "Lihat detail"),
> jenis lain sebagai baris kecil ber-link + chip. Baris "Proyek" & tombol "Catat Penolakan
> Klien" tetap membaca `lead.quotation`. Test: `tests/Feature/CRM/LeadActiveQuotationsTest.php`.
> `npx tsc --noEmit` bersih untuk file Sub 05; `npm run build` penuh menyusul di Sub 06.

- [x] `LeadController::show` mengirim `activeQuotations`: RAB terbaru lead per jenis yang belum
      DIBATALKAN, prioritas Proyek > Desain > Survey (RAB Tambahan tidak dihitung).
      `lead.quotation` (RAB Proyek) tidak diubah karena dipakai pembaca lama.
- [x] `StageRow` "Quotation" diganti label **"RAB"**: judul RAB (`Quotation::title()`),
      "Versi n · Rp …" sebagai link ke `quotations.show`, ditambah "Lihat detail".
- [x] Bila lead punya beberapa jenis RAB, tampilkan baris kecil per jenis
      (Survey v1 ✓, Desain v2 …), masing-masing ber-link. "Riwayat RAB" tetap untuk versi lama.
- [x] Test: lead dengan hanya RAB Survey menampilkan link ke RAB itu, lead dengan
      RAB Proyek tetap seperti sebelumnya (`npm run build` penuh: Sub 06).

## Sub 06 — Uji & dokumentasi

- [x] `php artisan test` hijau, `npm run build` lulus, `vendor/bin/pint`.
- [x] Perbarui matriks notifikasi (`sprint-18-notifikasi.md` §3) dengan tipe baru & prioritas.
- [ ] Uji alur di server (lead baru luar Pekanbaru):
      Minta RAB Survey → Estimator → ACC klien → Marketing terbitkan invoice →
      **Tandai Klien Sudah Bayar tanpa link** → Finance verifikasi →
      HP Marketing berbunyi (P1 "Survey Lunas") → buka notif → dialog jadwal
      terbuka di RAB → simpan → HP CEO & PM berbunyi (P2 "Survey dijadwalkan").
- [ ] Di lead: kartu tahap RAB menampilkan RAB Survey v1 → klik → detail RAB.
- [ ] Deploy mengikuti `deploy/DEPLOY-AAPANEL.md` (migrasi baru: `php artisan migrate --force`;
      frontend berubah: `npm ci && npm run build`; lalu `queue:restart`).

## 4. Di luar cakupan (catat bila diminta kemudian)

- Unggah foto bukti bayar (file) sebagai ganti link.
- PM pendamping per survey (K1 memilih semua PM; bisa ditambah bila notifikasi terlalu ramai).
- Kalender survey bersama untuk CEO/PM.
