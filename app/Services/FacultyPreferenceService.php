<?php

namespace App\Services;

use App\Models\FacultyPreference;
use App\Models\FacultyProfile;
use Illuminate\Support\Facades\DB;

class FacultyPreferenceService
{
    public function save(FacultyProfile $profile, array $attributes): void
    {
        DB::transaction(function () use ($profile, $attributes) {
            // Serialize saves for this faculty and commit both lists together.
            $profile = FacultyProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            foreach ([
                FacultyPreference::TYPE_PROJECT => $attributes['project_types'],
                FacultyPreference::TYPE_TECHNOLOGY => $attributes['technologies'],
            ] as $type => $values) {
                $profile->preferences()->where('preference_type', $type)
                    ->whereNotIn('preference_value', $values)->delete();

                foreach ($values as $value) {
                    $profile->preferences()->firstOrCreate([
                        'preference_type' => $type,
                        'preference_value' => $value,
                    ]);
                }
            }
        });
    }
}
