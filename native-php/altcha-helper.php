<?php
/**
 * altcha-helper.php
 * Altcha Self-Hosted Helper - Native PHP
 *
 * STATELESS implementation — verifikasi via HMAC signature, tanpa session.
 * Tidak ada race condition, aman untuk multi-worker / load balancer.
 */

/**
 * Generate Altcha challenge
 *
 * @param int $complexity  Higher = harder for client to solve (default: 100000)
 * @return array           Challenge data to pass to widget
 */
function generateAltchaChallenge(int $complexity = 100000): array
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

    $serverSecret = defined('ALTCHA_SECRET') ? ALTCHA_SECRET : 'change_me_to_a_random_secret';
    $signature    = hash_hmac('sha256', $signatureData, $serverSecret);

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
 *
 * @param string|null $payload  Base64 payload from widget hidden input
 * @return bool
 */
function verifyAltchaSolution(?string $payload): bool
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
    $signatureData = json_encode([
        'algorithm' => 'SHA-256',
        'challenge' => $data['challenge'],
        'maxnumber' => $data['maxnumber'] ?? 100000,
        'salt'      => $data['salt'],
    ]);
    $serverSecret      = defined('ALTCHA_SECRET') ? ALTCHA_SECRET : 'change_me_to_a_random_secret';
    $expectedSignature = hash_hmac('sha256', $signatureData, $serverSecret);

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
