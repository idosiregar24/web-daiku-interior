<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WebPushService;
use Base64Url\Base64Url;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sprint 18 — "why does my phone refuse the push?" on a server: checks the
 * VAPID config the way the push services do (key pair, subject, clock) and,
 * given an email, sends a test to each of that user's devices and prints
 * the push service's full answer. Never prints the private key.
 */
class CheckWebPush extends Command
{
    protected $signature = 'daiku:push-check {email? : Kirim uji ke perangkat user ini dan tampilkan jawaban layanan push}';

    protected $description = 'Periksa konfigurasi notifikasi perangkat (Web Push / VAPID) di server ini';

    private bool $ok = true;

    public function handle(): int
    {
        $publicKey = (string) config('services.webpush.public_key');
        $privateKey = (string) config('services.webpush.private_key');
        $subject = (string) config('services.webpush.subject');

        if (! WebPushService::enabled()) {
            $this->error('VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY kosong — jalankan php artisan daiku:vapid-keys, salin ke .env, lalu php artisan optimize:clear.');

            return self::FAILURE;
        }

        $this->checkKeys($publicKey, $privateKey, $subject);
        $this->checkClock();

        if ($email = $this->argument('email')) {
            $this->sendTo($email);
        }

        $this->newLine();
        $this->ok
            ? $this->info('Konfigurasi server benar. Bila iPhone masih menolak: di HP tekan "Matikan di perangkat ini" → "Aktifkan" → Izinkan, lalu uji lagi.')
            : $this->error('Perbaiki yang bertanda ✗ di atas, lalu php artisan optimize:clear dan restart server/worker.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function checkKeys(string $publicKey, string $privateKey, string $subject): void
    {
        $this->line('VAPID_PUBLIC_KEY  : '.Str::limit($publicKey, 16).' ('.strlen($publicKey).' karakter)');
        $this->line('VAPID_SUBJECT     : '.($subject === '' ? '(kosong)' : $subject));

        $this->check(
            strlen($publicKey) === 87 && preg_match('/^[A-Za-z0-9_-]+$/', $publicKey) === 1,
            'Format kunci publik (87 karakter base64url)',
            'kunci publik terpotong / ada spasi, tanda kutip atau baris baru — salin ulang satu baris utuh',
        );
        $this->check(
            strlen($privateKey) === 43 && preg_match('/^[A-Za-z0-9_-]+$/', $privateKey) === 1,
            'Format kunci privat (43 karakter base64url)',
            'kunci privat terpotong / ada spasi, tanda kutip atau baris baru — salin ulang satu baris utuh',
        );
        $this->check(
            preg_match('#^(mailto:[^\s@]+@[^\s@]+\.[^\s@]+|https://[^\s/]+\.[^\s/]+)#', $subject) === 1
                && ! Str::contains($subject, ['localhost', '127.0.0.1', '.test']),
            'VAPID_SUBJECT berupa mailto: atau https:// yang nyata',
            'Apple menolak subject kosong/lokal — pakai VAPID_SUBJECT=mailto:admin@daikuinterior.com',
        );

        try {
            $header = VAPID::getVapidHeaders(
                'https://web.push.apple.com',
                $subject,
                Base64Url::decode($publicKey),
                Base64Url::decode($privateKey),
                ContentEncoding::aes128gcm,
            )['Authorization'];
        } catch (Throwable $exception) {
            $this->check(false, 'Membuat token VAPID', $exception->getMessage());

            return;
        }

        preg_match('/^vapid t=([^,\s]+), k=([A-Za-z0-9_-]+)$/', $header, $parts);
        $this->check($parts !== [], 'Header Authorization "vapid t=…, k=…"', 'header tidak berformat VAPID');

        if ($parts === []) {
            return;
        }

        [$x, $y] = [substr(Base64Url::decode($parts[2]), 1, 32), substr(Base64Url::decode($parts[2]), 33, 32)];
        $jws = (new CompactSerializer)->unserialize($parts[1]);
        $matches = (new JWSVerifier(new AlgorithmManager([new ES256])))->verifyWithKey(
            $jws,
            new JWK(['kty' => 'EC', 'crv' => 'P-256', 'x' => Base64Url::encode($x), 'y' => Base64Url::encode($y)]),
            0,
        );

        $this->check(
            $matches,
            'Kunci publik & privat satu pasangan',
            'VAPID_PUBLIC_KEY dan VAPID_PRIVATE_KEY berasal dari dua kali generate berbeda — pakai sepasang dari satu kali daiku:vapid-keys, lalu semua perangkat aktifkan ulang',
        );
    }

    /** A JWT from a server clock that is off is refused (exp in the past / > 24 h ahead). */
    private function checkClock(): void
    {
        try {
            // Apple's push service answers without a Date header; Google's does.
            Http::timeout(10)->post('https://web.push.apple.com/');
            $date = Http::timeout(10)->head('https://fcm.googleapis.com/')->header('Date');
        } catch (Throwable $exception) {
            $this->check(false, 'Menghubungi layanan push', $exception->getMessage().' — server tidak bisa keluar ke web.push.apple.com / fcm.googleapis.com');

            return;
        }

        $this->check(true, 'Server bisa menghubungi layanan push', '');

        if ($date === '') {
            $this->warn('  ? Jam server: tidak bisa dibandingkan, dilewati.');

            return;
        }

        $skew = abs(now()->diffInSeconds(Carbon::parse($date)));
        $this->check($skew < 120, 'Jam server sesuai (selisih '.round($skew).' detik)', 'jam server meleset — aktifkan sinkronisasi waktu (NTP)');
    }

    private function sendTo(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->check(false, "User {$email}", 'tidak ditemukan');

            return;
        }

        $subscriptions = $user->pushSubscriptions()->get();
        $this->newLine();
        $this->line("Perangkat {$email}: {$subscriptions->count()}");

        foreach ($subscriptions as $subscription) {
            $client = app(WebPush::class);
            $encoding = WebPushService::contentEncodingFor($subscription);
            $client->queueNotification(
                new Subscription($subscription->endpoint, $subscription->public_key, $subscription->auth_token, $encoding),
                json_encode(['title' => 'Notifikasi uji', 'body' => 'Dari daiku:push-check.', 'url' => route('notifications.index')]),
                ['TTL' => 600, 'urgency' => 'high'],
            );

            foreach ($client->flush() as $report) {
                $host = parse_url($report->getEndpoint(), PHP_URL_HOST);
                $scheme = Str::before($report->getRequest()->getHeaderLine('Authorization'), ' ');
                $status = $report->getResponse()?->getStatusCode() ?? 'tanpa respons';
                $body = trim((string) $report->getResponseContent());

                $this->check(
                    $report->isSuccess(),
                    "#{$subscription->id} {$host} · {$encoding} · Authorization: {$scheme} · {$status}",
                    ($body !== '' ? $body : $report->getReason()).$this->hint($body),
                );
            }
        }
    }

    private function hint(string $body): string
    {
        return match (json_decode($body, true)['reason'] ?? null) {
            'VapidPkHashMismatch', 'BadAuthorizationHeader' => ' → kemungkinan perangkat ini didaftarkan dengan kunci VAPID lama: di HP Matikan → Aktifkan lagi.',
            'BadJwtToken', 'BadVapidPublicKey' => ' → periksa pasangan kunci & VAPID_SUBJECT di atas.',
            default => '',
        };
    }

    private function check(bool $passed, string $label, string $problem): void
    {
        $passed ? $this->line("  <info>✓</info> {$label}") : $this->line("  <error>✗</error> {$label}: {$problem}");
        $this->ok = $this->ok && $passed;
    }
}
