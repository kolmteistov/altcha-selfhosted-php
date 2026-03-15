<?php
/**
 * app/Services/AltchaService.php
 * Altcha Self-Hosted Service - Laravel
 *
 * STATELESS implementation — verifikasi via HMAC signature, tanpa session.
 * Tidak ada race condition, aman untuk multi-worker (Nginx, Load Balancer, dll).
 *
 * Drop this file into app/Services/ in your Laravel project.
 * Secret key is stored in the settings table or .env.
 */

namespace App\Services;

class AltchaService
{
    /**
     * Generate Altcha challenge
     * 
     * @param int $complexity  Higher = harder for client to solve (default: 100000)
     * @return array           Challenge data to pass to Blade view
     */
    public static function generateChallenge(int $complexity = 100000): array
    {
        $salt         = bin2hex(random_bytes(16));
        $secretNumber = rand(1, $complexity);
        $challenge    = hash('sha256', $salt . $secretNumber);

        $signatureData = json_encode([
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $complexity,
            'salt'      => $salt,
        ]);

        $signature = hash_hmac('sha256', $signatureData, self::getSecret());

        // Session tidak diperlukan lagi — verifikasi sepenuhnya via HMAC signature
        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $complexity,
            'salt'      => $salt,
            'signature' => $signature,
        ];
    }

    /**
     * Verify Altcha solution — STATELESS via HMAC signature
     * Tidak butuh session, tidak ada race condition
     */
    public static function verifySolution(?string $payload): bool
    {
        if (!$payload) return false;

        $decoded = base64_decode($payload);
        if (!$decoded) return false;

        $data = json_decode($decoded, true);
        if (!$data) return false;

        // Cek field wajib
        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
            if (!isset($data[$field])) return false;
        }

        // Verifikasi algoritma
        if (strtoupper($data['algorithm']) !== 'SHA-256') return false;

        // Verifikasi HMAC signature — pastikan challenge dibuat oleh server ini
        // Tidak perlu session: signature sudah membuktikan keaslian challenge
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

        // Verifikasi proof-of-work
        $computedHash = hash('sha256', $data['salt'] . $data['number']);
        if ($computedHash !== $data['challenge']) return false;

        // Verifikasi range number
        $maxnumber = $data['maxnumber'] ?? 100000;
        if ($data['number'] < 0 || $data['number'] > $maxnumber) return false;

        return true;
    }

    /**
     * Get or generate secret key
     * Reads from settings table first, falls back to .env
     */
    public static function getSecret(): string
    {
        // Try settings table first (if you have one)
        if (class_exists(\App\Models\Setting::class)) {
            $secret = \App\Models\Setting::get('altcha_secret');
            if ($secret) return $secret;
        }

        // Fallback to .env
        $secret = config('app.altcha_secret', env('ALTCHA_SECRET'));
        if ($secret) return $secret;

        // Auto-generate and save if nothing exists
        $secret = bin2hex(random_bytes(32));
        if (class_exists(\App\Models\Setting::class)) {
            \App\Models\Setting::set('altcha_secret', $secret);
        }

        return $secret;
    }

}
