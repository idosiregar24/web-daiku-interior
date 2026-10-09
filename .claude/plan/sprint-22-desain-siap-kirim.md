# Sprint 22 — Serah Terima Desain: Arsitek → Marketing → Klien

> Status: **kode selesai 2026-10-09** (Sub 01–05). Tersisa: coba alur di browser (arsitek → Marketing → WhatsApp) & deploy (migrasi baru).
> Sumber: tanya-jawab user setelah uji alur RAB Survey & RAB Desain
> ("apakah orang desain komunikasi di WA? bagaimana keterhubungan tim desain
> dengan Marketing? apa maksud Kirim Desain?").
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Alur Sprint 12
> keputusan #17 tidak berubah: **hanya Marketing yang berhubungan dengan
> klien**. Tidak ada route publik baru, tidak ada role baru.

## 1. Temuan

| # | Kondisi kode sekarang | Akibat |
|---|---|---|
| T1 | Arsitek menyimpan link desain (`DesignService::update()`) tanpa memberi tahu siapa pun. Arsitek tidak punya tombol "selesai". | Marketing tidak tahu desain sudah jadi; dalam praktik arsitek mengabari lewat WA. |
| T2 | `ActionInboxService` Marketing tidak punya antrean desain. | Desain yang sudah jadi tidak muncul di Perlu Tindakan Marketing. |
| T3 | "Kirim Desain ke Klien" hanya mengubah status → `WAITING_ACC_DESAIN` (dialog: "Tandai desain sudah dikirim ke klien"). | Marketing menyalin link sendiri ke WA lalu kembali menekan tombol — dua langkah terpisah, mudah lupa salah satunya. |
| T4 | Antrean arsitek "Desain sedang Anda kerjakan" berisi semua desain DESAIN/REVISI_DESAIN. | Desain yang sudah diserahkan ke Marketing tetap terlihat seperti pekerjaan arsitek. |

## 2. Keputusan

| # | Keputusan |
|---|---|
| K1 | "Siap dikirim" adalah **penanda** (`designs.ready_for_client_at` + `ready_note`), bukan status baru. Status tetap DESAIN / REVISI_DESAIN; filter & daftar status tidak berubah. |
| K2 | Arsitek (PIC/asisten, atau Kepala Desain) menekan **"Desain Siap Dikirim"** (syarat: link sudah tersimpan) dengan **catatan untuk Marketing** opsional (mis. "Revisi warna bar sudah"). |
| K3 | Penanda itu mengirim notifikasi baru **`design_ready_to_send` (P1)** ke Marketing pemilik lead (semua Marketing bila lead tanpa pemilik) dan memasukkan desain ke antrean baru **"Desain siap dikirim ke klien"** (`design-send`). |
| K4 | Penanda hilang saat Marketing mengirim ke klien (status → WAITING_ACC_DESAIN) atau saat semua link dihapus. Revisi berikutnya = arsitek menandai siap lagi. |
| K5 | Marketing **tetap boleh** mengirim tanpa penanda (arsitek lupa menekan) — syaratnya tetap hanya ada link. Dialog memberi peringatan. |
| K6 | Dialog "Kirim Desain ke Klien" menampilkan link desain + **pesan WhatsApp yang bisa diedit**. **"Kirim via WhatsApp"** membuka WhatsApp ke No. HP lead dan sekaligus mencatat terkirim; **"Tandai Terkirim"** untuk kiriman lewat jalur lain. Sistem tidak pernah mengirim WA sendiri (tetap `wa.me`). |
| K7 | No. HP klien hanya dikirim ke halaman desain untuk Marketing (prop terpisah), tidak ke arsitek. |
| K8 | Marketing **pemegang lead** ikut di thread diskusi desain (dijawab user 2026-10-09): boleh menulis, selalu diberi tahu (`design_discussion`, P2). Marketing lain & role lain tetap hanya membaca/tidak bisa menulis. Panel jadi "Diskusi Desain". |

## 3. Sub-plan

| # | Sub | Isi |
|---|---|---|
| 01 | Backend penanda siap kirim | Migrasi, model, `DesignService::markReadyToSend()`, route + Form Request, notifikasi P1, penanda dihapus saat kirim / link kosong |
| 02 | Antrean & daftar | Antrean Marketing `design-send`, antrean arsitek mengecualikan desain siap kirim, filter `?ready=1` di daftar Desain |
| 03 | UI arsitek | Tombol + dialog "Desain Siap Dikirim", notice status penanda |
| 04 | UI Marketing | Dialog kirim dengan WhatsApp, notice "siap dikirim" + catatan arsitek, penanda di daftar Desain |
| 05 | Uji & dokumen | Test, build, Pint, matriks notifikasi Sprint 18, simulasi alur Tahap 4 |

---

## Sub 01 — Backend penanda siap kirim

> Catatan pelaksanaan: `leads.assigned_to` NOT NULL, jadi cabang "lead tanpa
> pemilik → semua Marketing" hanya penjaga (sama dengan `awaitingInvoice()`),
> tidak diuji. Mengubah link desain yang sudah ditandai **tidak** menghapus
> penanda — Marketing selalu mengirim link terbaru; hanya mengosongkan semua link.
> No. HP klien dibaca terpisah (`clientPhone`), tidak ikut `design.lead`.

- [x] Migrasi `add_ready_for_client_to_designs_table`: `ready_for_client_at` timestamp nullable
      + `ready_note` string(500) nullable (setelah `sent_to_client_at`); `down()` drop keduanya.
- [x] `Design`: `$fillable` + cast `ready_for_client_at` datetime; `isReadyToSend()`.
- [x] `DesignService::markReadyToSend(Design, ?string $note, User)`: hanya desain alur Sprint 12,
      status DESAIN/REVISI_DESAIN, ada link, belum ditandai. Audit `design.ready_to_send`.
      Notifikasi `design_ready_to_send` ke pemilik lead / semua Marketing + `forgetMarketingOf()`.
- [x] `sendToClient()` mengosongkan penanda (+ cache inbox Marketing); `update()` mengosongkan
      penanda bila link jadi kosong.
- [x] Route `design.markReady` (POST `{design}/ready-to-send`, `role:DESIGNER` — Kepala Desain
      ikut lewat stacked role) + `DesignPolicy::update` + `MarkDesignReadyRequest` (`note` nullable|max:500).
- [x] `NotificationType::DesignReadyToSend` — P1, kategori Desain, target `design.show`.

## Sub 02 — Antrean & daftar

- [x] Scope `Design::readyToSend(?User $marketing)` (alur Sprint 12, DESAIN/REVISI_DESAIN, ditandai,
      lead milik Marketing itu atau tanpa pemilik).
- [x] `ActionInboxService::designsToSend()` untuk MARKETING → `design.index?ready=1`.
- [x] Antrean arsitek `design-work` / `design-revision` mengecualikan desain yang sudah ditandai siap.
- [x] `DesignController::index` menerima filter `ready=1` (scope yang sama).

## Sub 03 — UI arsitek

- [x] Prop `canMarkReady` (server): alur Sprint 12, status DESAIN/REVISI_DESAIN, belum ditandai,
      `can('update')`, role DESIGNER.
- [x] Tombol **"Desain Siap Dikirim"** di header (nonaktif bila belum ada link tersimpan atau brief
      belum disimpan) → dialog dengan "Catatan untuk Marketing" (RHF + Zod, max 500).
- [x] Notice setelah ditandai: "Ditandai siap dikirim {tanggal} — menunggu Marketing mengirim ke klien."

## Sub 04 — UI Marketing

- [x] Prop `clientPhone` hanya bila `canMarketingActions`.
- [x] Notice "Arsitek menandai desain siap dikirim {tanggal}" + catatan arsitek.
- [x] Komponen `SendDesignDialog` (`ResponsiveDialogContent`): daftar link, peringatan bila belum
      ditandai siap, pesan WA bisa diedit, tombol "Kirim via WhatsApp" (buka `wa.me` + catat terkirim)
      dan "Tandai Terkirim". Menggantikan `DesignConfirmDialog` untuk kirim.
- [x] Daftar Desain: penanda "Siap dikirim" di kolom Status + filter "Siap dikirim ke klien".

## Sub 05 — Uji & dokumen

- [x] `DesignFlowTest`: tandai siap (sukses, notifikasi P1 ke pemilik lead / semua Marketing tanpa
      pemilik, audit, catatan), ditolak tanpa link / status salah / sudah ditandai, RBAC (arsitek lain,
      Marketing, Estimator 403), penanda hilang saat kirim & saat link dikosongkan, props per role,
      `clientPhone` tidak ke arsitek, filter `ready=1`.
- [x] `ActionInboxServiceTest`: antrean `design-send` muncul & hilang; antrean arsitek mengecualikan.
- [x] `NotificationAuditTest` daftar P1 + `NotificationCatalogTest` K3.
- [x] `php artisan test`, `npm run build`, `vendor/bin/pint` lulus.
- [x] Matriks `sprint-18-notifikasi.md` + `simulasi-alur-klien.md` Tahap 4 + tabel `plan/README.md`.

## Sub 06 — Marketing ikut diskusi & salin link (tambahan 2026-10-09)

- [x] Route `design.discussions.store` + `role:MARKETING`; `DesignPolicy::discuss()` hanya Marketing pemegang lead.
- [x] `DesignService::discuss()` memberi tahu Marketing pemegang lead (+ `unique('id')`).
- [x] Panel "Diskusi Desain" (desain & halaman RAB lead).
- [x] Dialog kirim: tombol salin per link + "Salin Pesan"; setelah menyalin, "Tandai Terkirim" jadi tombol utama. Lebar dialog `sm:max-w-lg` (sebelumnya terkunci 384px, footer meluber).
- [x] Test: Marketing pemilik bisa menulis & diberi tahu, Marketing lain/PM 403; 2044 test lulus, build lulus.

## Uji di browser (belum)
- [ ] Arsitek: Simpan Brief → Desain Siap Dikirim (+ catatan) → notice & antrean hilang.
- [ ] Marketing (HP & laptop): lonceng P1 → dialog langsung terbuka → Kirim via WhatsApp membuka WA ke No. HP lead, status jadi Waiting Acc Desain.
- [ ] Revisi → arsitek tandai siap lagi → Marketing kirim lagi.
- [ ] Deploy: `php artisan migrate --force` (2 kolom nullable di `designs`).
