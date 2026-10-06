# Sprint 15 · 01 — Data Kop Surat (Pengaturan Situs)

> Induk: [`../sprint-15-surat-resmi-rab.md`](../sprint-15-surat-resmi-rab.md) · Keputusan: K3, K4
> Status: **selesai 2026-10-06**

## Rancangan
- Kolom baru `site_settings`: `company_instagram`, `company_legal_name`
  (a.n. rekening, mis. "PT Daiku Shankara Kreasitech"), `letter_footer`
  (mis. "INTERIOR FURNISHING | ARCHITECTURAL DESIGN | BUILDING
  CONSTRUCTION"), `signer_name`, `signer_title`, `signature_path` (aset
  baru `signature`, PNG), `note_survey`, `note_desain`, `note_proyek`
  (catatan bawaan per jenis RAB).
- Halaman Pengaturan Situs: kartu "Kop Surat & Tanda Tangan" + "Catatan
  Bawaan RAB"; unggah tanda tangan lewat `BrandAssetCard` yang sama.

## Checklist
- [x] **[Backend]** Migrasi + model + validasi + aset `signature`
- [x] **[UI]** Isian di Pengaturan Situs
- [x] **[Test]** Simpan & unggah (CEO/SUPERADMIN), role lain 403

## Catatan pelaksanaan (2026-10-06)
- Migrasi `add_letter_fields_to_site_settings_table`; `SiteSetting`: aset baru
  `signature` (PNG ≤ 1 MB, crop 3:1/2:1 di `BrandAssetCard`), `DEFAULT_LETTER_FOOTER`,
  `DEFAULT_NOTES` per jenis, `defaultNoteFor()`, `letterFooterLine()`, `assetDataUri()`.
- **Tanda tangan tidak pernah publik**: route `branding.show` kini hanya
  `SiteSetting::PUBLIC_ASSETS` (logo, favicon, gambar login); tanda tangan
  hanya disisipkan sebagai data URI ke surat (PDF & link klien) dan pratinjau
  Pengaturan (`signature_url`).
- Pengaturan Situs: Profil Perusahaan + Instagram & nama badan usaha; kartu
  baru "Surat & Tanda Tangan" dan "Catatan Bawaan RAB" (placeholder = teks contoh).
- **`SiteSettingSeeder`** (dipanggil `DatabaseSeeder` & `ProductionSeeder`):
  isi profil kop surat asli — Jl. Yos Sudarso, Rumbai, Pekanbaru ·
  daikupku@gmail.com · 0811 759 7766 · Instagram DaikuInterior · PT Daiku
  Shankara Kreasitech · penanda tangan Fendra Budiono. Hanya mengisi kolom
  yang **kosong** — isian CEO di Pengaturan tidak pernah ditimpa, aman
  dijalankan ulang saat deploy. Logo & tanda tangan tetap diunggah manual.
- Blok kontak kuning di kop: teks + ikon (lokasi, email, WhatsApp, Instagram)
  dari `LetterParts::ICONS` — SVG data URI, dipakai PDF dan `LetterDocument`
  (link klien) sehingga keduanya identik.
