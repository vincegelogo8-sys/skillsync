<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AccountProvisioningService
{
    public function create(array $attributes): User
    {
        $user = new User([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => Hash::make($attributes['password']),
        ]);
        $user->role = $attributes['role'];
        $user->save();

        return $user;
    }
}
