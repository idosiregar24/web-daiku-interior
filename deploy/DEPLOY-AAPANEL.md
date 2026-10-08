# Deploy Daiku ke Server aaPanel

Panduan deploy untuk server live **https://daiku.idosiregar.my.id** (aaPanel, Linux).
Server ini **tidak** memakai Docker (`deploy/deploy.sh` khusus Docker Compose).

| Komponen | Di server ini |
|---|---|
| Folder proyek | `/www/wwwroot/daiku.idosiregar.my.id/web-daiku-interior` |
| Aplikasi web | `php artisan serve` di belakang proxy HTTPS |
| Worker antrean (notifikasi ke HP) | systemd: `daiku-worker` |
| Scheduler (pengingat, push ulang P1) | aaPanel → Cron, tiap 1 menit |
| Database, cache, antrean | MySQL (`QUEUE_CONNECTION=database`) |

---

## ⚠️ Aturan penting

> **Setiap kali deploy, jalankan `git pull` → `php artisan optimize:clear` → `php artisan queue:restart`.**
> Tanpa `queue:restart`, worker tetap memakai kode lama di memori.

> **Jangan pernah mengedit file kode langsung di server.** Semua perubahan lewat Git
> (laptop → commit → push → `git pull` di server). Editan manual membuat `git pull` gagal
> dan perbaikan tidak masuk. Ini pernah membuat semua notifikasi ke iPhone ditolak Apple.

> **Jangan generate ulang kunci VAPID** (`daiku:vapid-keys`) setelah dipakai. Kalau kunci diganti,
> semua HP/laptop harus mengaktifkan notifikasi lagi.

> **Jangan jalankan `migrate:fresh`, `db:seed` atau `migrate:refresh` di server.** Semua data
> akan terhapus. Cukup `php artisan migrate --force`.

---

## 1. Deploy rutin

### Di laptop
Pastikan test & build lulus, lalu commit dan push ke `main`.

```bash
php artisan test
npm run build
git push origin main
```

### Di server
```bash
cd /www/wwwroot/daiku.idosiregar.my.id/web-daiku-interior

# 1. Harus bersih. Kalau ada file berubah, lihat bagian "git pull ditolak".
git status

# 2. Ambil kode terbaru
git pull

# 3. Hanya bila composer.lock berubah (paket PHP baru)
composer install --no-dev --optimize-autoloader

# 4. Hanya bila ada migration baru
php artisan migrate --force

# 5. Hanya bila ada perubahan tampilan (resources/js, resources/css)
#    public/build tidak ada di Git, jadi wajib dibuild di server
npm ci
npm run build

# 6. Selalu
php artisan optimize:clear
php artisan queue:restart
```

Lalu **restart `php artisan serve`** dengan cara yang biasa Anda pakai untuk menjalankannya.

> Tidak yakin langkah 3–5 perlu? Jalankan saja semuanya. Tidak ada yang rusak kalau dijalankan
> tanpa perubahan, hanya butuh waktu lebih lama.

---

## 2. Cek setelah deploy

```bash
# Worker hidup & stabil (jam "since" tidak berubah-ubah)
systemctl status daiku-worker --no-pager | head -5

# Antrean notifikasi tidak menumpuk: harus [0] atau angka kecil yang turun
php artisan queue:monitor database:notifications,database:default

# Tidak ada job gagal
php artisan queue:failed | head -20

# Konfigurasi push benar & HP menerima (ganti email)
php artisan daiku:push-check estimator@daikuinterior.com
```

Lalu cek di browser:
- [ ] Login berhasil (tidak "diam" setelah klik Masuk).
- [ ] Lonceng notifikasi tampil.
- [ ] Pengaturan Notifikasi → **Kirim notifikasi uji** → HP berbunyi.

---

## 3. Isi `.env` yang wajib di server

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://daiku.idosiregar.my.id

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Notifikasi ke HP (Web Push). Dibuat SEKALI: php artisan daiku:vapid-keys
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@daikuinterior.com
```

- `APP_ENV=production` wajib. Tanpa itu link dibuat `http://` di halaman `https://`, dan login macet.
- Setelah mengubah `.env`, selalu jalankan `php artisan optimize:clear`, lalu
  `php artisan queue:restart`, lalu restart `php artisan serve`.
- Tanpa Reverb (WebSocket), lonceng tetap diperbarui tiap 60 detik dan push ke HP tetap jalan.

---

