<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAccountRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $type = $this->queryValue($request, 'type', [
            Rule::in([User::ROLE_STUDENT, User::ROLE_FACULTY]),
        ]) ?? User::ROLE_STUDENT;
        $section = $this->queryValue($request, 'section', [
            Rule::in(config('student_options.sections')),
        ]);
        $course = $this->queryValue($request, 'course', [
            Rule::in(config('student_options.courses')),
        ]);
        $search = $this->queryValue($request, 'q', ['max:100']);

        if ($type !== User::ROLE_STUDENT) {
            $section = null;
            $course = null;
        } elseif ($course === null) {
            $section = null;
        }

        $accountsQuery = User::query()
            ->where('role', $type)
            ->select(['id', 'name', 'email', 'role', 'created_at']);

        if ($type === User::ROLE_STUDENT) {
            $accountsQuery->with('studentProfile');
            if ($section !== null || $course !== null) {
                $accountsQuery->whereHas('studentProfile', function ($query) use ($section, $course): void {
                    if ($section !== null) {
                        $query->where('section', $section);
                    }
                    if ($course !== null) {
                        $query->where('course', $course);
                    }
                });
            }
        } else {
            $accountsQuery->with('facultyProfile');
        }

        if ($search !== null) {
            $accountsQuery->where($this->searchConstraint($search));
        }

        $allStudentCount = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->when($search !== null, fn ($query) => $query->where($this->searchConstraint($search)))
            ->count();

        $courseCounts = array_fill_keys(config('student_options.courses'), 0);
        $groupedCourseCounts = DB::table('student_profiles')
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->where('users.role', User::ROLE_STUDENT)
            ->whereIn('student_profiles.course', config('student_options.courses'))
            ->when($search !== null, fn ($query) => $query->where($this->searchConstraint($search)))
            ->select('student_profiles.course')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('student_profiles.course')
            ->pluck('aggregate', 'course');
        foreach ($groupedCourseCounts as $courseName => $count) {
            $courseCounts[$courseName] = (int) $count;
        }

        $sectionCounts = [];
        if ($course !== null) {
            $sectionCounts = array_fill_keys(config('student_options.sections'), 0);
            $groupedSectionCounts = DB::table('student_profiles')
                ->join('users', 'users.id', '=', 'student_profiles.user_id')
                ->where('users.role', User::ROLE_STUDENT)
                ->where('student_profiles.course', $course)
                ->whereIn('student_profiles.section', config('student_options.sections'))
                ->when($search !== null, fn ($query) => $query->where($this->searchConstraint($search)))
                ->select('student_profiles.section')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('student_profiles.section')
                ->pluck('aggregate', 'section');
            foreach ($groupedSectionCounts as $sectionName => $count) {
                $sectionCounts[$sectionName] = (int) $count;
            }
        }

        return view('admin.accounts.index', [
            'accounts' => $accountsQuery->orderByDesc('id')->paginate(20)->withQueryString(),
            'type' => $type,
            'section' => $section,
            'course' => $course,
            'search' => $search,
            'allStudentCount' => $allStudentCount,
            'courseCounts' => $courseCounts,
            'sectionCounts' => $sectionCounts,
            'studentCount' => User::where('role', User::ROLE_STUDENT)->count(),
            'facultyCount' => User::where('role', User::ROLE_FACULTY)->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.accounts.create');
    }

    public function edit(User $account): View
    {
        $this->ensureEditableAccount($account);
        $account->load(['studentProfile', 'facultyProfile']);

        return view('admin.accounts.edit', ['account' => $account]);
    }

    public function store(CreateAccountRequest $request, AccountProvisioningService $accounts): RedirectResponse
    {
        $account = $accounts->create($request->validated());

        return redirect()->route('admin.accounts.index')->with(
            'status',
            ucfirst($account->role).' account created for '.$account->name.'. Give them their email and the initial password you entered.'
        );
    }

    public function update(UpdateAccountRequest $request, User $account, AccountProvisioningService $accounts): RedirectResponse
    {
        $this->ensureEditableAccount($account);
        $accounts->update($account, $request->validated());

        return redirect()->route('admin.accounts.index')->with('status', 'Account updated successfully.');
    }

    private function ensureEditableAccount(User $account): void
    {
        abort_unless(
            in_array($account->role, [User::ROLE_STUDENT, User::ROLE_FACULTY], true),
            404
        );
    }

    private function queryValue(Request $request, string $key, array $rules): ?string
    {
        $value = $request->query($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $validator = Validator::make([$key => $value], [
            $key => ['required', 'string', ...$rules],
        ]);

        return $validator->passes() ? trim($value) : null;
    }

    private function searchConstraint(string $search): \Closure
    {
        return function ($query) use ($search): void {
            $query->where('users.name', 'like', '%'.$search.'%')
                ->orWhere('users.email', 'like', '%'.$search.'%');
        };
    }
}
