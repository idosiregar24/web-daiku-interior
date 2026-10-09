# Sprint 18 — Notifikasi yang Benar-benar Sampai

> Status: **dikerjakan mulai 2026-10-07**.
> Permintaan user: "fitur notifikasi yang betul-betul notifikasi, memberikan
> trigger". Artinya orang yang dituju **tahu saat itu juga**, walau tab
> Daiku tidak sedang dibuka atau HP terkunci, dan bisa langsung menuju
> pekerjaannya dengan satu ketukan.
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Tidak ada
> perubahan RBAC. `NotificationService::notify()` tetap **satu-satunya**
> jalur tulis notifikasi; sprint ini menambah *pengiriman*, bukan jalur baru.

## 1. Kondisi sekarang (hasil penelusuran 2026-10-07)

Fondasi PRD §4.9 sudah lengkap di atas kertas: tabel `notifications`,
`NotificationService` (`notify` / `notifyMany` / `notifyRoles` /
`alreadySentToday`), event `NotificationCreated` di channel privat
`App.Models.User.{id}`, lonceng + halaman Riwayat, `notificationHref.ts`,
12 job terjadwal di `routes/console.php`, dan **±60 tipe trigger** di
service/listener/job. Masalahnya ada di **pengiriman**. Notifikasi saat ini
hanya baris di database yang terlihat kalau orangnya kebetulan membuka
halaman berikutnya.

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| D1 | **Real-time mati, dan memperlambat request.** `.env` berisi `BROADCAST_CONNECTION=pusher`, tetapi tidak ada server WebSocket di `127.0.0.1:6001` (Soketi hanya ada di `docker-compose.yml`, dan mesin dev tidak punya Docker). `NotificationCreated` bertipe `ShouldBroadcastNow`, jadi dikirim **sinkron di dalam request** setelah commit. | `laravel.log`: `Pusher error: cURL error 7: Failed to connect to 127.0.0.1 port 6001 after 2044 ms` | Toast & badge live tidak pernah muncul. Setiap penerima menambah **±2 detik** ke request yang memicunya. `notifyRoles` ke 5 orang membuat tombol "Kirim" menunggu ±10 detik. `rescue()` menelan error-nya sehingga tidak terlihat. |
| D2 | **Hanya di dalam tab.** Satu-satunya tanda adalah `toast.info` di `useRealtimeNotifications`. Tidak ada notifikasi OS/HP, suara, angka di judul tab, atau badge ikon aplikasi. | `public/sw.js` hanya `install`/`activate` (Sprint 13 H7, sengaja kosong), tanpa `push`/`notificationclick` | Tab tertutup atau HP terkunci = tidak ada yang tahu. Tukang di lapangan dan CEO di luar kantor praktis tidak pernah "terpanggil". |
| D3 | **Pengingat terjadwal tidak jalan di lokal.** `composer dev` menjalankan server, queue, pail, dan vite, tetapi **tidak** `schedule:work`. Scheduler hanya ada di container `daiku_scheduler`. | `composer.json` → `scripts.dev` | Follow-up jatuh tempo, form harian, termin H-3, overdue, dan pengajuan barang tidak pernah terkirim saat uji lokal, sehingga terkesan "tidak ada trigger". |
| D4 | **Semua notifikasi setara.** Tipe hanya berupa string bebas (`'quotation_submitted'`, …) tanpa katalog, prioritas, atau kategori. | `grep` tipe di `app/` (±60 string, tidak ada enum) | Tidak bisa memilih mana yang layak membunyikan HP dan mana yang cukup masuk lonceng. Tipe baru juga mudah lupa diberi tujuan klik. |
| D5 | **Tujuan klik dihitung di browser.** `notificationHref.ts` memakai Ziggy (`route()`), sedangkan service worker (push) tidak punya Ziggy. Beberapa tipe tidak punya tujuan dan jatuh ke `null`. | `resources/js/lib/notificationHref.ts` | Push notification tidak tahu harus membuka halaman apa. |
| D6 | **Badge menu basi saat event masuk.** Hook hanya me-reload `notifications` + `unreadNotificationsCount`, bukan `navBadges` (Perlu Tindakan). | `useRealtimeNotifications.ts` | Lonceng bertambah, tetapi angka "Perlu Tindakan" di sidebar tetap angka lama. |
| D7 | **Tidak ada preferensi.** Pengguna tidak bisa mematikan kategori tertentu atau suara. | — | Begitu push dinyalakan untuk semua tipe, CEO akan kebanjiran dan akhirnya mematikan izin browser sama sekali. |

## 2. Target

1. **Kejadian penting → HP/laptop berbunyi** dalam beberapa detik, walau
   Daiku tidak sedang dibuka (Web Push lewat service worker yang sudah ada).
2. **Saat Daiku terbuka** → toast dengan tombol **Buka**, angka di judul
   tab, badge lonceng **dan** badge menu ikut berubah tanpa reload.
3. **Ketuk notifikasi → langsung ke pekerjaannya**, dan notifikasi itu
   otomatis tertandai dibaca.
4. **Pengingat terjadwal benar-benar jalan**, di lokal maupun production.
5. **Mengirim notifikasi tidak pernah memperlambat atau menggagalkan aksi**
   pemicunya.
6. Pengguna bisa mengatur kategori yang boleh membunyikan perangkatnya.

### Seperti apa di HP (setelah Sub 04)

**Sekarang:** notifikasi hanya tersimpan di lonceng dan baru terlihat kalau
orangnya membuka Daiku. Tidak ada yang muncul di layar HP.

**Setelah Sub 04:** muncul di HP seperti notifikasi WhatsApp, walau Daiku
tidak dibuka dan HP terkunci. Contoh: Marketing menekan **"Minta RAB"**
untuk lead Ibu Sari.

1. Server menyimpan notifikasi ke lonceng Estimator.
2. Dalam hitungan detik server mengirimnya ke layanan push browser (Google
   untuk Chrome/Android, Apple untuk iPhone). Gratis, tanpa akun.
3. HP Estimator berbunyi/bergetar. Di layar kunci muncul:

   ```
   Daiku Interior                         sekarang
   Permintaan RAB baru
   Marketing meminta RAB Proyek untuk lead Ibu Sari.
   ```
4. Estimator mengetuk notifikasi. Daiku terbuka langsung di halaman RAB itu
   dan notifikasinya tertandai dibaca (`notifications.open`).

