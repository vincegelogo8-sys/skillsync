<?php

namespace App\Services;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            // The database also prevents duplicates from simultaneous submissions.
            throw ValidationException::withMessages([
                'expertise_area' => 'This faculty skill is already listed.',
            ]);
        }

        return $expertise;
    }

    /**
     * Replace the supported category selection without touching legacy entries outside the allowlist.
     *
     * @param  list<string>  $areas
     */
    public function saveCategories(FacultyProfile $profile, array $areas): void
    {
        DB::transaction(function () use ($profile, $areas) {
            $supported = config('expertise.areas');
            $selected = array_values(array_unique($areas));

            $toRemove = array_diff($supported, $selected);
            if ($toRemove !== []) {
                $profile->expertise()->whereIn('expertise_area', $toRemove)->delete();
            }

            foreach (array_intersect($supported, $selected) as $area) {
                $profile->expertise()->firstOrCreate(['expertise_area' => $area]);
            }
        });
    }

    public function delete(FacultyProfile $profile, FacultyExpertise $expertise): void
    {
        abort_unless($expertise->faculty_profile_id === $profile->id, 404);
        $expertise->delete();
    }
}
