<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Sprint 18 Sub 04 — prints a fresh VAPID key pair for Web Push. Run once
 * per server and paste the lines into .env; changing the pair later means
 * every device has to turn notifications on again.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'daiku:vapid-keys';

    protected $description = 'Buat pasangan kunci VAPID untuk notifikasi perangkat (Web Push)';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $exception) {
            $this->error('Gagal membuat kunci: '.$exception->getMessage());
            $this->line('Di Windows: set env OPENSSL_CONF ke openssl.cnf bawaan PHP (…\\php\\extras\\ssl\\openssl.cnf), lalu ulangi.');

            return self::FAILURE;
        }

        $this->info('Salin ke .env (jangan bagikan VAPID_PRIVATE_KEY):');
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->line('VAPID_SUBJECT=mailto:admin@daikuinterior.com');

        return self::SUCCESS;
    }
}