Bila Daiku sedang terbuka di layar: toast di dalam aplikasi dengan tombol
**Buka**, angka lonceng & "Perlu Tindakan" langsung berubah, bunyi untuk
P1/P2 (Sub 03).

**Syarat**

| Syarat | Keterangan |
|---|---|
| Server HTTPS | Web Push hanya jalan di `https://` (atau `localhost`). `web-daiku-interior.test` tidak bisa dibuka dari HP dan tidak bisa menerima push, jadi fitur ini baru terasa di staging/production berdomain + HTTPS. Uji lokal dari HP: ngrok. |
| Izin sekali per perangkat | Setiap orang menekan "Aktifkan notifikasi di perangkat ini" lalu **Izinkan**. Bila memilih **Blokir**, harus dibuka lewat pengaturan browser; Daiku menampilkan petunjuknya. |
| Login di perangkat itu | Notifikasi dikirim ke setiap perangkat yang pernah login dan mengizinkan. HP dan laptop bisa didaftarkan bersamaan. |
| Antrean & scheduler berjalan | Ditangani Sub 01. Production: Horizon + container `daiku_scheduler`. |

**Per perangkat**

| Perangkat | Bisa? | Catatan |
|---|---|---|
| Android (Chrome) | ✅ paling lancar | Tetap masuk walau Chrome ditutup, karena Android yang menerimanya. |
| iPhone (iOS 16.4 ke atas) | ✅ dengan syarat | Daiku **harus ditambahkan ke Layar Utama** (Safari → Bagikan → Tambahkan ke Layar Utama), dibuka dari ikon itu, lalu izinkan. Dibuka biasa di Safari: tidak bisa. Di bawah iOS 16.4: tidak bisa sama sekali. |
| Laptop (Chrome/Edge) | ✅ | Muncul di pojok layar. Browser harus berjalan (boleh di background, tab Daiku tidak perlu terbuka). |

**Batasan**

- **Biasanya beberapa detik, tapi tidak dijamin.** Mode hemat baterai atau
  sinyal buruk bisa membuat Android/iOS menunda notifikasi. Untuk hal yang
  sangat kritis, ini kurang andal dibanding WhatsApp (yang tidak dipakai,
  lihat K4).
- **Isi dibuat singkat.** Nominal uang dan data sensitif tidak dikirim,
  karena isinya tampil di layar kunci.
- **Jumlah dibatasi.** Hanya P1/P2 yang membunyikan HP; P3 masuk tanpa bunyi, P4
  cukup masuk lonceng, dan kategori bisa dibisukan (Sub 05). Tujuannya agar
  orang tidak kebanjiran lalu mematikan izin.
- **Tanpa izin**, notifikasi tetap tercatat di lonceng dan "Perlu Tindakan";
  hanya HP-nya yang tidak berbunyi.
- **Tidak ada WhatsApp/email** (K4, diputuskan user 2026-10-07). Yang
  terlewat di HP tetap tertangkap lonceng dan "Perlu Tindakan" (Sub 06).

## 3. Arsitektur

```
Service (QuotationService, TaskService, …)
  └─ NotificationService::notify(user, NotificationType::X, …)   ← tetap satu pintu
       ├─ INSERT notifications (sumber kebenaran, 90 hari)
       └─ DB::afterCommit → dispatch DeliverNotificationJob (queue "notifications")
              ├─ broadcast NotificationCreated  → Reverb/Soketi → tab yang terbuka
              │     (toast + suara + judul tab + reload bell & navBadges)
              └─ WebPush ke push_subscriptions milik user
                    (hanya bila prioritas & preferensi mengizinkan)
                    → public/sw.js `push` → notifikasi OS
                    → `notificationclick` → GET /notifications/{id}/open
                          → tandai dibaca → redirect ke target_url
```

Prinsip:

- **Baris DB dulu, pengiriman belakangan.** Pengiriman lewat job antrean
  (`ShouldQueue`, `afterCommit`), bukan `ShouldBroadcastNow`. Kalau
  server WebSocket atau layanan push mati, aksi tetap cepat dan notifikasi
  tetap ada di lonceng. Job memakai `tries=3` + backoff, gagal → log.
- **Katalog tipe** `App\Enums\NotificationType` (string-backed, nilainya
  sama dengan string yang sudah tersimpan agar data lama tetap terbaca):
  `category()` (CRM, Desain, RAB, Proyek, Tugas, QA, Keuangan, Logistik,
  SDM), `priority()` (`NotificationPriority` P1–P4, lihat di bawah), `label()`. Tipe di TS
  ikut didefinisikan di `types/index.d.ts`.
- **Tujuan klik dihitung di backend**, sekali, oleh
  `App\Support\NotificationTarget::for(Notification)`. Ini memindahkan
  logika `notificationHref.ts` ke PHP. Hasilnya dikirim sebagai `url` di
  prop, payload broadcast, dan payload push. `notificationHref.ts` tinggal
  membaca `notification.url`.
- **Satu route buka** `GET notifications/{notification}/open`
  (`notifications.open`, auth, hanya pemilik → 403). Route ini menandai
  dibaca lalu redirect ke target. Dipakai lonceng dan push.

### Prioritas (K3, ditetapkan 2026-10-07)

Patokan dari user: **yang menuntut keputusan cepat, terutama yang menentukan
seberapa cepat Daiku menjawab klien, paling keras bunyinya.** Pertanyaan
pembedanya: *"Kalau notifikasi ini terlambat dibuka 2 jam, siapa yang
menunggu?"* Klien → P1. Rekan kerja/proyek → P2. Tidak ada yang menunggu → P3/P4.

Enum `App\Enums\NotificationPriority`:

| Level | Kapan | Lonceng | Tab terbuka | Push HP/laptop | Bila belum dibuka |
|---|---|---|---|---|---|
| **P1 `ClientWaiting`**, "Segera · klien menunggu" | Klien sedang menunggu jawaban/tindakan Daiku: RAB, penawaran, invoice, verifikasi bayar, revisi desain, survey, buka proyek | ✓ ditandai merah, paling atas | Toast **tetap tampil sampai ditutup** + tombol Buka + bunyi | Bunyi + getar, `requireInteraction` (di laptop tidak hilang sendiri) | Push ulang **1×** setelah 60 menit jam kerja (Sub 06) |
| **P2 `ActionRequired`**, "Perlu tindakan" | Rekan kerja atau proyek tertahan menunggu keputusan saya | ✓ | Toast + Buka + bunyi | Bunyi | — |
| **P3 `Update`**, "Kabar" | Hasil keputusan atau pengingat yang tidak menahan siapa pun | ✓ | Toast singkat | **Senyap** (masuk panel notifikasi HP tanpa bunyi) | — |
| **P4 `Info`** | Sekadar kabar | ✓ | Toast singkat | — | — |

