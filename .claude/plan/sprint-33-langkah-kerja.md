# Sprint 33 — Langkah Kerja (urutan isian di atas Detail Proyek)

> Status: **kode selesai 2026-10-22** — 2397 test lulus, build & Pint bersih,
> cek headless browser (Pengawas desktop + HP 390 px, tahap TM / Persiapan /
> Produksi). Di luar PRD; lanjutan Sprint 29–32.

## 1. Asal

User 2026-10-22: "atur ux-nya sesuai form yang urutannya diisi duluan —
setelah meeting ngapain, menentukan upah berdasarkan item RAB, kemudian
timeline, berurut; yang penting di bagian atas agar tidak capek scroll."
Sebelumnya: satu kalimat langkah di header + kartu tersebar di tab Eksekusi,
Item RAB, Daily Target, Material.

## 2. Dikerjakan

- [x] **[Backend]** `ProjectStepsService::for()` — langkah tahap berjalan, berurutan, masing-masing: siapa, selesai/belum (dari data yang sama dengan syarat TM/DP), keterangan singkat, tujuan (tab / view / kartu), `mine` (giliran penonton). Status: `done` · `current` (langkah terbuka pertama, atau yang menunggu seseorang) · `ongoing` (pekerjaan rutin tanpa antrean) · `todo`. Prop `execution.steps` (hanya proyek berjalan, penonton tab Eksekusi)
- [x] **Urutan per tahap**
  - Gambar Kerja: selesaikan gambar kerja
  - TM: jadwal TM → timeline & tanggal selesai → rencana bahan (Pengawas) → notulen, peserta, TM Selesai
  - Persiapan: **upah per item RAB → jadwal kerja tiap item → daily target tiap timeline → bahan diajukan ke Logistik → ajukan DP → PM setujui DP (Start Produksi)**
  - Produksi: cek daily target · isi progres per item & ajukan pencairan · ACC PM (rutin) → berangkat ke lokasi (≥ 70 %, baru "sekarang" setelah tercapai)
  - Pemasangan: pengecekan rutin → QC tiap item (atau timeline untuk proyek lama) → tutup temuan lokasi
- [x] **[UI]** `WorkStepsPanel` tepat di bawah stepper tahap, di semua tab: desktop berderet, langkah sekarang kuning + "Giliran Anda" + **Kerjakan** (membuka tab/kartunya), lainnya "Buka"; HP: langkah selesai dilipat jadi satu baris; bisa dilipat ke satu baris "Sekarang: …" (diingat per browser). Kalimat langkah di header hanya untuk proyek yang tidak berjalan
- [x] **[UI]** Item RAB sebelum Produksi dibuka di **Upah Tukang** dulu (lalu Lembar Progres); Syarat DP berurutan Upah → Daily target → Bahan
- [x] **[UI]** Upah bukan tugas PM (user 2026-10-22): proyek berupah per item RAB tidak lagi punya "Nilai Upah Tukang" / "DP %" di langkah 3 TM (tinggal Tanggal Selesai; DP 10 %, nominal diputuskan PM saat ACC). **Bagian Pengawas** di kartu TM: langkah 4 **Upah Tukang per Item RAB** — tabel item RAB dengan kolom Satuan Upah di kanan (`WorkItemWageTable`, sama dengan Item RAB › Upah Tukang), lalu 5 Bahan; Langkah Kerja TM menambah "Isi upah tukang per item RAB · Pengawas" (`#tm-upah`)
- [x] **[Test]** `ProjectStepsTest` (urutan & status Persiapan, Produksi rutin & ACC PM, prop & proyek selesai); `ExecutionFlowTest` urutan Syarat DP

## 3. Lanjutan — Jadwal TM & pengingat (2026-10-22)

- [x] **[DB]** `technical_meetings.mode` (JADWAL / LANGSUNG), `meeting_time`, `invited_at`, `reminded_day_before_at`, `reminded_soon_at`
- [x] **[UI]** Langkah 1 kartu TM dibuka dengan dua pilihan: **Jadwalkan TM** (tanggal, jam, orang yang terlibat — wajib minimal satu akun → "Jadwalkan & kirim undangan", lalu ringkasan "TM dijadwalkan … pukul …" dengan **Mulai TM · isi notulen** / **Ubah jadwal / undangan**) atau **Langsung TM** (form biasa: tanggal hari ini, jam, notulen, peserta — tanpa undangan)
- [x] **[Backend]** Undangan hanya untuk TM terjadwal yang masih akan datang: semua penerima saat pertama dijadwalkan dan saat tanggal/jam berubah ("Jadwal TM Diubah"); simpan ulang hanya mengundang orang yang baru ditambah. Penerima = peserta yang punya akun + Pengawas proyek + PM (kecuali yang menyimpan)
- [x] **[Backend]** Pengingat `TechnicalMeetingReminderJob` tiap 15 menit: H-1 (24 jam sebelum) dan 2 jam sebelum, masing-masing sekali, tidak dikirim bila undangan baru terkirim di dalam jendela itu; jadwal dipindah → pengingat dihitung ulang. TM langsung / selesai / lewat tidak diingatkan
- [x] **[Test]** `TechnicalMeetingScheduleTest` (validasi, undangan, orang baru, langsung tanpa notifikasi, H-1 & 2 jam sekali, scheduler tiap 15 menit)
