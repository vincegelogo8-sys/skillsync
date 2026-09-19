<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAccountRequest;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        return view('admin.accounts.index', [
            'accounts' => User::query()
                ->whereIn('role', [User::ROLE_STUDENT, User::ROLE_FACULTY])
                ->select(['id', 'name', 'email', 'role', 'created_at'])
                ->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.accounts.create');
    }

    public function store(CreateAccountRequest $request, AccountProvisioningService $accounts): RedirectResponse
    {
        $account = $accounts->create($request->validated());

        return redirect()->route('admin.accounts.index')->with(
            'status',
            ucfirst($account->role).' account created for '.$account->name.'. Give them their email and the initial password you entered.'
        );
    }
}