Pemetaan semua tipe yang ada sekarang (hasil telusur pemanggil
`NotificationService`, 2026-10-07):

**P1 · Segera, klien menunggu**

| Tipe | Penerima | Kenapa klien menunggu |
|---|---|---|
| `lead_follow_up_due` | Marketing pemegang lead | Janji follow-up ke klien hari ini / terlewat |
| `quotation_requested` | Estimator | Klien menunggu RAB (Survey/Desain/Proyek/Tambahan) |
| `quotation_submitted` | PM / Asisten PM | RAB menunggu review sebelum bisa dikirim ke klien |
| `quotation_awaiting_ceo` | CEO | RAB Proyek menunggu ACC CEO sebelum dikirim |
| `quotation_returned` *(baru, pecahan `quotation_rejected`)* | Estimator | RAB dikembalikan PM/CEO, harus diperbaiki |
| `quotation_client_rejected` *(baru, pecahan `quotation_rejected`)* | Estimator, Marketing | Klien menolak penawaran, perlu dihubungi |
| `quotation_ready_to_send` | Marketing | Penawaran siap, tinggal dikirim ke klien |
| `invoice_to_issue` | Marketing | Klien siap bayar, invoice belum terbit |
| `invoice_awaiting_verification` | Finance | Klien sudah transfer, menunggu verifikasi |
| `invoice_rejected` | Marketing, pengirim bukti | Bukti bayar klien ditolak, klien harus dihubungi |
| `design_revision_requested` | Tim desain | Klien minta revisi desain |
| `design_ready_to_assign` | Kepala Desain | Klien sudah bayar jasa desain, desain belum ditugaskan |
| `design_ready_to_send` *(Sprint 22)* | Marketing pemegang lead | Arsitek selesai, desain / revisi belum dikirim ke klien |
| `design_acc` (ke Estimator) | Estimator | Desain ACC → klien menunggu RAB Proyek |
| `lead_survey_ready` | Marketing / tim survey | Survey sudah dibayar, jadwalkan berangkat |
| `project_opening_pending` | CEO | Klien sudah ACC RAB Proyek, proyek belum dibuka |

**P2 · Perlu tindakan (internal)**

`design_assigned`, `design_discussion`, `termin_invoice_due`,
`termin_overdue`, `overtime_submitted`, `overtime_awaiting_finance`
*(baru, pecahan `overtime_approved_pm` untuk Finance)*,
`budget_overrun_requested`, `material_request_submitted`,
`material_request_pm_pending`, `material_request_reminder`,
`material_low_stock`, `project_material_leftover`, `qa_form_created`,
`qa_rejected`, `qa_rejected_twice`, `task_assigned`, `task_updated` *(baru, Sub 06)*, `daily_form_reminder`
(tenggat penalti 21:00), `task_overdue`, `milestone_overdue`,
`project_pm_assigned`, `project_assistant_assigned`,
`salary_change_requested`, `review_submitted`, `review_returned`.

**P3 · Kabar (push senyap)**

`quotation_approved`, `quotation_cancelled`, `quotation_client_approved`
*(turun dari P1 di Sub 06: tindak lanjutnya sudah P1 tersendiri —
`invoice_to_issue` / `project_opening_pending` — jadi HP Marketing berbunyi
sekali, bukan dua kali)*, `invoice_verified`,
`termin_reminder` (H-3), `deal_confirmed`, `project_opened`,
`project_addendum_added`, `design_sent_to_client`, `design_client_approved`,
`design_acc_pm` *(baru, pecahan `design_acc` untuk PM)*,
`overtime_approved_pm` (ke tukang), `overtime_approved_finance`,
`overtime_rejected`, `budget_overrun_approved`, `budget_overrun_rejected`,
`material_request_decided`, `qa_approved`, `penalty_issued` (ke tukang & Finance),
`disciplinary_issued`, `salary_change_decided`, `review_approved_reviewer`.

**P4 · Info (lonceng saja)**

`quotation_started`, `project_rab_auto_requested`,
`project_rab_awaiting_opening`, `design_awaiting_payment`,
`project_completed`, `project_pm_unassigned`, `project_assistant_unassigned`,
`material_request_summary`, `review_approved`.

**Tipe yang dipecah.** Tiga string dipakai untuk dua arti dengan
prioritas berbeda: `quotation_rejected` (dikembalikan internal vs ditolak
klien), `overtime_approved_pm` (kabar ke tukang vs antrean Finance), dan
`design_acc` (ke Estimator vs ke PM). Ketiganya dipecah di Sub 02; baris
lama tetap terbaca lewat nilai lamanya. `penalty_issued` (tukang vs
Finance) tetap satu tipe: keduanya P3 dengan tujuan sama.

Tipe baru di sprint berikutnya **wajib** memilih level di enum. Tanpa level,
test arsitektur Sub 02 gagal.

## 4. Keputusan (perlu dijawab user, ✱ = usulan)

