# Sprint 15 · 05 — Riwayat RAB Lengkap di Detail Klien

> Induk: [`../sprint-15-surat-resmi-rab.md`](../sprint-15-surat-resmi-rab.md) · Keputusan: K1
> Status: **selesai 2026-10-06**

## Rancangan
- Kartu "Riwayat RAB" (Sprint 14 Sub 02) di detail lead menambah per RAB:
  nomor surat (bila sudah dikirim), tombol **PDF**, dan **link klien**
  (salin/buka) bila RAB sudah dikirim — link hanya untuk CEO/Marketing
  (sama dengan aturan `shareUrl` di halaman Quotation).

## Checklist
- [x] **[Backend]** `quotations` di detail lead membawa `letter_number` & `client_url` (sesuai role)
- [x] **[UI]** Tombol PDF + salin/buka link per RAB
- [x] **[Test]** Link klien hanya untuk CEO/Marketing; PDF per RAB

## Catatan pelaksanaan (2026-10-06)
- `LeadController@show`: `quotations` membawa `letter_number`; `client_url` (link
  versi berjalan) hanya untuk CEO/Marketing/SUPERADMIN — token tidak pernah dikirim
  ke role lain (`shareLinks` dimuat lalu dilepas sebelum serialisasi).
- `RabHistoryCard`: per RAB nomor surat, tombol **PDF**, **Salin link klien** +
  buka link.
