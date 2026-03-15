# Altcha Self-Hosted — Native PHP & Laravel

Panduan implementasi **Altcha captcha self-hosted** untuk Native PHP dan Laravel/Composer.

Altcha menggunakan metode **proof-of-work** — tidak ada tracking, tidak ada request ke server eksternal, 100% berjalan di server kamu sendiri.

---

## Daftar Isi

- [Cara Kerja](#cara-kerja)
- [Native PHP](#native-php)
- [Laravel / Composer](#laravel--composer)
- [Konfigurasi Widget](#konfigurasi-widget)
- [Tips Keamanan](#tips-keamanan)

---

## Cara Kerja

```
Server                          Client (Browser)
  |                                    |
  |-- generate challenge() ---------->|
  |   (salt + hmac signature)          |
  |                                    |-- widget solves proof-of-work
  |                                    |   (mencari angka yang hash-nya cocok)
  |<-- submit payload (base64) --------|
  |                                    |
  |-- verifySolution(payload) -------->|
  |   (cek HMAC signature + PoW)       |
  |-- ✅ valid / ❌ invalid ---------->|
```

Tidak ada API call eksternal. Semua verifikasi terjadi di server kamu.

**Keunggulan implementasi ini: STATELESS**
Verifikasi tidak bergantung pada session — server hanya perlu memvalidasi HMAC signature dan bukti proof-of-work.
Aman untuk multi-worker, load balancer, dan tidak ada race condition.

---

## Native PHP

### 1. Tambahkan script Altcha di halaman

```html
<!-- Load dari CDN resmi - otomatis dapat update terbaru -->
<script type="module" src="https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js"></script>
```

### 2. Copy file helper

Copy `native-php/altcha-helper.php` ke project kamu.

### 3. Definisikan secret key

Di file config atau sebelum `require altcha-helper.php`:

```php
define('ALTCHA_SECRET', 'isi_dengan_random_string_panjang');
```

Generate secret yang aman:
```php
echo bin2hex(random_bytes(32));
```

### 4. Generate challenge di halaman login

```php
<?php
require_once 'altcha-helper.php';

$challenge = generateAltchaChallenge(100000); // complexity default
?>
```

### 5. Tambahkan widget di form

```html
<!-- Load script di <head> -->
<script type="module" src="https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const widget    = document.querySelector('altcha-widget');
    const submitBtn = document.getElementById('btn-submit');

    if (widget && submitBtn) {
        // Disable tombol sampai Altcha selesai proof-of-work
        submitBtn.disabled = true;

        widget.addEventListener('statechange', (ev) => {
            if (ev.detail.state === 'verified') {
                document.getElementById('altcha_input').value = ev.detail.payload;
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
                document.getElementById('altcha_input').value = '';
            }
        });
    }
});
</script>

<!-- Di dalam <form> -->
<altcha-widget
    challengeurl="data:application/json;base64,<?= base64_encode(json_encode($challenge)) ?>"
    hidefooter
    hidelogo
></altcha-widget>
<input type="hidden" name="altcha" id="altcha_input">

<!-- Tambahkan id="btn-submit" pada tombol submit -->
<button type="submit" id="btn-submit">Login</button>
```

> **Kenapa disable button?**
> Tombol di-disable sampai Altcha selesai kalkulasi proof-of-work di browser.
> Verifikasi sepenuhnya stateless via HMAC signature — tidak butuh session, tidak ada race condition.

### 6. Verifikasi saat form di-submit

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = $_POST['altcha'] ?? '';

    if (!verifyAltchaSolution($payload)) {
        $error = 'Verifikasi captcha gagal. Silakan coba lagi.';
    } else {
        // Lanjutkan proses login/register
    }
}
```

### Contoh Lengkap

Lihat file `native-php/login-example.php`.

---

## Laravel / Composer

### 1. Copy AltchaService

Copy `laravel/AltchaService.php` ke `app/Services/AltchaService.php`.

### 2. Simpan secret key

**Opsi A — via database settings table:**
```php
// Jalankan sekali lewat tinker
php artisan tinker --execute="
App\Models\Setting::create([
    'key'   => 'altcha_secret',
    'value' => bin2hex(random_bytes(32)),
]);
"
```

**Opsi B — via .env:**
```env
ALTCHA_SECRET=isi_dengan_random_string_panjang
```

Lalu di `config/app.php` tambahkan:
```php
'altcha_secret' => env('ALTCHA_SECRET'),
```

### 3. Generate challenge di Controller

```php
use App\Services\AltchaService;

public function showLogin()
{
    $challenge = AltchaService::generateChallenge(); // complexity default 100000
    return view('auth.login', compact('challenge'));
}
```

### 4. Tambahkan widget di Blade

```blade
{{-- Di <head> --}}
@if($challenge)
<script type="module" src="https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const widget    = document.querySelector('altcha-widget');
    const submitBtn = document.getElementById('btn-submit');

    if (widget && submitBtn) {
        // Disable tombol sampai Altcha selesai proof-of-work
        submitBtn.disabled = true;

        widget.addEventListener('statechange', (ev) => {
            if (ev.detail.state === 'verified') {
                document.getElementById('altcha_input').value = ev.detail.payload;
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
                document.getElementById('altcha_input').value = '';
            }
        });
    }
});
</script>
@endif

{{-- Di dalam form --}}
@if($challenge)
<altcha-widget
    challengeurl="data:application/json;base64,{{ base64_encode(json_encode($challenge)) }}"
    hidefooter
    hidelogo
></altcha-widget>
<input type="hidden" name="altcha" id="altcha_input">
@endif

{{-- Tambahkan id="btn-submit" pada tombol submit --}}
<button type="submit" id="btn-submit">Login</button>
```

> **Kenapa disable button?**
> Tombol di-disable sampai Altcha selesai kalkulasi proof-of-work di browser.
> Verifikasi sepenuhnya stateless via HMAC signature — tidak butuh session, tidak ada race condition.

### 5. Verifikasi di Controller

```php
public function login(Request $request)
{
    // Verifikasi captcha
    if (!AltchaService::verifySolution($request->input('altcha'))) {
        return back()->withErrors([
            'email' => 'Verifikasi captcha gagal. Silakan coba lagi.'
        ])->withInput();
    }

    // Lanjutkan proses login...
}
```

### Contoh Lengkap

- Controller: `laravel/AuthController-example.php`
- Blade view: `laravel/login-example.blade.php`

---

## Konfigurasi Widget

| Atribut | Fungsi |
|---------|--------|
| `challengeurl` | URL atau data URI challenge JSON |
| `hidefooter` | Sembunyikan footer "Protected by Altcha" |
| `hidelogo` | Sembunyikan logo Altcha |
| `auto="onload"` | Auto-solve tanpa klik user |
| `floating` | Mode floating widget |

Contoh dengan auto-solve (invisible captcha):
```html
<altcha-widget
    challengeurl="..."
    auto="onload"
    hidefooter
    hidelogo
></altcha-widget>
```

---

## Tips Keamanan

**Secret Key**
- Gunakan minimal 32 karakter random: `bin2hex(random_bytes(32))`
- Simpan di database atau `.env`, jangan hardcode di source code
- Jangan commit secret ke repository

**Complexity**
- `50000` — ringan, cocok untuk server lemah
- `100000` — default, balance antara keamanan dan kecepatan
- `200000` — lebih aman, sedikit lebih lambat di client

**Stateless Verification**
- Verifikasi via HMAC signature — tidak butuh session
- Aman untuk multi-worker, load balancer, dan concurrent request
- Tidak ada race condition

**Kombinasi dengan Rate Limit**
Altcha mencegah bot otomatis, tapi tetap kombinasikan dengan rate limit by email/username untuk perlindungan berlapis — terutama jika digunakan di Tor hidden service (jangan rate limit by IP).

---

## Referensi

- [Altcha Official](https://altcha.org)
- [Altcha GitHub](https://github.com/altcha-org/altcha)
- [CDN jsDelivr](https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js)

---

## Lisensi

MIT — bebas digunakan dan dimodifikasi.
