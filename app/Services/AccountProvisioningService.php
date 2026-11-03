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

    public function update(User $user, array $attributes): User
    {
        if ($user->email !== $attributes['email']) {
            $user->email_verified_at = null;
        }

        $user->name = $attributes['name'];
        $user->email = $attributes['email'];

        if (! empty($attributes['password'])) {
            $user->password = Hash::make($attributes['password']);
        }

        $user->save();

        return $user;
    }
}
