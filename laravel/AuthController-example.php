<?php
/**
 * app/Http/Controllers/AuthController.php
 * Example Auth Controller with Altcha - Laravel
 */

namespace App\Http\Controllers;

use App\Services\AltchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    // Set to true to enable captcha
    const CAPTCHA_ENABLED = true;

    /**
     * Show login form
     */
    public function showLogin()
    {
        $challenge = self::CAPTCHA_ENABLED
            ? AltchaService::generateChallenge()
            : null;

        return view('auth.login', compact('challenge'));
    }

    /**
     * Handle login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // Verify captcha before anything else
        if (self::CAPTCHA_ENABLED) {
            if (!AltchaService::verifySolution($request->input('altcha'))) {
                return back()->withErrors([
                    'email' => 'Verifikasi captcha gagal. Silakan coba lagi.'
                ])->withInput();
            }
        }

        if (Auth::attempt($request->only('email', 'password'))) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'email' => 'Email atau password salah.'
        ])->withInput();
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
