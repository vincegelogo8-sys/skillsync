<?php

namespace App\Services;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountQueryService
{
    public function filters(Request $request): array
    {
        $type = $this->queryValue($request, 'type', [
            Rule::in([User::ROLE_STUDENT, User::ROLE_FACULTY]),
        ]) ?? User::ROLE_STUDENT;
        $course = $this->queryValue($request, 'course', [Rule::in(config('student_options.courses'))]);
        $section = $this->queryValue($request, 'section', [Rule::in(config('student_options.sections'))]);
        $status = $this->queryValue($request, 'status', [Rule::in(['all', 'active', 'deactivated'])]) ?? 'all';
        $search = $this->queryValue($request, 'q', ['max:100']);

        if ($type !== User::ROLE_STUDENT || $course === null) {
            $course = null;
            $section = null;
        }

        return [
            'type' => $type,
            'course' => $course,
            'section' => $section,
            'status' => $status,
            'q' => $search,
        ];
    }

    public function accounts(array $filters): Builder
    {
        $type = $filters['type'] ?? User::ROLE_STUDENT;
        $query = User::query()->where('role', $type);

        if (($filters['status'] ?? 'all') === 'active') {
            $query->active();
        } elseif (($filters['status'] ?? 'all') === 'deactivated') {
            $query->deactivated();
        }

        if ($type === User::ROLE_STUDENT) {
            $query->with('studentProfile');
            if (($filters['course'] ?? null) !== null || ($filters['section'] ?? null) !== null) {
                $query->whereHas('studentProfile', function (Builder $profile) use ($filters): void {
                    if (($filters['course'] ?? null) !== null) {
                        $profile->where('course', $filters['course']);
                    }
                    if (($filters['section'] ?? null) !== null) {
                        $profile->where('section', $filters['section']);
                    }
                });
            }
        } else {
            $query->with(['facultyProfile' => fn ($profile) => $profile->withCount([
                'activeAssignments',
                'adviserRequests as pending_requests_count' => fn ($requests) => $requests->where('status', 'pending'),
            ])]);
        }

        $this->applySearch($query, $filters['q'] ?? null);

        return $query;
    }

    public function studentProfiles(array $filters): Builder
    {
        $query = StudentProfile::query()->whereHas('user', function (Builder $users) use ($filters): void {
            $users->where('role', User::ROLE_STUDENT);
            if (($filters['status'] ?? 'all') === 'active') {
                $users->active();
            } elseif (($filters['status'] ?? 'all') === 'deactivated') {
                $users->deactivated();
            }
            $this->applySearch($users, $filters['q'] ?? null);
        });

        if (($filters['course'] ?? null) !== null) {
            $query->where('course', $filters['course']);
        }
        if (($filters['section'] ?? null) !== null) {
            $query->where('section', $filters['section']);
        }

        return $query;
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }
    }

    private function queryValue(Request $request, string $key, array $rules): ?string
    {
        $value = $request->query($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Validator::make([$key => $value], [
            $key => ['required', 'string', ...$rules],
        ])->passes() ? trim($value) : null;
    }
}
