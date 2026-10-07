# Sprint 17 · 04 — Info "RAB Proyek Sudah Otomatis Diminta"

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T6
> Status: **selesai 2026-10-07**

## Kondisi sekarang
`DesignService::markClientApproved()` (Sprint 12 keputusan #17–18) sudah
**otomatis** membuat RAB Proyek `DIMINTA` dan memberi notifikasi Estimator.
Masalahnya pada informasi:
- Satu-satunya tanda bagi Marketing adalah toast yang hilang dalam
  beberapa detik.
- Marketing **tidak** mendapat notifikasi. Di lead #37 hanya Estimator
  (user 4) yang menerima "Permintaan RAB Proyek".
- Halaman lead/desain tidak menunjukkan bahwa permintaan itu sudah ada,
  sehingga Marketing bisa mengira harus meminta manual.
- Bila RAB Proyek sudah berjalan, permintaan baru **tidak** dibuat, tetapi
  toast tetap berbunyi "Estimator diminta menyusun RAB Proyek".

## Rancangan
- **`markClientApproved()` mengembalikan hasilnya:** apakah RAB Proyek baru
  diminta (beserta quotation-nya) atau sudah ada yang berjalan. Controller
  memakai hasil itu untuk toast yang jujur:
  - "Desain disetujui klien. Permintaan RAB Proyek otomatis dikirim ke
    Estimator."
  - atau "Desain disetujui klien. RAB Proyek untuk klien ini sudah
    berjalan ({status})."
- **Log pipeline lead:** "Desain disetujui klien — RAB Proyek otomatis
  diminta ke Estimator." Log ini tampil di timeline lead.
- **Notifikasi Marketing pemilik lead:** "RAB Proyek otomatis diminta"
  (bila Marketing bukan pelaku yang menekan tombol).
- **Notice permanen:**
  - Di `CRM/Show` (kartu RAB/Riwayat RAB) dan `Design/Show`, selama RAB
    Proyek hasil otomatis itu masih `DIMINTA`/`DISUSUN`, tampil
    `Notice tone="info"`: "Permintaan RAB Proyek sudah otomatis dikirim ke
    Estimator pada {tanggal} — tidak perlu meminta lagi. Status: {status}."
    + link ke RAB.
  - Penanda "otomatis" dibaca dari `request_note`/kolom asal. Lebih baik
    kolom `requested_via = 'DESIGN_ACC'` daripada mencocokkan teks; putuskan
    saat pelaksanaan dan catat.
- **Tombol "Buat RAB → RAB Proyek"** di lead: disembunyikan atau dinonaktifkan
  dengan tooltip yang sama selama RAB Proyek itu berjalan. Aturan servernya
  sudah ada ("Masih ada … yang berjalan").

## Checklist
- [x] **[Backend]** Hasil `markClientApproved()` + toast jujur di
      `DesignController` (kedua cabang)
- [x] **[Backend]** Log pipeline + notifikasi Marketing pemilik lead
- [x] **[Backend]** Penanda permintaan otomatis (kolom atau aturan teks),
      dikirim sebagai prop ke lead & desain
- [x] **[UI]** `Notice` di `CRM/Show` & `Design/Show`; tombol Buat RAB
      Proyek nonaktif + tooltip selama berjalan
- [x] **[Test]** ACC desain tanpa RAB Proyek → quotation DIMINTA + log +
      notifikasi Marketing; ACC saat RAB Proyek berjalan → tidak ada
      quotation baru, pesan cabang kedua
- [x] **[Build]** `php artisan test` + `npm run build` lulus; dicek di lead #37
      — `php artisan test` penuh lulus (1722) dan `tsc --noEmit` bersih;
      `npm run build` & cek manual di browser belum dijalankan (dikerjakan
      lead saat penutupan sprint)

## Catatan pelaksanaan (2026-10-07)

- K2 (milik Sub 03) **dijalankan sesuai usulan (user tidak menjawab)**;
  Sub 04 sendiri tidak punya keputusan terbuka.
- **Penanda: kolom, bukan teks.** Migrasi baru
  `2026_10_07_090851_add_requested_via_to_quotations_table`
  (`quotations.requested_via` string(20) nullable, `down()` drop kolom),
  sudah di-`migrate` di DB lokal. `up()` mengisi mundur baris lama yang
  catatannya diawali teks tetap "Desain disetujui klien — susun RAB Proyek
  dari desain ini" → di DB lokal 2 baris (quotation #27 lead 31 DRAFT,
  #36 lead 37 sudah CLIENT_APPROVED). Konstanta
  `Quotation::VIA_DESIGN_ACC = 'DESIGN_ACC'`. `QuotationService::request()`
  tidak diubah — `markClientApproved()` menandai quotation hasilnya
  sesudah `request()` di transaksi yang sama.
- `DesignService::markClientApproved()` kini mengembalikan
  `App\Support\DesignApproval` (`design`, `requested`, `running`,
  `message()`). Toast: "Desain disetujui klien. Permintaan RAB Proyek
  otomatis dikirim ke Estimator." atau "Desain disetujui klien. {judul RAB}
  untuk klien ini sudah berjalan ({STATUS}) — tidak diminta ulang." (cabang
  ketiga, lead LOST/CLOSING: "Desain disetujui klien."). Dipakai di
  `DesignController@markClientApproved` dan `@clientAcc` (desain alur
  Sprint 12 langsung lewat `markClientApproved()`);
  `DesignService::clientAcc()` tetap mengembalikan `Design` (`->design`).
