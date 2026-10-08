# Sprint 21 — Login dengan Username

> Status: **belum dikerjakan** — rencana 2026-10-08, K1–K5 sudah dijawab user (§2).
> Sumber: permintaan klien lewat user, 2026-10-08: "bisa login menggunakan
> username yang diatur sendiri, dan sistem mengecek apakah username itu sudah
> dipakai atau belum".
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`. Di luar PRD
> (PRD §9.1 hanya menyebut login email + password). Role gate tidak berubah:
> akun tetap hanya dibuat CEO (`users.*`, SUPERADMIN otomatis).

## 1. Kondisi kode sekarang

| Bagian | Kondisi | Akibat |
|---|---|---|
| Tabel `users` | `email` UNIQUE NOT NULL, **tidak ada `username`** | Tambah `username`, `email` jadi nullable. |
| Login | `LoginRequest` memvalidasi `email` (rule `email`), `Auth::attempt(email, password)`, throttle 5× per `email\|ip` | Field login dan kunci throttle diganti. |
| Pembuatan akun | Hanya `users.store` (`role:CEO`) → `UserService::create()` + audit. HR tidak membuat akun (Employee hanya `user_id` opsional). | Satu pintu. |
| Edit akun oleh CEO | `users.update` → `UserService::update()` + audit. **Password sudah bisa diganti CEO** (`password` nullable di `UpdateUserRequest`). | Jalan reset password untuk staf tanpa email sudah ada; tinggal dirapikan (Sub 05). |
| Profil Saya | `profile.update` (`ProfileUpdateRequest`: `name`, `email` wajib) | User mengatur username & email sendiri di sini. |
| Konfirmasi password | `ConfirmablePasswordController` memvalidasi pakai `email` | Harus pakai ID user (akun tanpa email). |
| Lupa password | Lewat email (`MAIL_MAILER=log` di lokal) | Akun tanpa email → minta CEO. |
| Tampilan email | Kartu user di sidebar (`AppLayout.tsx`), daftar Users, `HR/Employees/Show.tsx`, audit `UserService`, gate Telescope/Horizon | Fallback ke `@username` bila email kosong. |
| Seeder demo | `{role}@daikuinterior.com` / `password` | Ditambah username demo = nama role (`ceo`, `marketing`, `fieldstaff`…). |
| `verified` middleware | `User` tidak memakai `MustVerifyEmail` | Akun tanpa email tidak terhalang. |

## 2. Keputusan (dijawab user 2026-10-08)

| # | Pertanyaan | Jawaban | Akibat di kode |
|---|---|---|---|
| K1 | Siapa yang mengatur username/email? | **CEO mendaftarkan akun dengan username, email, atau keduanya.** Bila didaftarkan dengan username saja, **staf menambahkan email sendiri** (bebas) di Profil Saya. Staf juga bisa mengatur username sendiri. | `username` dan `email` sama-sama nullable, **minimal salah satu terisi** (seperti `ValidatesLeadContact`). Berlaku di Buat User, Edit User, dan Profil Saya. |
| K2 | Login pakai apa? | **Email atau username, dua-duanya bisa.** | Satu kolom "Email atau Username". Ada `@` → email, tidak ada → username. |
| K3 | Email boleh dikosongkan? | **Boleh.** | Sub 05: lupa password akun tanpa email lewat CEO, konfirmasi password pakai ID. |
| K4 | Seberapa sering username boleh diganti sendiri? | **Sekali per 40 hari.** | `username_changed_at`; CEO tidak dibatasi. Email boleh diganti kapan saja. |
| K5 | Format username | **Huruf kecil semua, tanpa spasi, tanpa titik, tanpa garis bawah.** | Hanya `a–z` dan angka `0–9`, diawali huruf, 3–30 karakter (mis. `budi`, `budisantoso`, `budi2`). Huruf besar otomatis dikecilkan; spasi, titik, garis bawah dan simbol ditolak. Kata cadangan tidak boleh (`admin`, `superadmin`, `root`, `daiku`, `system`, `support`, nama role). Angka boleh (dikonfirmasi user 2026-10-08). |

**User lama tidak diberi username otomatis.** Mereka tetap login dengan
email seperti sekarang, dan bisa membuat username sendiri di Profil Saya.
Jadi tidak ada yang terkunci saat rilis, dan tidak perlu membagikan daftar
username.

## 3. Sub-plan

| # | Sub | Isi | Bergantung |
|---|---|---|---|
| 01 | Kolom & aturan | Migrasi `username` + `email` nullable, `App\Support\Username` + `lib/username.ts`, aturan "minimal salah satu", factory & seeder | — |
| 02 | Cek ketersediaan langsung | Route `username.check`, komponen `UsernameInput` (✓ tersedia / ✗ dipakai + saran) | 01 |
| 03 | Login dengan email atau username | Kolom "Email atau Username", throttle, pesan error umum | 01 |
| 04 | Atur username & email | Buat/Edit User oleh CEO, Profil Saya (batas 40 hari), tampilan `@username`, audit, notifikasi | 02 |
| 05 | Akun tanpa email | Lupa password lewat CEO, wajib ganti password setelah direset CEO, konfirmasi password pakai ID, fallback tampilan | 03, 04 |
| 06 | Uji, deploy & sosialisasi | Test, build, security review, migrasi di server, info ke staf | semua |

Cara menyuruh: **"Kerjakan Sprint 21 Sub 1"** … **Sub 6**.

---

## Sub 01 — Kolom & aturan

- [ ] `App\Support\Username` (satu sumber aturan, seperti `App\Support\Phone`): `normalize()` (trim + lowercase), `rules()` (`/^[a-z][a-z0-9]{2,29}$/`), `RESERVED`, `isReserved()`, `suggestFor(string $name)` (mis. "Budi Santoso" → `budisantoso`, `budis`, `budi`, + angka bila sudah dipakai; huruf beraksen ditransliterasi, sisanya dibuang).
- [ ] `resources/js/lib/username.ts`: regex, panjang & daftar cadangan yang **sama**, untuk Zod. Ubah keduanya bersamaan (komentar saling menunjuk).
- [ ] Trait `App\Http\Requests\Concerns\ValidatesUserIdentity`: `username` (`nullable`, `Username::rules()`, tidak cadangan, `unique` ignore diri sendiri) + `email` (`nullable|email|max:255|unique`) + `required_without` satu sama lain. Pesan: "Isi username atau email (minimal salah satu)."
- [ ] Migrasi `add_username_to_users_table`: `username` `string(30)` nullable UNIQUE setelah `name`, `username_changed_at` timestamp nullable; `email` → nullable (UNIQUE tetap; MySQL mengizinkan banyak NULL). `down()`: hapus kolom, email kosong diisi `{id}@no-email.invalid` sebelum dikembalikan NOT NULL.
- [ ] Model `User`: `username` & `username_changed_at` di `$fillable`/`casts`, mutator lowercase untuk `username` (lapis kedua) dan `email`.
- [ ] `UserFactory`: username unik. `DatabaseSeeder` demo: tiap user demo dapat username = nama role huruf kecil tanpa garis bawah (`ceo`, `marketing`, `fieldstaff`, `asistenpm`, `kepaladesain`…). Tambah satu user demo **tanpa email** (Tukang, username saja) untuk uji Sub 05. `ProductionSeeder` tidak berubah.
- [ ] `resources/js/types/index.d.ts`: `User.username: string | null`, `User.email: string | null`.
- [ ] Test unit `Username`: normalisasi (`Budi` → `budi`), valid (`budi`, `budi2`, `budisantoso`), invalid (`bu`, `1budi`, `budi.s`, `budi_s`, `budi s`, `budi-s`, 31 karakter), cadangan, saran tidak bentrok dengan yang sudah ada. Test trait: kosong keduanya ditolak, salah satu cukup.

## Sub 02 — Cek ketersediaan langsung

- [ ] Route `GET /username/check` (`username.check`, `auth` + `throttle:30,1`), controller tipis + `CheckUsernameRequest`. Parameter `username`, opsional `user_id` (hanya CEO/SUPERADMIN; user lain otomatis dirinya sendiri) supaya username milik sendiri dianggap "tidak berubah", bukan "sudah dipakai".
- [ ] Jawaban JSON `{ status, message, suggestions }`. `status` ∈ `available` / `taken` / `invalid` / `reserved` / `unchanged`. Pesan Indonesia: "Username tersedia", "Username sudah dipakai", "Hanya huruf kecil dan angka, tanpa spasi, titik atau garis bawah", "Username ini tidak boleh dipakai". `suggestions` (maks. 3) untuk `taken`/`reserved`.
- [ ] Hanya untuk user yang login. Tidak ada pendaftaran publik, jadi orang luar tidak bisa menebak username staf. Halaman login **tidak** memakai pengecekan ini.
- [ ] Komponen `Components/shared/UsernameInput.tsx`: prefiks `@`, huruf besar langsung dikecilkan saat mengetik, cek otomatis 400 ms setelah berhenti mengetik (permintaan lama dibatalkan), indikator di dalam kolom: memuat / ✓ `text-success-ink` / ✗ `text-error-ink` + pesan di bawah. Saran bisa diklik untuk mengisi kolom. `autoCapitalize="none"`, `autoCorrect="off"`, `spellCheck={false}`.
- [ ] Hasil cek hanya bantuan. **Keputusan akhir tetap di server**: Form Request `unique` + indeks UNIQUE di DB (dua orang menyimpan username sama bersamaan → yang kedua dapat "Username sudah dipakai").
- [ ] Test: tiap `status`, `Budi` = `budi`, `unchanged` untuk milik sendiri, user biasa tidak bisa memakai `user_id` orang lain, guest → redirect login, throttle → 429.

## Sub 03 — Login dengan email atau username

- [ ] `LoginRequest`: field `email` → `login` (`required|string|max:255`). Ada `@` → `Auth::attempt(['email' => lower(...)])`, tidak ada → `Auth::attempt(['username' => Username::normalize(...)])`. `remember` tetap.
- [ ] User nonaktif (`is_active = false`) tetap ditolak seperti sekarang, apa pun cara login.
- [ ] Pesan gagal **umum**: "Email/username atau password salah." Tidak membedakan "akun tidak ada" dan "password salah".
- [ ] Throttle 5× per (identitas yang dinormalisasi + IP), tidak bisa diakali dengan huruf besar/kecil.
- [ ] `Auth/Login.tsx`: label "Email atau Username" (wajib), `type="text"`, `autoComplete="username"`, `autoCapitalize="none"` (HP tidak membesarkan huruf pertama), placeholder "nama@email.com atau username".
- [ ] Test: login email (lama) tetap bisa, login username, `BUDI` tetap masuk sebagai `budi`, akun tanpa email bisa login dengan username, password salah → pesan umum, user nonaktif ditolak, throttle per identitas.

## Sub 04 — Atur username & email

- [ ] **Buat User** (`users.create`): kolom Username (`UsernameInput`) + Email, **minimal salah satu** (bintang dinamis: keduanya berbintang selama keduanya kosong, sesuai Sprint 16). Tombol kecil "Buat dari nama" mengisi saran pertama yang tersedia.
- [ ] **Edit User** (`users.edit`): bisa mengisi/mengganti/mengosongkan username atau email, asal tidak keduanya kosong. Ganti oleh CEO tidak dibatasi 40 hari dan tidak mereset hitungan user.
- [ ] **Profil Saya** (`profile.update`, `UpdateProfileInformationForm`):
  - Username (`UsernameInput`). Bila masih dalam 40 hari sejak terakhir diganti sendiri: kolom terkunci + "Bisa diganti lagi pada {tanggal}". Mengisi username **pertama kali** (dari kosong) selalu boleh, lalu hitungan 40 hari dimulai.
  - Email: bebas ditambah/diganti kapan saja (K1).
  - Tidak boleh mengosongkan keduanya.
- [ ] Batas 40 hari dicek di `UserService::changeOwnUsername()` (bukan hanya di UI), pesan Indonesia. Angka 40 di `config/daiku.php` (`username_change_days`), tidak di-hardcode di beberapa tempat.
- [ ] Semua perubahan username/email lewat `UserService` → `AuditLogService::record()` (lama → baru, siapa yang mengubah). Notifikasi ke user bila username/email-nya diubah CEO ("Username Anda diubah menjadi @…").
- [ ] Tampilan: daftar Users (`users.index`) menampilkan `@username` dan email (yang ada), keduanya ikut pencarian.
- [ ] Form Request `StoreUserRequest`, `UpdateUserRequest`, `ProfileUpdateRequest` memakai `ValidatesUserIdentity`. Zod dari `lib/username.ts` dengan aturan "minimal salah satu" yang sama.
- [ ] Test: CEO membuat akun dengan username saja / email saja / keduanya / tanpa keduanya (ditolak); user mengisi username pertama kali; ganti kedua dalam 40 hari ditolak, hari ke-41 boleh; CEO bebas mengganti + user dapat notifikasi; email diganti sendiri bebas; audit tercatat; RBAC `users.*` tetap CEO saja.

## Sub 05 — Akun tanpa email

- [ ] Lupa password (`ForgotPassword.tsx` + controller): teks "Masukkan email akun Anda. Akun tanpa email? Minta CEO mengatur ulang password Anda." Respons tetap umum (tidak memberi tahu email terdaftar atau tidak).
- [ ] CEO mengatur ulang password lewat Edit User (sudah ada). Ditambah kolom `must_change_password` (boolean) di `users`: menyala bila password diganti CEO, mati setelah user menggantinya sendiri.
- [ ] Middleware `EnsurePasswordChanged`: user dengan `must_change_password` hanya bisa membuka halaman ganti password (+ logout) sampai ia membuat password sendiri. Pesan: "Password Anda diatur ulang oleh CEO. Buat password baru untuk melanjutkan."
- [ ] `ConfirmablePasswordController`: validasi password pakai ID user (`Auth::guard('web')->validate(['id' => …, 'password' => …])` atau `Hash::check`), bukan email.
- [ ] Fallback tampilan email kosong: kartu user di sidebar (`AppLayout.tsx`) menampilkan `@username`, `HR/Employees/Show.tsx` `nama (@username)`, audit `UserService` mencatat `username` juga.
- [ ] Telusuri sisa pemakaian `$user->email` (gate Telescope/Horizon, PDF, notifikasi, `ProductionSeeder`) agar aman bila `null`.
- [ ] Test: akun tanpa email login dengan username, konfirmasi password berhasil, CEO reset password → user dipaksa ganti → setelah ganti bebas, lupa password tidak membocorkan keberadaan akun.

## Sub 06 — Uji, deploy & sosialisasi

- [ ] `php artisan test`, `npm run build`, `pint` lulus.
- [ ] `/security-review` pada diff Sub 02, 03 dan 05 (endpoint baru, login, reset password).
- [ ] Cek di HP: kolom login dan username tidak membesarkan huruf otomatis, indikator cek username terbaca, layar "buat password baru" nyaman.
- [ ] Deploy server (lihat `deploy/DEPLOY-AAPANEL.md`): backup DB → `migrate` (hanya menambah kolom, user lama tidak berubah) → build.
- [ ] Info ke staf: "Sekarang bisa login dengan username. Buat username Anda di Profil Saya (hanya huruf kecil dan angka). Username hanya bisa diganti sekali per 40 hari."
- [ ] Update `plan/README.md` + `CLAUDE.md` (golden rule: username lewat `App\Support\Username` / `lib/username.ts`; akun minimal punya username atau email; login "email atau username").
