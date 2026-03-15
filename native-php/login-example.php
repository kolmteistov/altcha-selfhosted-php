<?php
/**
 * login.php
 * Example login page with Altcha captcha - Native PHP
 */

session_start();

// Your config
define('ALTCHA_SECRET', 'your_random_secret_key_here'); // Change this!
define('CAPTCHA_ENABLED', true);

require_once 'altcha-helper.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Verify captcha first
    if (CAPTCHA_ENABLED) {
        $payload = $_POST['altcha'] ?? '';
        if (!verifyAltchaSolution($payload)) {
            $error = 'Verifikasi captcha gagal. Silakan coba lagi.';
        }
    }

    if (!$error) {
        // Your login logic here
        // Example: check against database
        if ($email === 'admin@example.com' && $password === 'secret') {
            $_SESSION['user'] = $email;
            $success = 'Login berhasil!';
        } else {
            $error = 'Email atau password salah.';
        }
    }
}

// Generate challenge for widget
$challenge = CAPTCHA_ENABLED ? generateAltchaChallenge(100000) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>

    <?php if ($challenge): ?>
    <!-- Load Altcha from CDN - always gets latest version -->
    <script type="module" src="https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const widget    = document.querySelector('altcha-widget');
        const submitBtn = document.getElementById('btn-submit');

        if (widget && submitBtn) {
            // Disable button until Altcha finishes proof-of-work
            submitBtn.disabled = true;

            widget.addEventListener('statechange', (ev) => {
                if (ev.detail.state === 'verified') {
                    document.getElementById('altcha_input').value = ev.detail.payload;
                    // Stateless verification — no delay needed
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                    document.getElementById('altcha_input').value = '';
                }
            });
        }
    });
    </script>
    <?php endif; ?>
</head>
<body>
    <h2>Login</h2>

    <?php if ($error): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color:green"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <form method="POST">
        <div>
            <label>Email</label><br>
            <input type="email" name="email" required>
        </div>
        <br>
        <div>
            <label>Password</label><br>
            <input type="password" name="password" required>
        </div>
        <br>

        <?php if ($challenge): ?>
        <!-- Altcha Widget -->
        <div>
            <altcha-widget
                challengeurl="data:application/json;base64,<?= base64_encode(json_encode($challenge)) ?>"
                hidefooter
                hidelogo
            ></altcha-widget>
            <input type="hidden" name="altcha" id="altcha_input">
        </div>
        <br>
        <?php endif; ?>

        <button type="submit" id="btn-submit">Login</button>
    </form>
</body>
</html>
