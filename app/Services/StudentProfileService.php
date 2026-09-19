<?php

namespace App\Services;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfileService
{
    public function save(User $student, array $attributes): StudentProfile
    {
        // The authenticated user's relationship supplies the owner, never form input.
        $profile = $student->studentProfile()->updateOrCreate([], $attributes);
        $student->setRelation('studentProfile', $profile);

        return $profile;
    }
}
