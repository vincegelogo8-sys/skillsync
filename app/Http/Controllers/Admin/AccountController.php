<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountStatusRequest;
use App\Http\Requests\Admin\CreateAccountRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AccountQueryService;
use App\Services\AccountStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request, AccountQueryService $accountQueries): View
    {
        $filters = $accountQueries->filters($request);
        $type = $filters['type'];
        $course = $filters['course'];
        $section = $filters['section'];
        $search = $filters['q'];
        $courseCounts = array_fill_keys(config('student_options.courses'), 0);
        $countFilters = ['type' => User::ROLE_STUDENT, 'status' => $filters['status'], 'q' => $search];
        $groupedCourseCounts = $accountQueries->studentProfiles($countFilters)
            ->whereIn('course', config('student_options.courses'))
            ->select('course')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('student_profiles.course')
            ->pluck('aggregate', 'course');
        foreach ($groupedCourseCounts as $courseName => $count) {
            $courseCounts[$courseName] = (int) $count;
        }

        $sectionCounts = [];
        if ($course !== null) {
            $sectionCounts = array_fill_keys(config('student_options.sections'), 0);
            $groupedSectionCounts = $accountQueries->studentProfiles([
                ...$countFilters,
                'course' => $course,
            ])
                ->whereIn('section', config('student_options.sections'))
                ->select('section')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('section')
                ->pluck('aggregate', 'section');
            foreach ($groupedSectionCounts as $sectionName => $count) {
                $sectionCounts[$sectionName] = (int) $count;
            }
        }

        $allStudentCount = $accountQueries->accounts($countFilters)->count();

        return view('admin.accounts.index', [
            'accounts' => $accountQueries->accounts($filters)->orderByDesc('users.id')->paginate(20)->withQueryString(),
            'type' => $type,
            'section' => $section,
            'course' => $course,
            'search' => $search,
            'status' => $filters['status'],
            'allStudentCount' => $allStudentCount,
            'courseCounts' => $courseCounts,
            'sectionCounts' => $sectionCounts,
            'studentCount' => User::where('role', User::ROLE_STUDENT)->count(),
            'facultyCount' => User::where('role', User::ROLE_FACULTY)->count(),
        ]);
    }

    public function deactivate(User $account, AccountStatusService $statuses): RedirectResponse
    {
        $this->ensureEditableAccount($account);
        $result = $statuses->deactivate($account);

        return redirect()->route('admin.accounts.index')->with('status', $result['changed'] > 0
            ? $account->name.' has been deactivated.'
            : $account->name.' was already inactive.');
    }

    public function activate(User $account, AccountStatusService $statuses): RedirectResponse
    {
        $this->ensureEditableAccount($account);
        $result = $statuses->activate($account);

        return redirect()->route('admin.accounts.index')->with('status', $result['changed'] > 0
            ? $account->name.' has been activated.'
            : $account->name.' was already active.');
    }

    public function bulkStatus(
        AccountStatusRequest $request,
        AccountQueryService $accountQueries,
        AccountStatusService $statuses
    ): RedirectResponse {
        $validated = $request->validated();
        $action = $validated['action'];
        if ((string) ($validated['select_all_matching'] ?? '') === '1') {
            $ids = $accountQueries->accounts([
                'type' => User::ROLE_STUDENT,
                'course' => $validated['course'] ?? null,
                'section' => $validated['section'] ?? null,
                'q' => $validated['q'] ?? null,
                'status' => $validated['status'] ?? 'all',
            ])->pluck('id')->all();
        } else {
            $ids = $validated['ids'];
        }

        $result = $statuses->{$action}($ids);
        $verb = $action === 'deactivate' ? 'deactivated' : 'activated';
        $already = $action === 'deactivate' ? 'already inactive' : 'already active';

        return redirect()->route('admin.accounts.index')
            ->with('status', $result['changed'].' accounts '.$verb.', '.$result['skipped'].' were '.$already.'.');
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
}
