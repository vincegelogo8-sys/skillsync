<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(Request $request, DashboardSummaryService $summary): View
    {
        return view('dashboard', $summary->forUser($request->user()));
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $route = match ($request->user()->role) {
            User::ROLE_STUDENT => 'student.dashboard',
            User::ROLE_FACULTY => 'faculty.dashboard',
            User::ROLE_ADMIN => 'admin.dashboard',
            default => abort(403, 'Your account does not have a valid role.'),
        };

        return redirect()->route($route);
    }
}
