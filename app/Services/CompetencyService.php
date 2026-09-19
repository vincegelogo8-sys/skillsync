<?php

namespace App\Services;

use App\Models\FacultyCompetency;
use App\Models\FacultyProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CompetencyService
{
    public function save(FacultyProfile $profile, User $admin, array $attributes): FacultyCompetency
    {
        abort_unless($admin->role === User::ROLE_ADMIN && $profile->user->role === User::ROLE_FACULTY, 403);

        return DB::transaction(function () use ($profile, $admin, $attributes) {
            $profile = FacultyProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $competency = $profile->competency()->firstOrNew();
            $competency->fill(Arr::only($attributes, array_keys(FacultyCompetency::DIMENSIONS)));
            $competency->remarks = $attributes['remarks'] ?? null;
            $competency->evaluator()->associate($admin);
            $competency->save();

            return $competency;
        });
    }

    public function score(?FacultyCompetency $competency): ?float
    {
        if (! $competency) {
            return null;
        }

        $total = array_sum(Arr::only($competency->getAttributes(), array_keys(FacultyCompetency::DIMENSIONS)));

        return round($total / 25 * 100, 2);
    }
}
