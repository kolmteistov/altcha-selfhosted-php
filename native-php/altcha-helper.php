<?php
/**
 * altcha-helper.php
 * Altcha Self-Hosted Helper - Native PHP
 *
 * STATELESS implementation — verifikasi via HMAC signature, tanpa session.
 * Expiry disimpan di dalam salt — tidak bisa dimanipulasi client.
 * Tidak ada race condition, aman untuk multi-worker / load balancer.
 */

// Berapa menit challenge berlaku (default: 15 menit)
define('ALTCHA_TTL_MINUTES', 15);

/**
 * Generate Altcha challenge dengan expiry di dalam salt
 */
function generateAltchaChallenge(int $complexity = 100000): array
{
    $saltRaw  = bin2hex(random_bytes(16));
    $expires  = time() + (ALTCHA_TTL_MINUTES * 60);

    // Embed expires di salt — widget kirim balik salt apa adanya
    // Teknik ini sesuai library resmi altcha-org/altcha-lib-php
    $salt = $saltRaw . '?expires=' . $expires;

    $secretNumber = rand(1, $complexity);
    $challenge    = hash('sha256', $salt . $secretNumber);

    $signatureData = json_encode([
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => $complexity,
        'salt'      => $salt,
    ]);

    $serverSecret = defined('ALTCHA_SECRET') ? ALTCHA_SECRET : 'change_me_to_a_random_secret';
    $signature    = hash_hmac('sha256', $signatureData, $serverSecret);

    return [
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => $complexity,
        'salt'      => $salt,
        'signature' => $signature,
    ];
}

/**
 * Verify Altcha solution — STATELESS: cek expiry dari salt + HMAC + PoW
 */
function verifyAltchaSolution(?string $payload): bool
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

    // 3. Verifikasi HMAC signature
    $signatureData = json_encode([
        'algorithm' => 'SHA-256',
        'challenge' => $data['challenge'],
        'maxnumber' => $data['maxnumber'] ?? 100000,
        'salt'      => $data['salt'],
    ]);
    $serverSecret      = defined('ALTCHA_SECRET') ? ALTCHA_SECRET : 'change_me_to_a_random_secret';
    $expectedSignature = hash_hmac('sha256', $signatureData, $serverSecret);

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