| # | Pertanyaan | Usulan |
|---|---|---|
| K1 | Server WebSocket apa? PRD §4.9 menyebut Soketi, tetapi Soketi tidak bisa jalan di mesin dev ini tanpa Docker. | **Dijawab user 2026-10-07: Laravel Reverb dulu.** First-party, PHP murni, jalan di Windows (`php artisan reverb:start`), protokol Pusher sehingga Echo hampir tidak berubah. Service `soketi` di `docker-compose.yml` diganti `reverb`; dicatat sebagai deviasi PRD di `plan/README.md`. |
| K2 | Push ke HP/OS lewat apa? | **Web Push (VAPID)** lewat `laravel-notification-channels/webpush`: fitur standar browser, gratis, tanpa akun Firebase/OneSignal, tanpa aplikasi Play Store/App Store. Memakai `public/sw.js` + manifest PWA yang sudah ada (Sprint 13 H7). Isi notifikasi dienkripsi end-to-end; server push Google/Apple hanya meneruskan dan tidak bisa membacanya. Syarat: **HTTPS** (atau `localhost`), dan di iPhone Daiku harus **ditambahkan ke Layar Utama** (iOS 16.4+). **Dijawab user 2026-10-07: pakai Web Push.** |
| K3 | Prioritas notifikasi? | **Dijawab user 2026-10-07:** utamakan yang butuh keputusan cepat, terutama yang menentukan kecepatan respons ke klien → skema P1–P4 di §3 "Prioritas". Usulan turunan (✱, ikut dikerjakan kecuali user menolak): P1 yang belum dibuka 60 menit pada jam kerja di-push ulang 1×, dan waktu buka dicatat (`read_at`) agar kecepatan respons bisa diukur. |
| K4 | Perlu saluran di luar browser (WhatsApp / email)? | **Dijawab user 2026-10-07: tidak usah.** Saluran hanya lonceng, real-time di tab, dan Web Push. Sub 07 (kerangka WA/email) dibatalkan; tidak ada kerangka saluran yang dibuat lebih dulu. |
| K5 | Jam tenang? (mis. 21:00–06:00 push P3 ditahan) | **Dijawab user 2026-10-07: nanti saja.** Sprint ini tanpa jam tenang: push dikirim kapan pun kejadiannya. Usulan untuk nanti tetap tercatat di §7. |

## 5. Sub-plan

Cara menyuruh: **"Kerjakan Sprint 18 Sub 1"** … **Sub 6**. Sub 01 bisa
langsung dikerjakan (memperbaiki bug D1/D3, tidak menunggu keputusan).
Semua keputusan terjawab (K1 Reverb, K2 Web Push, K3 P1–P4; K4 tidak dipakai, K5 ditunda).

### Sub 01 — Pengiriman yang tidak memblokir + scheduler jalan (D1, D3) ✅ 2026-10-07

> Catatan: `Bus::dispatch()` dipakai di `NotificationService::deliver()` karena `Job::dispatch()` baru mengirim saat `PendingDispatch` dihancurkan (di luar `rescue()`), sehingga exception lolos. Test regresi menangkapnya. `phpunit.xml` kini `BROADCAST_CONNECTION=null`: sebelumnya test ikut memakai `pusher` dari `.env` dan menunggu timeout ke :6001 (test notifikasi 29,6 s → 2,3 s). `composer dev` juga pindah ke port 8010 sesuai CLAUDE.md.

- [x] `DeliverNotificationJob` (`ShouldQueue`, queue `notifications`, `afterCommit`, `tries=3`, backoff `[5, 30, 120]`): broadcast `NotificationCreated` (ubah jadi `ShouldBroadcast` biasa / dikirim dari job). `NotificationService::broadcast()` → `dispatch()`.
- [x] Job idempoten: kirim ulang baris yang sama tidak membuat baris baru (job hanya *mengirim* `notification_id` yang sudah ada).
- [x] Bila `BROADCAST_CONNECTION` = `pusher`/`reverb` tetapi server tidak terjangkau: gagal di job, bukan di request. Request pemicu tidak menunggu.
- [x] `composer dev` menambah `php artisan schedule:work` (+ `reverb:start` setelah Sub 03); `queue:listen` mendengarkan `notifications,default`.
- [x] `.env.example` & CLAUDE.md "Local environment": jelaskan bahwa tanpa worker, notifikasi tetap tercatat di lonceng tetapi tidak dikirim live.
- [x] Horizon (`config/horizon.php`): supervisor untuk queue `notifications`.
- [x] Test: `Queue::fake()` → notify di dalam transaksi yang di-rollback tidak men-dispatch job; notify sukses men-dispatch 1 job per penerima; `notifyMany` dedup tetap jalan.
- [x] Test regresi: aksi pemicu (mis. submit quotation) tetap sukses saat broadcaster melempar exception.

### Sub 02 — Katalog tipe & tujuan klik di backend (D4, D5) ✅ 2026-10-07

> Catatan pelaksanaan:
> - 70 tipe di `NotificationType` (67 lama + `quotation_returned`, `quotation_client_rejected`, `overtime_awaiting_finance`, `design_acc_pm`). Baris lama `quotation_rejected` dibaca sebagai `quotation_returned` (`fromStored()`); string tak dikenal dibaca sebagai Info.
> - `penalty_issued` **tidak** dipecah: ke tukang dan ke Finance sama-sama P3 dengan tujuan sama (`penalties.index`), jadi pemecahan tidak mengubah apa pun.
> - `NotificationType::label()` tidak dibuat: preferensi (Sub 05) per kategori, bukan per tipe. Label ada di `NotificationCategory` & `NotificationPriority`.
> - Kolom `type` tetap string (bukan cast enum) supaya baris lama tidak membuat halaman error; `priority`/`category` di-append ke model.
> - Prop tidak membawa `url`: frontend cukup `route('notifications.open', id)`; target dihitung saat dibuka. `notificationHref.ts` tinggal 2 fungsi kecil.
> - Pengganti test "tidak ada string literal": signature `notify*()` bertipe `NotificationType`, jadi PHP sendiri menolak string.
> - Tes: `tests/Feature/Notifications/NotificationCatalogTest.php` (setiap tipe punya prioritas, kategori & target; legacy; target spesifik; `notifications.open` pemilik/403/guest; `read_at`).


