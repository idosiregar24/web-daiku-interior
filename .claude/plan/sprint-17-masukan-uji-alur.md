# Sprint 17 — Masukan Uji Alur Klien

> Status: **selesai 2026-10-07** (Sub 01–07; K2 dijawab user → Sub 06; masukan bukti bayar → Sub 07; K1, K3, K4 dijalankan sesuai usulan).
> Sumber: hasil uji alur oleh rekan user (mengikuti
> [`simulasi-alur-klien.md`](simulasi-alur-klien.md)) dengan lead #37
> "Ido Refael Siregar". Bentuknya 6 temuan, terdiri dari 3 bug dan 3
> permintaan UX.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Tidak ada
> perubahan RBAC. Alur bisnis Sprint 12 tetap; yang diperbaiki adalah
> celahnya.
>
> **Urutan:** kerjakan Sprint 17 **sebelum** Sprint 16, karena Sub 01–02
> adalah bug yang menghambat alur klien. Sprint 16 (penanda wajib & data
> lead) tidak menghalangi apa pun.

## 1. Temuan & hasil penelusuran (2026-10-07)

| # | Temuan penguji | Penyebab yang ditemukan | Sub |
|---|---|---|---|
| T1 | Tombol "Salin Link" tidak bisa | **Bug.** `navigator.clipboard` hanya tersedia di HTTPS atau `localhost`. Di `http://web-daiku-interior.test` nilainya `undefined`, error-nya ditelan `catch {}` kosong, sehingga tombol tidak bereaksi sama sekali. Ini juga terjadi di `RabHistoryCard` dan akan terjadi di server mana pun yang belum HTTPS. | 01 |
| T2 | "Ada error pada link RAB yang dikirim ke klien" | **Belum pasti.** Link yang sudah disetujui dibuka ulang di sini (curl + Chrome headless) dan tampil normal. Ada dua tersangka kuat: **(a)** link berbentuk `http://web-daiku-interior.test/penawaran/…`, domain lokal Laragon yang **tidak bisa dibuka dari HP klien** atau komputer lain ("situs tidak dapat dijangkau"); **(b)** `laravel.log` mencatat 4× `production.ERROR: No application encryption key` dari request web (`public/index.php`) pada 5–6 Okt, artinya `.env` sesekali tidak terbaca dan halaman menjadi 500. | 01 |
| T3 | Klien ACC RAB → Marketing harus dapat notifikasi, masuk "Perlu Tindakan", diarahkan terbitkan invoice | **Celah.** Notifikasi `invoice_to_issue` sudah dikirim (lead #37 ✓), tetapi `ActionInboxService` **tidak punya antrean** "RAB disetujui klien → terbitkan invoice". Begitu notifikasi dibaca, tugasnya hilang dari pandangan. | 03 |
| T4 | Format kirim penawaran dan invoice tidak berbeda | **Benar.** Keduanya memakai `pdf/layouts/letter` yang sama. Invoice jasa bahkan mencetak ulang seluruh tabel RAB. Bedanya hanya kata (PENAWARAN/INVOICE, OFF/INV, "Berlaku Sampai"/"Jatuh Tempo"), sehingga sekilas sama persis. | 05 |
| T5 | Kartu Survey lead #37 tidak berubah walau pembayaran survey sudah diverifikasi Finance | **Bug.** Survey #6 tetap `MENUNGGU_BAYAR` dengan `quotation_id = null`. RAB Jasa Survey #34 diminta **23:21** dan survey baru dijadwalkan **23:30**. Survey ↔ RAB hanya ditautkan di `QuotationService::request()` (mencari survey yang *sudah* menunggu bayar), tidak di `LeadService::scheduleSurvey()`. Akibatnya `MarkSurveyReadyOnInvoiceVerified` tidak menemukan survey saat invoice #14 diverifikasi. | 02 |
| T6 | Saat desain di-ACC klien, tampilkan info bahwa permintaan RAB Proyek sudah otomatis ke Estimator | **Sebagian sudah ada.** `DesignService::markClientApproved()` sudah otomatis meminta RAB Proyek, tetapi infonya hanya toast yang langsung hilang. Marketing tidak diberi notifikasi, tidak ada jejak di halaman lead/desain, dan toast tetap berbunyi "Estimator diminta…" walau RAB Proyek sudah berjalan sehingga permintaan baru tidak dibuat. | 04 |

**Temuan sampingan (dimasukkan ke Sub 01):** halaman publik
`penawaran/{token}` mengirim **seluruh daftar route Ziggy** (termasuk
`horizon.*`, `master-data.*`, `finance.*`) ke browser klien. Ini bukan
celah akses karena semua route tetap dijaga middleware, tetapi peta
sistem internal ikut terbuka. Halaman publik cukup memuat route publik.

## 2. Keputusan (perlu dijawab user, ✱ = usulan)

| # | Pertanyaan | Usulan |
|---|---|---|
| K1 | T2: link akan dibuka klien dari mana selama uji? | ✱ Uji dari HP butuh alamat yang bisa dijangkau HP: server staging (Sprint 9: deploy/HTTPS) **atau** sementara Laragon → *Share* (ngrok) / `php artisan serve --host=0.0.0.0` lewat IP LAN dengan `APP_URL` disesuaikan. Sistem menampilkan peringatan bila `APP_URL` masih lokal. |
| K2 | T3: RAB **Proyek** yang di-ACC klien juga masuk antrean Marketing? | **Dijawab user 2026-10-07: langsung** — Marketing diarahkan menerbitkan invoice DP sambil menunggu CEO Buka Proyek; invoice itu menempel ke termin DP saat proyek dibuka → [Sub 06](sprint-17/06-invoice-dp-lebih-awal.md). (Usulan awal ✱ — hanya notifikasi, DP dari termin — dikerjakan di Sub 03 lalu diganti.) |
| K3 | T4: seperti apa invoice yang "berbeda"? | ✱ Penawaran tetap format surat (Sprint 15). Invoice memakai **tata letak tagihan**: judul besar **INVOICE**, kotak "Ditagihkan kepada" + No./Tanggal/Jatuh Tempo/Ref. Penawaran, tabel **ringkas** (1 baris per kelompok/termin, bukan rincian item RAB), kotak **Total Tagihan**, kotak **Cara Pembayaran** (rekening), cap **BELUM DIBAYAR / LUNAS**, tanpa kalimat "Demikian penawaran…". |
| K4 | T5: data lama (lead #37) diperbaiki bagaimana? | ✱ Perintah sekali jalan `daiku:relink-surveys` (idempoten, `--dry-run`): tautkan survey `MENUNGGU_BAYAR` tanpa RAB ke RAB Jasa Survey lead yang sama, lalu tandai SIAP bila invoice-nya sudah terverifikasi. |

## 3. Sub-plan

| # | Sub-plan | Isi | Task |
|---|---|---|---|
| 01 | [Salin link & link klien](sprint-17/01-salin-link-link-klien.md) | Salin yang jalan di HTTP, peringatan `APP_URL` lokal, telusuri error app key, Ziggy publik dibatasi | 7 |
| 02 | [Survey ↔ RAB Jasa Survey](sprint-17/02-survey-rab-survey.md) | Tautan dua arah (urutan bebas), SIAP langsung bila sudah lunas, perbaikan data lama, kartu survey menampilkan status bayar | 7 |
| 03 | [Klien ACC → Perlu Tindakan Marketing](sprint-17/03-klien-acc-perlu-tindakan.md) | Antrean "Terbitkan invoice", notifikasi RAB Proyek, cek termin DP setelah Buka Proyek | 6 |
| 04 | [Info RAB Proyek otomatis](sprint-17/04-info-rab-proyek-otomatis.md) | Notice di lead & desain, notifikasi + log pipeline untuk Marketing, pesan toast yang jujur | 6 |
| 05 | [Invoice berbeda dari penawaran](sprint-17/05-format-invoice.md) | Tata letak tagihan (K3) untuk invoice jasa & termin, PDF + tampilan web | 6 |
| 06 | [Invoice DP lebih awal](sprint-17/06-invoice-dp-lebih-awal.md) | K2 direvisi: DP RAB Proyek ditagih begitu klien ACC, menempel ke termin DP saat Buka Proyek | 8 |
| 07 | [Akses Kirim Bukti Bayar](sprint-17/07-akses-bukti-bayar.md) | Tombol "Kirim Bukti Bayar" di halaman RAB & proyek, antrean "Invoice menunggu bukti bayar", notifikasi tolak membuka dialog | 7 |

Cara menyuruh: **"Kerjakan Sprint 17 Sub 1"** … **Sub 5**. Sub 01–04
saling lepas. Sub 05 menunggu jawaban K3.

## 4. Uji ulang bersama penguji
Setelah Sub 01–05 selesai, ulangi
[`simulasi-alur-klien.md`](simulasi-alur-klien.md) Tahap 2–3 dengan
**survey luar Pekanbaru yang dijadwalkan setelah RAB-nya diminta** (urutan
yang memicu T5), dan buka link klien dari HP sungguhan (K1).
