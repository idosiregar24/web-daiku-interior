# Sprint 17 · 04 — Info "RAB Proyek Sudah Otomatis Diminta"

> Induk: [`../sprint-17-masukan-uji-alur.md`](../sprint-17-masukan-uji-alur.md) · Temuan T6
> Status: **belum dikerjakan**

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
- [ ] **[Backend]** Hasil `markClientApproved()` + toast jujur di
      `DesignController` (kedua cabang)
- [ ] **[Backend]** Log pipeline + notifikasi Marketing pemilik lead
- [ ] **[Backend]** Penanda permintaan otomatis (kolom atau aturan teks),
      dikirim sebagai prop ke lead & desain
- [ ] **[UI]** `Notice` di `CRM/Show` & `Design/Show`; tombol Buat RAB
      Proyek nonaktif + tooltip selama berjalan
- [ ] **[Test]** ACC desain tanpa RAB Proyek → quotation DIMINTA + log +
      notifikasi Marketing; ACC saat RAB Proyek berjalan → tidak ada
      quotation baru, pesan cabang kedua
- [ ] **[Build]** `php artisan test` + `npm run build` lulus; dicek di lead #37
