# Database Standards — MySQL 8.0 / Eloquent Migrations

Berlaku untuk semua file di `database/migrations`, `database/seeders`,
`database/factories`. Skema lengkap ada di PRD §5 (Database Schema &
Entities) — dokumen ini adalah aturan *cara menulisnya*, bukan pengulangan
skema itu sendiri.

## 1. Mesin database

Proyek ini pakai **MySQL 8.0** (lihat `.env`, `docker-compose.yml`). PRD §3.2
menulis "MySQL 16.x" yang bukan versi MySQL yang valid — abaikan angka
versinya, MySQL tetap mesin databasenya. Jangan pakai fitur khusus
PostgreSQL (mis. native array/JSONB operator) — kolom "array" di PRD §5
(`designer_ids`, `design_urls`, `attachments`) diimplementasikan sebagai
kolom `JSON` MySQL + cast `array` di model Eloquent.

## 2. Konvensi penamaan

- Tabel: `snake_case`, jamak (`leads`, `pipeline_logs`, `quotation_items`).
- Foreign key: `{singular_table}_id` (`lead_id`, `project_id`,
  `milestone_id`), dengan `->constrained()->cascadeOnDelete()` kecuali PRD
  eksplisit minta lain (mis. `nullable()` untuk `milestone_id` di `tasks`).
- Migration file: `{timestamp}_create_{table}_table.php` atau
  `{timestamp}_add_{column}_to_{table}_table.php` — gunakan
  `php artisan make:migration`, jangan tulis nama file manual.
- Kolom status: selalu `string` + cast ke PHP enum (lihat
  `backend-standards.md` §4), **bukan** MySQL `ENUM` type asli (susah
  di-migrate ulang saat status baru ditambah — lihat cabang status di PRD
  §4.2/§4.5 yang kemungkinan bertambah).

## 3. Kolom wajib per tabel transaksional

Setiap tabel yang PRD sebut sebagai bagian "audit trail" (leads,
quotations, projects, finance_transactions, penalties, qa_forms, dst) wajib
punya:

- `timestamps()` (created_at, updated_at).
- Kolom pelaku (`created_by`, `assigned_to`, `logged_by`, `changed_by`,
  `recorded_by` — nama mengikuti PRD §5, FK ke `users`).
- Untuk tabel finance/penalty: **tidak ada soft delete**. PRD §9.4 eksplisit
  "Audit log tidak bisa dihapus oleh siapapun" — jangan tambahkan
  `SoftDeletes` trait pada `FinanceTransaction`, `Penalty`, `AuditLog`,
  `QaForm` yang sudah `APPROVED`. Modul lain (Lead, Material, Asset) boleh
  soft delete jika dibutuhkan operasional, tapi harus didiskusikan per
  kasus, bukan default.

## 4. Role & RBAC — jangan duplikasi kolom `role`

`users` **tidak** punya kolom `role` ENUM meskipun sketsa skema PRD §5.1
menuliskannya. Role dikelola sepenuhnya oleh Spatie Laravel Permission
(`model_has_roles` pivot, `config/permission.php`) — lihat
`database/seeders/RoleSeeder.php` untuk 10 role yang sudah di-seed (9 dari
PRD §2 + `SUPERADMIN`, role admin teknis di luar PRD dengan akses god-mode
ke semua route `role:`-gated — lihat `app/Http/Middleware/RoleMiddleware.php`
dan `.claude/plan/README.md`). Jangan
tambahkan kolom `role` baru; gunakan `$user->hasRole('PM')`,
`$user->assignRole('QA')` dsb. Kolom `is_active` (boolean) memang ada di
`users` karena Spatie tidak menyediakannya.

## 5. Index

Tambahkan index eksplisit untuk kolom yang dipakai `WHERE`/`ORDER BY` pada
halaman list bervolume tinggi (task per tukang, transaksi finance,
notifikasi per user):

```php
$table->index(['assignee_id', 'status']);   // tasks
$table->index(['project_id', 'date']);       // finance_transactions
$table->index(['user_id', 'is_read']);       // notifications
```

## 6. Seeder & factory

- `RoleSeeder` (roles) dan `DatabaseSeeder` (roles + user CEO awal) sudah
  ada — seeder modul baru ditambahkan sebagai class terpisah lalu
  didaftarkan via `$this->call()`, bukan ditumpuk di `DatabaseSeeder`.
- Data dummy untuk staging/UAT (PRD §11.1, CSV Sprint 7 "seed data dummy")
  pakai `Factory`, bukan seeder manual — supaya reprodusibel dan bisa
  di-`->count(50)` untuk uji performa pagination/index.

## 7. Sebelum menjalankan migrasi

- `php artisan migrate` di lokal jalan ke database `daiku_interior`
  (MySQL Laragon, root/no password — lihat `.env`).
- Migrasi merusak (`Schema::dropColumn`, rename) wajib method `down()` yang
  benar-benar reversibel — proyek ini belum punya migration production
  history, tapi biasakan dari awal.
