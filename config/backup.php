<?php

/*
|--------------------------------------------------------------------------
| Database backup — `php artisan db:backup`
|--------------------------------------------------------------------------
|
| PRD §3.3 "mysqldump cron harian", §9.5 "Backup database terenkripsi,
| disimpan di lokasi terpisah", §11.4 "setiap tengah malam, retensi 30
| hari". Scheduled 00:00 Asia/Jakarta in routes/console.php; restore steps
| in README "Backup & Restore". Every value is env-driven.
|
*/

return [

    // Connection to dump; null = the app's default connection. Must use
    // the mysql/mariadb driver.
    'connection' => env('BACKUP_DB_CONNECTION') ?: null,

    // Disk (config/filesystems.php) the backups are written to. "backups" is
    // a dedicated local disk (storage/app/backups, or BACKUP_LOCAL_PATH —
    // point that at a separately mounted volume). A remote disk (s3/sftp)
    // keeps backups off the app server entirely, but needs its Flysystem
    // adapter package installed first (not bundled with this project).
    'disk' => env('BACKUP_DISK') ?: 'backups',

    // Folder inside the disk ('' = disk root). Only files named
    // "{database}_{Y-m-d_His}.sql.gz[.enc]" in this folder are ever pruned.
    'directory' => trim((string) env('BACKUP_DIRECTORY', ''), '/'),

    // Backups older than this many days are deleted after each successful
    // run. 0 disables pruning.
    'retention_days' => is_numeric(env('BACKUP_RETENTION_DAYS'))
        ? (int) env('BACKUP_RETENTION_DAYS')
        : 30,

    // base64 of 32 random bytes (`openssl rand -base64 32`), optional
    // "base64:" prefix. Empty = backups are stored unencrypted (logged as a
    // warning). Keep a copy outside the server — without it no encrypted
    // backup can be restored.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY') ?: null,

    'mysqldump' => [
        // Binary name or full path (MySQL's mysqldump or MariaDB's
        // mariadb-dump/mysqldump — the flavour is detected at run time).
        'binary' => env('BACKUP_MYSQLDUMP_PATH') ?: 'mysqldump',

        // Seconds before a hanging dump is killed.
        'timeout' => (int) (env('BACKUP_TIMEOUT') ?: 3600),
    ],

];
