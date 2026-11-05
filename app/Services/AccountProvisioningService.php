<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountProvisioningService
{
    public function __construct(
        private StudentProfileService $studentProfiles,
        private FacultyProfileService $facultyProfiles,
    ) {}

    public function create(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = new User([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
            ]);
            $user->role = $attributes['role'];
            $user->save();

            $this->saveProfile($user, $attributes);

            return $user;
        });
    }

    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            if ($user->email !== $attributes['email']) {
                $user->email_verified_at = null;
            }

            $user->name = $attributes['name'];
            $user->email = $attributes['email'];

            if (! empty($attributes['password'])) {
                $user->password = Hash::make($attributes['password']);
            }

            $user->save();
            $this->saveProfile($user, $attributes);

            return $user->refresh();
        });
    }

    private function saveProfile(User $user, array $attributes): void
    {
        if ($user->role === User::ROLE_STUDENT) {
            $this->studentProfiles->save($user, array_intersect_key($attributes, array_flip([
                'student_number', 'course', 'year_level', 'section',
            ])));

            return;
        }

        if ($user->role === User::ROLE_FACULTY) {
            $this->facultyProfiles->save($user, [
                'name' => $attributes['name'],
                'department' => $attributes['department'],
            ]);
        }
    }
}