- [x] `App\Enums\NotificationType` untuk semua ±60 tipe yang ada sekarang (nilai string = yang sudah tersimpan), lengkap dengan `category()`, `priority()`, `label()`.
- [x] `App\Enums\NotificationPriority` (P1 `ClientWaiting`, P2 `ActionRequired`, P3 `Update`, P4 `Info`) dengan `label()`; `NotificationType::priority()` mengikuti pemetaan §3.
- [x] Pecah 4 tipe bermakna ganda: `quotation_rejected` → `quotation_returned` / `quotation_client_rejected`; `overtime_approved_pm` (Finance) → `overtime_awaiting_finance`; `penalty_issued` (Finance) → `penalty_recorded`; `design_acc` (PM) → `design_acc_pm`. Nilai lama tetap dikenali untuk baris lama; `NotificationTarget` ikut.
- [x] Kolom `notifications.read_at` (nullable timestamp, diisi saat ditandai dibaca, termasuk lewat `notifications.open`). `is_read` tetap dipakai agar index `user_id,is_read` tidak berubah. Dasar push ulang P1 (Sub 06) dan ukuran kecepatan respons.
- [x] `notify()` / `notifyMany()` / `notifyRoles()` menerima `NotificationType` (bukan string). Semua pemanggil diganti. Model `Notification` meng-cast `type` ke enum, dengan **fallback aman** untuk string lama yang tidak dikenal (baris 90 hari ke belakang).
- [x] `App\Support\NotificationTarget::for()` = port `notificationHref.ts` (TARGETS, TYPE_FALLBACKS, SDM_TARGETS, INVOICE_TARGETS) ke `route()` PHP. Tipe tanpa target eksplisit → halaman Riwayat Notifikasi.
- [x] Route `notifications.open` (GET, auth): pemilik saja (403 untuk orang lain), tandai dibaca, redirect ke target.
- [x] Prop `notifications` (HandleInertiaRequests), halaman Riwayat, dan payload broadcast membawa `url`, `priority`, `category`. `notificationHref.ts` disederhanakan menjadi membaca `url`.
- [x] Test arsitektur (Pest): setiap `NotificationType` punya prioritas dan target yang me-resolve tanpa exception; tidak ada `->notify(` dengan string literal di `app/`.
- [x] Test RBAC `notifications.open`: pemilik 302, user lain 403.

### Sub 03 — Real-time di tab yang terbuka (D1, D6) ✅ 2026-10-07

> Catatan pelaksanaan:
> - Reverb dipasang manual (`composer require laravel/reverb`, `vendor:publish --tag=reverb-config`), bukan `install:broadcasting` yang menimpa file. `allowed_origins` dari `REVERB_ALLOWED_ORIGINS` (production = domain Daiku, dicek `deploy/deploy.sh`), client events `none`. Docker dev/prod: service `soketi` → `reverb` (image app, `reverb:start`); nginx `/app/` → `reverb:8080`.
> - **Temuan:** `.env` lokal tidak pernah punya `VITE_BROADCAST_CONNECTION`, jadi real-time di frontend selama ini mati walau backend `pusher`. Sudah ditambahkan.
> - Cache "Perlu Tindakan" penerima dibuang saat notifikasi dibuat (`ActionInboxService::forget`), supaya reload `navBadges` langsung benar.
> - Bunyi: Web Audio (tanpa file), aktif setelah interaksi pertama, hanya di tab yang terlihat. Pengaturan bunyi per user menyusul di Sub 05.
> - Toast diumumkan sekali per id (state modul, karena `AppLayout` di-mount ulang tiap kunjungan). Notifikasi yang datang bersama kunjungan biasa/poll juga di-toast; >3 sekaligus → 3 terpenting + ringkasan. Toast P1 yang masih terbuka hilang saat pindah halaman, tetapi tetap di atas lonceng dengan chip merah.
> - Uji browser 2026-10-07 (`php artisan serve` :8010 + `reverb:start` + `queue:listen`): akun Estimator, notifikasi P1 dikirim dari server → toast "Segera · klien menunggu" + Buka muncul tanpa reload, judul tab (75)→(76), lonceng P1 teratas; klik → `quotations/1`, (76)→(75). Uji dua akun di dua jendela belum; pemicu dari server setara.


- [x] Pasang Laravel Reverb (K1) ( `php artisan install:broadcasting`, `config/reverb.php`, env `REVERB_*`, `VITE_REVERB_*`). `lib/echo.ts` & `realtimeEnabled` mengikuti driver baru. Docker: service `reverb` menggantikan `soketi`.
- [x] `useRealtimeNotifications`: reload `notifications`, `unreadNotificationsCount`, **dan `navBadges`**.
- [x] Toast sesuai prioritas: P1 → tetap tampil sampai ditutup, label "Segera · klien menunggu", tombol **Buka** (`notifications.open`); P2 → tombol Buka, durasi panjang; P3/P4 → toast singkat tanpa tombol.
- [x] Lonceng & Riwayat: P1 yang belum dibaca ditandai (`StatusChip` tone `error`) dan diurutkan paling atas di dropdown lonceng.
- [x] Bunyi pendek untuk P1/P2 (file kecil di `public/`, diputar hanya bila tab terlihat atau baru berfokus, dan hanya setelah pengguna pernah berinteraksi dengan halaman, mengikuti aturan autoplay browser). Bisa dimatikan (Sub 05).
- [x] Judul tab `(3) Daiku Interior` dari `unreadNotificationsCount`; `navigator.setAppBadge()` bila PWA terpasang.
- [x] Indikator koneksi: bila socket putus, polling ringan `router.reload({ only: [...] })` tiap 60 detik saat tab terlihat, sebagai cadangan.
- [x] Uji browser: dua akun di dua jendela (Marketing minta RAB → Estimator melihat toast tanpa reload).

### Sub 04 — Web Push ke HP/laptop (D2) ✅ 2026-10-07

> Catatan pelaksanaan:
> - Library inti `minishlink/web-push` (v11) dipakai langsung, bukan `laravel-notification-channels/webpush`: paket itu butuh sistem Notification Laravel yang tidak dipakai di sini. Klien `WebPush` di-bind di `AppServiceProvider` (test me-mock-nya).
> - `WebPushService` (kirim, P1 `requireInteraction` + urgency high, P2 bunyi, P3 `silent` + urgency low, P4 tidak; nominal "Rp …" disamarkan jadi "Rp •••"; tag/topic per tipe+resource; 404/410 → baris perangkat dihapus) + `PushNotificationJob` (job terpisah dari broadcast) + `PushSubscriptionService` (satu perangkat = satu user; login lain di HP yang sama memindahkan perangkat) + `daiku:vapid-keys`.
> - Route: `push-subscriptions.store` / `.unsubscribe` (endpoint) / `.destroy` (id, pemilik saja → 403) di grup `throttle:60,1`; `push-subscriptions.test` `throttle:5,1` (dipakai Sub 05).
> - Frontend: `lib/webPush.ts`, `PushOptIn` (compact di lonceng, kartu di "Hari Ini" tukang), service worker kini didaftarkan juga di `localhost`. Tetap tanpa handler `fetch` (PwaTest).
> - **Windows:** worker pengirim butuh env `OPENSSL_CONF` (PHP `extras\ssl\openssl.cnf`), kalau tidak pembuatan EC key gagal. Sudah dicatat di CLAUDE.md, README, `.env.example`.
> - Diuji 2026-10-07: 16 test Web Push; di Chrome `localhost:8010` SW terdaftar, izin "default", ajakan "Aktifkan" tampil di lonceng; enkripsi + VAPID di mesin ini berhasil (dicoba ke perangkat tiruan). **Belum:** klik Izinkan dan terima push sungguhan di HP/laptop (butuh klik user; HP butuh HTTPS).