## 4. Pemasangan sekali saja (server baru / setelah reinstall)

### a. Worker antrean: systemd `daiku-worker`
Tanpa worker, notifikasi hanya masuk lonceng dan **tidak pernah dikirim ke HP**.
Supervisor bawaan aaPanel rusak di server ini, jadi worker dipasang lewat systemd.

```bash
PHP_BIN=$(which php)
cat > /etc/systemd/system/daiku-worker.service <<EOF
[Unit]
Description=Daiku queue worker (notifikasi)
After=network.target

[Service]
User=root
WorkingDirectory=/www/wwwroot/daiku.idosiregar.my.id/web-daiku-interior
ExecStart=$PHP_BIN artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now daiku-worker
```

Perintah berguna:

| Perlu | Perintah |
|---|---|
| Status | `systemctl status daiku-worker --no-pager` |
| Log | `journalctl -u daiku-worker -n 50 --no-pager` |
| Muat kode baru | `php artisan queue:restart` (systemd menyalakan ulang otomatis) |
| Restart paksa | `systemctl restart daiku-worker` |

> Hanya **satu** worker. Jangan tambahkan worker Daiku di Supervisor aaPanel juga.

### b. Scheduler: aaPanel → Cron
Pilih **Add Task**, Type **Shell Script**, jadwal **setiap 1 menit**:

```bash
cd /www/wwwroot/daiku.idosiregar.my.id/web-daiku-interior && php artisan schedule:run >> /dev/null 2>&1
```

Tanpa scheduler, pengingat follow-up, form harian, termin, penalti dan push ulang P1 tidak pernah jalan.

### c. Kunci VAPID (sekali seumur server)
```bash
php artisan daiku:vapid-keys
```
Salin ketiga baris `VAPID_*` hasilnya ke `.env` (satu baris utuh, tanpa spasi atau tanda kutip).
Lalu jalankan `optimize:clear` dan `queue:restart`. **Jangan diulang** setelah ada HP yang aktif.

### d. Setiap HP/laptop pengguna
Buka **Pengaturan Notifikasi**, tekan **Aktifkan**, lalu **Izinkan**, lalu **Kirim notifikasi uji**.
Di **iPhone**: buka Safari, tekan Bagikan, pilih **Tambahkan ke Layar Utama**, lalu buka Daiku dari ikon itu
(push tidak jalan dari tab Safari biasa).

---

## 5. Kalau ada masalah

| Gejala | Penyebab | Solusi |
|---|---|---|
| `git pull` ditolak: *Your local changes … would be overwritten* | Ada file yang diedit langsung di server | `git diff <file>` untuk melihat isinya. Kalau bukan perubahan penting: `git checkout -- <file>` lalu `git pull` |
| Login macet / halaman tidak bergerak setelah submit | `APP_ENV` bukan `production`, jadi link dibuat `http://` | Set `APP_ENV=production`, `APP_URL=https://…`, lalu `optimize:clear` dan restart serve |
| `Class "Minishlink\WebPush\…" not found` | Paket PHP baru belum terpasang | `composer install --no-dev --optimize-autoloader` |
| Notifikasi uji masuk, tapi notifikasi asli (mis. Minta RAB) tidak | Worker mati | `systemctl status daiku-worker`; lihat juga `queue:monitor` (antrean menumpuk) |
| Notifikasi asli baru masuk setelah lama / pakai kode lama | Worker belum memuat kode baru | `php artisan queue:restart` |
| Notifikasi uji: `403 BadAuthorizationHeader` / `BadJwtToken` | Kunci VAPID / konfigurasi server | `php artisan daiku:push-check <email>`, lalu perbaiki yang bertanda ✗ |
| Notifikasi uji: `429 Too Many Requests` | Terlalu sering ditekan (maks 5×/menit) | Tunggu 1 menit |
| `push-check`: `Perangkat …: 0` | HP belum mengaktifkan notifikasi untuk akun itu | Di HP: Matikan, lalu Aktifkan, lalu Izinkan |
| Tampilan tidak berubah setelah deploy | Frontend belum dibuild | `npm ci && npm run build`, lalu refresh keras browser |
| Pengingat harian tidak pernah muncul | Scheduler tidak jalan | Cek Cron aaPanel (bagian 4b) |

Log aplikasi: `storage/logs/laravel.log`, contohnya:

```bash
tail -50 storage/logs/laravel.log
grep "Web Push gagal" storage/logs/laravel.log | tail -5
```
