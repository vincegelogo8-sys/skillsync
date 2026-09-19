<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FacultyProfileService
{
    public function save(User $faculty, array $attributes): FacultyProfile
    {
        // Keep the account name and department consistent if either write fails.
        return DB::transaction(function () use ($faculty, $attributes) {
            $faculty->update(['name' => $attributes['name']]);

            $profile = $faculty->facultyProfile()->updateOrCreate([], [
                'department' => $attributes['department'],
            ]);
            $faculty->setRelation('facultyProfile', $profile);

            return $profile;
        });
    }
}
