<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        abort_unless(
            in_array($role, User::ROLES, true) && $request->user()?->role === $role,
            403,
            'You do not have access to this page.'
        );

        return $next($request);
    }
}