- [x] `composer require laravel-notification-channels/webpush` (+ cek ekstensi `gmp`/`openssl` di PHP 8.4 Laragon & image Docker). VAPID key di `.env` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT=mailto:…`), placeholder di `.env.example`.
- [x] Migrasi `push_subscriptions` (user_id FK, endpoint UNIQUE, public_key, auth_token, content_encoding, user_agent, last_used_at, timestamps); index `user_id`.
- [x] Route `push-subscriptions.store` / `push-subscriptions.destroy` (auth, `throttle:60,1`, Form Request memvalidasi endpoint URL https). Hanya pemilik yang boleh menghapus.
- [x] `DeliverNotificationJob` mengirim push untuk P1–P3 bila preferensi mengizinkan: P1 `requireInteraction: true` + getar, P2 bunyi biasa, P3 `silent: true`, P4 tidak di-push. Payload hanya `title`, `message` (dipotong), `url` (`notifications.open`), `tag` (= tipe + id resource, supaya pengingat berulang menimpa, bukan menumpuk). Jangan kirim nominal/data sensitif di payload. Subscription yang dibalas `404/410` langsung dihapus.
- [x] `public/sw.js`: tambah handler `push` (`showNotification` dengan ikon PWA + badge) dan `notificationclick` (fokus tab Daiku yang sudah terbuka lalu navigasi, atau buka tab baru). Tetap **tanpa cache/fetch handler** (keputusan Sprint 13 H6). Service worker juga diregistrasi di dev `localhost` agar bisa diuji.
- [x] UX izin: **jangan** minta izin saat halaman dimuat. Tampilkan kartu "Aktifkan notifikasi di perangkat ini" di dropdown lonceng dan halaman Pengaturan Notifikasi; untuk Tukang, kartu ini juga muncul di "Hari Ini". Status: belum diaktifkan / aktif / diblokir browser (dengan petunjuk membuka izin). Di iPhone non-PWA: petunjuk "Tambahkan ke Layar Utama".
- [x] Test: fake WebPush → P1 mengirim push ke semua subscription user dengan `requireInteraction`; P3 senyap; P4 tidak di-push; subscription 410 terhapus; user lain tidak bisa menghapus subscription orang.
- [ ] Uji manual di HP sungguhan lewat HTTPS (staging, atau ngrok sesuai catatan K1 Sprint 17): Chrome Android, laptop Chrome/Edge, iPhone PWA.

### Sub 05 — Preferensi notifikasi (D7) ✅ 2026-10-07

> Catatan pelaksanaan:
> - Route `profile.notifications.edit` / `.update` (halaman `Profile/Notifications`). Masuk lewat menu akun, ikon ⚙ di footer lonceng, dan tombol "Pengaturan" di Riwayat Notifikasi. Isinya: perangkat ini (`PushOptIn` + "Kirim notifikasi uji"), daftar perangkat (hapus per perangkat), bunyi di aplikasi, dan toggle per kategori.
> - Kategori yang tampil = pemetaan role (`NotificationCategory::forRoles()`) **ditambah** kategori yang benar-benar pernah diterima 90 hari terakhir, supaya toggle tidak pernah hilang untuk sesuatu yang memang membunyikan user.
> - Kategori dibisukan: P2/P3 tidak di-push; P1 tetap di-push tapi `silent` (ditandai `client_waiting` di halaman). Bunyi di tab juga mengikuti (`auth.user.notification_preferences`).
> - Jam tenang tidak dikerjakan (K5 ditunda, lihat §7).


- [x] Kolom `users.notification_preferences` (JSON, cast array; pola sama dengan `nav_preferences` Sprint 13): `muted_categories[]`, `sound` (bool).
- [x] Halaman **Pengaturan Notifikasi** (`profile.notifications`, untuk setiap user, milik sendiri): daftar kategori yang relevan untuk role-nya (diambil dari `NotificationType` yang memang bisa diterima role itu), toggle "Bunyi & push" (kategori dengan tipe P1 hanya bisa dibisukan bunyinya, push-nya tetap masuk, supaya urusan klien tidak pernah hilang), toggle suara, daftar perangkat terdaftar + tombol "Kirim notifikasi uji" + "Hapus perangkat".
- [x] Lonceng **selalu** tetap mencatat (preferensi hanya mengatur push/suara), supaya tidak ada pekerjaan yang hilang dari jejak.
- [x] Form Request whitelist kategori (pola `UpdateNavPreferenceRequest::GROUPS`); test validasi + test "kategori dibisukan → tidak ada push, baris tetap ada".

### Sub 06 — Audit trigger: setiap kejadian penting memanggil orang yang tepat ✅ 2026-10-07

> Catatan pelaksanaan — hasil audit (2026-10-07), dari penelusuran otomatis setiap pemanggil `notify*()` (±85 titik):
>
> **Celah yang ditutup**
> 1. **Arsitek tidak punya antrean "Perlu Tindakan"**: `design_revision_requested` (P1) hilang begitu dibaca. Ditambahkan antrean `design-revision` (REVISI_DESAIN) dan `design-work` (BRIEF/DESAIN) untuk desain milik sendiri (PIC atau tim).
> 2. **`lead_survey_ready` (P1) tanpa antrean**: ditambahkan `survey-ready` untuk Marketing pemegang lead, sampai survey SELESAI/BATAL.
> 3. **PM mengubah deadline/isi task, tukang tidak tahu** (pengalihan sudah memberi tahu): tipe baru `task_updated` (P2) untuk perubahan judul/deskripsi/deadline pada task yang belum DONE, menyebut tanggal lama → baru.
> 4. **Klien ACC = dua P1 sekaligus untuk Marketing** (`quotation_client_approved` + `invoice_to_issue`): `quotation_client_approved` diturunkan ke P3.
> 5. **Push ulang P1**: `RepushClientWaitingJob` tiap 15 menit, Senin–Sabtu 08:00–17:00 (`config/daiku.php` `notification_repush`), P1 belum dibuka ≥ 60 menit dan < 24 jam, sekali per baris (`notifications.repushed_at` diklaim sebelum push), judul "Pengingat: …", tag sama (menggantikan entri lama di HP).
>
> **Dicek, bukan celah**
> - QA approve/reject → PM: tipe sudah terpisah (`qa_approved` / `qa_rejected`).
> - Lembur ACC PM → Finance (`overtime_awaiting_finance`), penalti → Finance (`penalty_issued`), invoice terverifikasi → Marketing (penerbit + pemegang lead): sudah ada.
> - `quotation_submitted` ke semua PM & Asisten PM: konsisten dengan hak review (`QuotationService::reviewStage` mengizinkan PM/Asisten PM mana pun, bukan hanya proyek yang ditugaskan).
> - Asisten PM hanya menerima notifikasi proyek miliknya (`$project->assistantPm`); notifikasi alokasi dana/overrun hanya ke CEO & PM peminta (tidak ke Marketing/Asisten PM); QA hanya menerima `qa_form_created`, tanpa detail task.
> - Desain ACC lewat jalur lama (`clientAcc()` untuk desain sebelum Sprint 12) membuat RAB DRAFT v1 tanpa antrean Estimator. Jalur Sprint 12 (`markClientApproved()`) memakai DIMINTA → antrean `quotation-requested`. Jalur lama dibiarkan.
> - Semua job pengingat (follow-up, termin H-3/invoice/overdue, pengajuan barang, task/milestone overdue, form harian) dijalankan dua kali pada data demo (`DatabaseSeeder`): putaran kedua tidak menambah notifikasi.
>
> Tes: `tests/Feature/Notifications/NotificationAuditTest.php` (+ judul pengingat di `WebPushTest`). Termasuk test arsitektur: setiap tipe P1 terdaftar di peta antrean.
>
> **Matriks kejadian → penerima → prioritas** (P1 = klien menunggu; detail per tipe di §3)
>
> | Kejadian | Penerima | Tipe | P |
> |---|---|---|---|
> | Follow-up jatuh tempo / terlewat | Marketing pemegang lead | `lead_follow_up_due` | 1 |
> | Marketing minta RAB / RAB Tambahan | Semua Estimator | `quotation_requested` | 1 |
> | Estimator mulai menyusun | Peminta (Marketing) | `quotation_started` | 4 |
> | RAB diajukan | Semua PM & Asisten PM | `quotation_submitted` | 1 |
> | PM ACC RAB Proyek | CEO | `quotation_awaiting_ceo` | 1 |
> | PM/CEO mengembalikan | Estimator penyusun | `quotation_returned` | 1 |
> | RAB disetujui internal | Estimator penyusun | `quotation_approved` | 3 |
> | RAB siap dikirim | Marketing pemegang lead (+ peminta) | `quotation_ready_to_send` | 1 |
> | Klien menolak penawaran | Estimator + Marketing | `quotation_client_rejected` | 1 |
> | Klien ACC penawaran | Estimator, peminta, Marketing, pengirim link | `quotation_client_approved` | 3 |
> | Klien ACC RAB jasa / DP RAB Proyek | Marketing pemegang lead | `invoice_to_issue` | 1 |
> | Klien ACC RAB Proyek | CEO | `project_opening_pending` | 1 |
> | RAB Proyek non-DP menunggu Buka Proyek | Marketing | `project_rab_awaiting_opening` | 4 |
> | RAB dibatalkan | Estimator penyusun | `quotation_cancelled` | 3 |
> | Bukti bayar dikirim | Finance | `invoice_awaiting_verification` | 1 |
> | Bukti ditolak | Penerbit, Marketing, pengirim bukti | `invoice_rejected` | 1 |
> | Pembayaran terverifikasi | Penerbit + Marketing | `invoice_verified` | 2 (Sprint 19; sebelumnya 3) |
> | Survey sudah dibayar | Marketing pemegang lead | `lead_survey_ready` | 1 |
> | RAB Jasa Survey lunas, survey belum dibuat (Sprint 19) | Marketing pemegang lead | `survey_to_schedule` | 1 |
> | Survey dijadwalkan / diubah / dibatalkan (Sprint 19) | CEO + semua PM (bukan pelaku) | `survey_scheduled` / `survey_rescheduled` / `survey_cancelled` | 2 |
> | Survey jadi berangkat setelah lunas (Sprint 19) | CEO + semua PM | `survey_confirmed` | 3 |
> | Desain ACC klien (jalur lama) | Estimator / semua PM | `design_acc` / `design_acc_pm` | 1 / 3 |
> | Desain ACC klien → RAB Proyek otomatis | Estimator / Marketing / tim desain | `design_acc` / `project_rab_auto_requested` / `design_client_approved` | 1 / 4 / 3 |
> | Jasa Desain belum / sudah dibayar | Kepala Desain | `design_awaiting_payment` / `design_ready_to_assign` | 4 / 1 |
> | Desain ditugaskan | PIC + tim | `design_assigned` | 2 |
> | Desain siap dikirim (Sprint 22) | Marketing pemegang lead | `design_ready_to_send` | 1 |
> | Desain dikirim ke klien / klien minta revisi | Tim desain | `design_sent_to_client` / `design_revision_requested` | 3 / 1 |
> | Diskusi desain | PIC, tim, Estimator | `design_discussion` | 2 |
> | Deal (RAB Proyek ACC) | Semua PM | `deal_confirmed` | 3 |
> | Proyek dibuka | CEO, Finance, Logistik, PM, Asisten PM | `project_opened` | 3 |
> | PM / Asisten PM ditunjuk / diganti | Orang yang ditunjuk / diganti | `project_pm_*` / `project_assistant_*` | 2 / 4 |
> | RAB Tambahan disetujui | PM, Asisten PM, Marketing, Finance | `project_addendum_added` | 3 |
> | Task baru / dialihkan | Tukang | `task_assigned` | 2 |
> | Deadline/isi task diubah PM | Tukang | `task_updated` | 2 |
> | Task / milestone lewat deadline | PM proyek | `task_overdue` / `milestone_overdue` | 2 |
> | Form harian belum diisi (20:30) | Tukang | `daily_form_reminder` | 2 |
> | Penalti form harian | Tukang + Finance | `penalty_issued` | 3 |
> | Lembur diajukan | PM proyek | `overtime_submitted` | 2 |
> | Lembur ACC PM | Tukang / Finance | `overtime_approved_pm` / `overtime_awaiting_finance` | 3 / 2 |
> | Lembur ACC Finance / ditolak | Tukang (+ PM bila ditolak Finance) | `overtime_approved_finance` / `overtime_rejected` | 3 |
> | Milestone selesai → QA | Semua QA | `qa_form_created` | 2 |
> | QA approve / reject | PM proyek | `qa_approved` / `qa_rejected` | 3 / 2 |
> | QA reject 2× | CEO | `qa_rejected_twice` | 2 |
> | Overrun anggaran diajukan / diputuskan | CEO / PM peminta | `budget_overrun_*` | 2 / 3 |
> | Pengajuan barang (tukang → PM, → Logistik) | PM+Asisten PM / Logistik | `material_request_pm_pending` / `material_request_submitted` | 2 |
> | Pengajuan diputuskan | Peminta + PM | `material_request_decided` | 3 |
> | Pengajuan belum ditinjau (09:00) | Logistik / PM / CEO (ringkasan) | `material_request_reminder` / `material_request_summary` | 2 / 4 |
> | Stok menipis | Logistik | `material_low_stock` | 2 |
> | Proyek tertahan material sisa / selesai | Logistik + PM / CEO + PM | `project_material_leftover` / `project_completed` | 2 / 4 |
> | Termin H-3 / overdue / waktunya ditagih | Finance / Finance+CEO / Marketing | `termin_reminder` / `termin_overdue` / `termin_invoice_due` | 3 / 2 / 2 |
> | SP diterbitkan | Karyawan | `disciplinary_issued` | 3 |
> | Perubahan gaji diajukan / diputuskan | CEO / HR pengaju | `salary_change_*` | 2 / 3 |
> | Evaluasi diajukan / dikembalikan / disetujui | CEO / penilai / karyawan & penilai | `review_*` | 2 / 2 / 4 & 3 |


- [x] Matriks **kejadian → penerima → tipe → prioritas** di file ini (lampiran), dicocokkan dengan PRD §4.9 dan alur Sprint 12–17 (RAB 3 jenis, link klien, invoice Marketing → verifikasi Finance, Buka Proyek, alokasi dana, Asisten PM, pengajuan barang, SDM).
- [x] Tutup celah yang ditemukan. Kandidat yang perlu dicek dulu: QA approve/reject → PM (`QaFormService::notifyPm`, tipe yang dikirim), lembur ACC → **Finance** (PRD), penalti → **Finance** (PRD), tugas diubah PM setelah di-assign → tukang, invoice terverifikasi → Marketing.
- [x] Penerima sesuai RBAC: Asisten PM hanya untuk proyek yang ditugaskan (`Project::isManagedBy()`); Marketing & Asisten PM **tidak** menerima notifikasi alokasi dana/realisasi (aturan Sprint 12); QA tidak menerima detail task tukang.
- [x] **Push ulang P1**: job tiap 15 menit (`routes/console.php`, hari kerja `DailyFormSchedule::workDays()`, jam kerja dari `config/daiku.php`) mengirim push ulang **sekali** untuk notifikasi P1 yang `read_at` masih kosong setelah 60 menit. Ditandai (mis. kolom `repushed_at`) supaya idempoten. Tidak membuat baris lonceng baru.
- [x] Setiap tipe P1 **wajib** punya antrean di `ActionInboxService` (P2 sebaiknya). Celah P1 ditutup di sub ini.
- [x] Pengingat terjadwal: jalankan semua job sekali dengan `php artisan schedule:test` pada data seed, pastikan `alreadySentToday` mencegah dobel.
- [x] Feature test per celah yang ditutup.

### ~~Sub 07 — Saluran luar browser~~ (dibatalkan, K4)

Tidak dikerjakan: user memutuskan tidak memakai WhatsApp/email (2026-10-07).

## 6. Definisi selesai

- [ ] Marketing menekan "Minta RAB" → HP Estimator (Daiku tertutup) berbunyi sebagai P1 dalam < 10 detik; ketuk → halaman RAB terbuka & notifikasi tertandai dibaca (`read_at`).
- [ ] P1 yang dibiarkan 60 menit pada jam kerja berbunyi sekali lagi; P3 masuk HP tanpa bunyi; P4 hanya di lonceng.
- [x] Request pemicu tidak bertambah lambat walau server WebSocket/push mati (diukur sebelum/sesudah, target tambahan < 50 ms). *Diukur 2026-10-07, server WebSocket mati, 3 penerima: 6.160 ms → 76 ms (±25 ms/penerima, antrean database MySQL).*
- [x] Pengingat terjadwal terkirim di lokal dengan `composer dev`. *`schedule:work` ada di `composer dev`; `schedule:list` memuat semua job termasuk `RepushClientWaitingJob`; job pengingat dijalankan dua kali pada data demo di test (tanpa dobel).*
- [x] `php artisan test` hijau (1890), `npm run build` bersih, `/security-review` (2026-10-07: tidak ada temuan ≥ 8/10; catatan rendah: endpoint push bebas host https → opsional batasi ke host layanan push) pada diff Sub 02 & Sub 04 (route baru yang menerima input pengguna + data yang dikirim ke layanan push pihak ketiga).
- [x] `plan/README.md`: baris Sprint 18 + catatan deviasi PRD (Reverb menggantikan Soketi bila K1 disetujui).

## 7. Ditunda (bukan bagian sprint ini)

- **Jam tenang (K5, ditunda user 2026-10-07).** Usulan saat diangkat lagi:
  push P3 pada 21:00–06:00 ditahan lalu dikirim saat jam tenang
  selesai (job `release()` dengan delay); P1/P2 tetap langsung, termasuk
  pengingat form harian 21:00 (`DailyFormSchedule`). Jam di
  `config/daiku.php`, bukan per pengguna. Titik pasangnya sudah ada:
  `DeliverNotificationJob` (Sub 01) dan preferensi (Sub 05).
