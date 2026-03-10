{{-- resources/views/auth/login.blade.php --}}
{{-- Example login view with Altcha widget - Laravel --}}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>

    @if($challenge)
    {{-- Load Altcha from CDN - always gets latest version --}}
    <script type="module" src="https://cdn.jsdelivr.net/npm/altcha/dist/altcha.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const widget = document.querySelector('altcha-widget');
        if (widget) {
            widget.addEventListener('statechange', (ev) => {
                if (ev.detail.state === 'verified') {
                    document.getElementById('altcha_input').value = ev.detail.payload;
                }
            });
        }
    });
    </script>
    @endif
</head>
<body>
    <h2>Login</h2>

    @if($errors->any())
        <p style="color:red">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <label>Email</label><br>
            <input type="email" name="email" value="{{ old('email') }}" required>
        </div>
        <br>

        <div>
            <label>Password</label><br>
            <input type="password" name="password" required>
        </div>
        <br>

        @if($challenge)
        {{-- Altcha Widget --}}
        <div>
            <altcha-widget
                challengeurl="data:application/json;base64,{{ base64_encode(json_encode($challenge)) }}"
                hidefooter
                hidelogo
            ></altcha-widget>
            <input type="hidden" name="altcha" id="altcha_input">
        </div>
        <br>
        @endif

        <button type="submit">Login</button>
    </form>
</body>
</html>
