<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AdvisoryThresholdService
{
    public const MAX_LIMIT = 65535;

    public function calculate(int $load, int $limit): array
    {
        if ($load < 0 || $limit < 0) {
            throw new InvalidArgumentException('Advisory load and limit cannot be negative.');
        }

        return [
            'load' => $load,
            'limit' => $limit,
            'remaining' => max(0, $limit - $load),
            'is_full' => $load >= $limit,
            'status' => $load >= $limit ? 'FULL' : 'AVAILABLE',
        ];
    }

    /** Read current limits and derived loads together; never count recommendation rows. */
    public function forFaculties(iterable $faculties): array
    {
        $ids = [];
        foreach ($faculties as $faculty) {
            $ids[] = $faculty->getKey();
        }
        $profiles = FacultyProfile::whereKey(array_unique($ids))->withCount('activeAssignments')->get();
        $result = [];
        foreach ($profiles as $profile) {
            $result[$profile->id] = $this->calculate($profile->active_assignments_count, $profile->advisory_limit);
        }

        return $result;
    }

    public function updateLimit(FacultyProfile $faculty, User $admin, int $limit): void
    {
        DB::transaction(function () use ($faculty, $admin, $limit) {
            $admin = User::findOrFail($admin->id);
            abort_unless($admin->role === User::ROLE_ADMIN, 403);
            $faculty = FacultyProfile::whereKey($faculty->id)->lockForUpdate()->firstOrFail();
            abort_unless($faculty->user->role === User::ROLE_FACULTY, 404);
            Validator::make(['advisory_limit' => $limit], ['advisory_limit' => ['required', 'integer', 'between:0,'.self::MAX_LIMIT]])->validate();
            $faculty->advisory_limit = $limit;
            $faculty->save();
        });
    }
}
