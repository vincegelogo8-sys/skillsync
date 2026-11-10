<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public const MESSAGE = 'This account has been deactivated. Please contact the administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isDeactivated()) {
            $loginRoute = $user->role === User::ROLE_ADMIN ? 'admin.login' : 'login';

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($loginRoute)->withErrors(['email' => self::MESSAGE]);
        }

        return $next($request);
    }
}