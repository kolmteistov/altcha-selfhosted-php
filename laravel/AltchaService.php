<?php
/**
 * app/Services/AltchaService.php
 * Altcha Self-Hosted Service - Laravel
 *
 * STATELESS implementation — verifikasi via HMAC signature, tanpa session.
 * Expiry disimpan di dalam salt — tidak bisa dimanipulasi client.
 * Secret key auto-rotate setiap 24 jam saat generateChallenge() dipanggil.
 *
 * Drop this file into app/Services/ in your Laravel project.
 * Secret key is stored in the settings table or .env.
 */

namespace App\Services;

class AltchaService
{
    // Berapa menit challenge berlaku
    const CHALLENGE_TTL_MINUTES = 15;

    // Berapa jam secret key otomatis dirotasi
    const SECRET_ROTATE_HOURS = 24;

    /**
     * Generate Altcha challenge dengan expiry di dalam salt
     * Teknik ini sesuai library resmi altcha-org/altcha-lib-php
     */
    public static function generateChallenge(int $complexity = 100000): array
    {
        $saltRaw  = bin2hex(random_bytes(16));
        $expires  = time() + (self::CHALLENGE_TTL_MINUTES * 60);

        // Embed expires di salt — widget kirim balik salt apa adanya
        $salt = $saltRaw . '?expires=' . $expires;

        $secretNumber = rand(1, $complexity);
        $challenge    = hash('sha256', $salt . $secretNumber);

        $signatureData = json_encode([
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $complexity,
            'salt'      => $salt,
        ]);

        $signature = hash_hmac('sha256', $signatureData, self::getSecret());

        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $complexity,
            'salt'      => $salt,
            'signature' => $signature,
        ];
    }

    /**
     * Verify solution — STATELESS: cek expiry dari salt + HMAC signature + PoW
     */
    public static function verifySolution(?string $payload): bool
    {
        if (!$payload) return false;

        $decoded = base64_decode($payload);
        if (!$decoded) return false;

        $data = json_decode($decoded, true);
        if (!$data) return false;

        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
            if (!isset($data[$field])) return false;
        }

        // 1. Cek expiry dari salt — hemat CPU kalau sudah basi
        $salt = $data['salt'];
        if (str_contains($salt, '?expires=')) {
            $parts   = explode('?expires=', $salt, 2);
            $expires = (int) ($parts[1] ?? 0);
            if ($expires > 0 && time() > $expires) {
                return false;
            }
        }

        // 2. Verifikasi algoritma
        if (strtoupper($data['algorithm']) !== 'SHA-256') return false;

        // 3. Verifikasi HMAC signature — salt (beserta expires) ikut dicek
        $signatureData = json_encode([
            'algorithm' => 'SHA-256',
            'challenge' => $data['challenge'],
            'maxnumber' => $data['maxnumber'] ?? 100000,
            'salt'      => $data['salt'],
        ]);
        $expectedSignature = hash_hmac('sha256', $signatureData, self::getSecret());

        // hash_equals() mencegah timing attack
        if (!hash_equals($expectedSignature, $data['signature'])) {
            return false;
        }

        // 4. Verifikasi range number
        $maxnumber = $data['maxnumber'] ?? 100000;
        if ($data['number'] < 0 || $data['number'] > $maxnumber) return false;

        // 5. Verifikasi proof-of-work
        $computedHash = hash('sha256', $data['salt'] . $data['number']);
        return $computedHash === $data['challenge'];
    }

    /**
     * Ambil secret key — auto-rotate setiap 24 jam
     * Rotate terjadi saat generateChallenge() dipanggil (showLogin/showRegister)
     * Tidak butuh crontab — trigger by request
     */
    public static function getSecret(): string
    {
        if (class_exists(\App\Models\Setting::class)) {
            $secret      = \App\Models\Setting::get('altcha_secret');
            $lastRotated = (int) \App\Models\Setting::get('altcha_secret_rotated_at', '0');

            $shouldRotate = !$secret
                || (time() - $lastRotated) > (self::SECRET_ROTATE_HOURS * 3600);

            if ($shouldRotate) {
                $secret = bin2hex(random_bytes(32));
                \App\Models\Setting::set('altcha_secret', $secret);
                \App\Models\Setting::set('altcha_secret_rotated_at', (string) time());
            }

            return $secret;
        }

        // Fallback ke .env jika tidak ada settings table
        $secret = config('app.altcha_secret', env('ALTCHA_SECRET'));
        if ($secret) return $secret;

        return bin2hex(random_bytes(32));
    }

    /**
     * Paksa rotate secret sekarang (manual reset)
     * php artisan tinker --execute="App\Services\AltchaService::rotateSecret();"
     */
    public static function rotateSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        if (class_exists(\App\Models\Setting::class)) {
            \App\Models\Setting::set('altcha_secret', $secret);
            \App\Models\Setting::set('altcha_secret_rotated_at', (string) time());
        }
        return $secret;
    }
}
