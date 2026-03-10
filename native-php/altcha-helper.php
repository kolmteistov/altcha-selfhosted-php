<?php
/**
 * altcha-helper.php
 * Altcha Self-Hosted Helper - Native PHP
 * 
 * Generates and verifies Altcha proof-of-work challenges
 * without any external API calls.
 */

/**
 * Generate Altcha challenge
 * 
 * @param int $complexity  Higher = harder for client to solve (default: 100000)
 * @return array           Challenge data to pass to widget
 */
function generateAltchaChallenge(int $complexity = 100000): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $salt         = bin2hex(random_bytes(16));
    $secretNumber = rand(1, $complexity);
    $challenge    = hash('sha256', $salt . $secretNumber);

    $signatureData = json_encode([
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => $complexity,
        'salt'      => $salt,
    ]);

    // Get secret from your config/database
    $serverSecret = defined('ALTCHA_SECRET') ? ALTCHA_SECRET : 'change_me_to_a_random_secret';
    $signature    = hash_hmac('sha256', $signatureData, $serverSecret);

    // Store in session for verification
    $_SESSION['altcha_challenge']  = $challenge;
    $_SESSION['altcha_salt']       = $salt;
    $_SESSION['altcha_secret']     = $secretNumber;
    $_SESSION['altcha_time']       = time();
    $_SESSION['altcha_complexity'] = $complexity;

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
function verifyAltchaSolution(?string $payload): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!$payload) return false;

    // Check session exists
    if (!isset($_SESSION['altcha_challenge'], $_SESSION['altcha_salt'], $_SESSION['altcha_secret'])) {
        return false;
    }

    // Check expired (5 minutes)
    if (isset($_SESSION['altcha_time']) && (time() - $_SESSION['altcha_time']) > 300) {
        clearAltchaSession();
        return false;
    }

    // Decode payload from widget
    $decoded = base64_decode($payload);
    if (!$decoded) return false;

    $data = json_decode($decoded, true);
    if (!$data) return false;

    // Check required fields
    foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
        if (!isset($data[$field])) return false;
    }

    // Verify each field
    if (strtoupper($data['algorithm']) !== 'SHA-256')          return false;
    if ($data['challenge'] !== $_SESSION['altcha_challenge'])   return false;
    if ($data['salt']      !== $_SESSION['altcha_salt'])        return false;
    if ($data['number'] > $_SESSION['altcha_complexity'] || $data['number'] < 0) return false;

    // Most important: verify proof-of-work solution
    $computedHash = hash('sha256', $data['salt'] . $data['number']);
    if ($computedHash !== $data['challenge']) return false;

    clearAltchaSession();
    return true;
}

/**
 * Clear Altcha session data after use
 */
function clearAltchaSession(): void
{
    unset(
        $_SESSION['altcha_challenge'],
        $_SESSION['altcha_salt'],
        $_SESSION['altcha_secret'],
        $_SESSION['altcha_time'],
        $_SESSION['altcha_complexity']
    );
}
