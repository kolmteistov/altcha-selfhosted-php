<?php
/**
 * app/Services/AltchaService.php
 * Altcha Self-Hosted Service - Laravel
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

        // Store in Laravel session
        session([
            'altcha_challenge'  => $challenge,
            'altcha_salt'       => $salt,
            'altcha_secret'     => $secretNumber,
            'altcha_time'       => time(),
            'altcha_complexity' => $complexity,
        ]);

        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $complexity,
            'salt'      => $salt,
            'signature' => $signature,
        ];
    }

    /**
     * Verify Altcha solution submitted by client
     * 
     * @param string|null $payload  Base64 payload from widget hidden input
     * @return bool
     */
    public static function verifySolution(?string $payload): bool
    {
        if (!$payload) return false;

        // Check session exists
        if (!session()->has('altcha_challenge') ||
            !session()->has('altcha_salt') ||
            !session()->has('altcha_secret')) {
            return false;
        }

        // Check expired (5 minutes)
        if ((time() - session('altcha_time', 0)) > 300) {
            self::clearSession();
            return false;
        }

        // Decode payload
        $decoded = base64_decode($payload);
        if (!$decoded) return false;

        $data = json_decode($decoded, true);
        if (!$data) return false;

        // Check required fields
        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
            if (!isset($data[$field])) return false;
        }

        // Verify each field
        if (strtoupper($data['algorithm']) !== 'SHA-256')              return false;
        if ($data['challenge'] !== session('altcha_challenge'))         return false;
        if ($data['salt']      !== session('altcha_salt'))              return false;
        if ($data['number'] > session('altcha_complexity') || $data['number'] < 0) return false;

        // Most important: verify proof-of-work solution
        $computedHash = hash('sha256', $data['salt'] . $data['number']);
        if ($computedHash !== $data['challenge']) return false;

        self::clearSession();
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

    /**
     * Clear Altcha session data after use
     */
    public static function clearSession(): void
    {
        session()->forget([
            'altcha_challenge',
            'altcha_salt',
            'altcha_secret',
            'altcha_time',
            'altcha_complexity',
        ]);
    }
}
