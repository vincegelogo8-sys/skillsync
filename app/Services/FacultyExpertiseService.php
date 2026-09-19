<?php

namespace App\Services;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class FacultyExpertiseService
{
    public function save(FacultyProfile $profile, array $attributes, ?FacultyExpertise $expertise = null): FacultyExpertise
    {
        abort_if($expertise && $expertise->faculty_profile_id !== $profile->id, 404);

        $expertise ??= $profile->expertise()->make();

        try {
            $expertise->fill([
                'expertise_area' => $attributes['expertise_area'],
                'proficiency_score' => $attributes['proficiency_score'],
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            // The database also prevents duplicates from simultaneous submissions.
            throw ValidationException::withMessages([
                'expertise_area' => 'This expertise area is already listed. Edit its existing score instead.',
            ]);
        }

        return $expertise;
    }

    public function delete(FacultyProfile $profile, FacultyExpertise $expertise): void
    {
        abort_unless($expertise->faculty_profile_id === $profile->id, 404);
        $expertise->delete();
    }
}
