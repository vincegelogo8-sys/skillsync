<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Separate sign-in page for administrators (/admin/login). Only admin accounts can
 * sign in here; students and faculty keep using /login. Logging out is shared.
 */
class AdminAuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.admin-login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate(adminPortal: true);

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }
}