- Log pipeline lead (status tidak berubah, `from_status = to_status`):
  "Desain disetujui klien — RAB Proyek otomatis diminta ke Estimator." /
  "… — {judul} sudah berjalan, tidak diminta ulang." Riwayat Pipeline di
  `CRM/Show` menampilkan satu chip saja untuk entri seperti ini. Tidak
  memengaruhi KPI konversi (memakai MIN `created_at` per lead).
- Notifikasi `project_rab_auto_requested` "RAB Proyek Otomatis Diminta"
  ke Marketing pemilik lead bila bukan dia yang menekan tombol (metadata
  `quotation_id` → klik membuka RAB).
- Prop `projectRab` (`Quotation::runningProjectRabSummary()` — RAB Proyek
  lead yang masih berjalan: `id`, `title`, `status`, `requested_at`,
  `auto`, `pending_auto` = otomatis & masih DIMINTA/DRAFT) dikirim ke
  `CRM/Show` dan `Design/Show`. Komponen baru
  `Components/modules/quotation/AutoProjectRabNotice.tsx` menampilkan
  `Notice tone="info"` (tanggal, `StatusChip`, link "Lihat RAB") hanya
  saat `pending_auto` — di lead di atas Riwayat RAB, di desain di bawah
  notice status.
- Dialog "Buat RAB" di lead: opsi **RAB Proyek** dan **RAB Lainnya** (juga
  tipe PROYEK) nonaktif selama ada RAB Proyek berjalan (otomatis maupun
  manual — server memang menolak keduanya), alasannya tampil sebagai
  teks petunjuk opsi + `title`. Dialog konfirmasi "Desain Disetujui Klien"
  di `Design/Show` juga menyebut cabang yang akan terjadi.
- Test: `tests/Feature/Design/DesignFlowTest.php` — ACC tanpa RAB Proyek →
  DIMINTA + `requested_via` + log + flash, tanpa notifikasi ke diri
  sendiri; ACC oleh Marketing lain → notifikasi ke Marketing lead, prop
  `projectRab` di lead & desain, server menolak permintaan kedua, notice
  hilang setelah SUBMITTED; ACC saat RAB Proyek manual berjalan → tidak
  ada quotation baru, pesan cabang kedua, log, `auto=false`.

**Penutupan sprint (lead, 2026-10-07):** `php artisan test` penuh 1723
lulus, `npm run build` lulus, Pint lulus. Cek browser (Chrome headless,
Marketing): Salin Link di `http://web-daiku-interior.test` (bukan secure
context) → `execCommand('copy')` = true, toast "Link disalin.", tombol
"Tersalin"; peringatan "alamat lokal" tampil; kartu survey lead #37 SIAP;
notice RAB Proyek otomatis di lead #31. Uji dari HP sungguhan (K1) belum.
