<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;

class AuthController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        try {
            $authenticated = Auth::attempt($credentials, $request->boolean('remember'));
        } catch (QueryException $exception) {
            report($exception);

            return back()->withErrors(['email' => 'Database sedang tidak tersedia. Jalankan layanan MySQL lalu coba lagi.'])->onlyInput('email');
        }

        if (! $authenticated) {
            return back()->withErrors(['email' => 'Email atau password tidak sesuai.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended('/barang');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
