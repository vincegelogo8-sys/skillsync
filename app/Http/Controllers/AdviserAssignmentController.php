<?php

namespace App\Http\Controllers;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\User;
use App\Services\AdviserAssignmentService;
use App\Services\AdvisoryThresholdService;
use App\Support\CollectionPaginator;
use App\Support\ListSorting;
use App\Support\NameSortKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AdviserAssignmentController extends Controller
{
    public function index(
        Request $request,
        AdvisoryThresholdService $threshold,
        CollectionPaginator $paginator,
        NameSortKey $nameSortKey
    ): View
    {
        $role = $request->user()->role;
        if ($role === User::ROLE_STUDENT) {
            $assignments = AdviserAssignment::with(['researchProposal.studentProfile.user', 'facultyProfile.user', 'approver'])
                ->whereHas('researchProposal', fn ($query) => $query->where('student_profile_id', $request->user()->studentProfile?->id ?? 0))
                ->latest('assigned_at')->latest('id')->paginate(15);

            return view('assignments.index', ['assignments' => $assignments, 'role' => $role]);
        }

        $filters = $this->filters($request);
        $sortOptions = $role === User::ROLE_ADMIN
            ? ($filters['view'] === 'grouped'
                ? ['adviser' => 'Adviser', 'students' => 'Number of students', 'capacity' => 'Available capacity']
                : ['assigned_date' => 'Assigned date', 'adviser' => 'Adviser', 'student' => 'Student', 'course' => 'Course', 'section' => 'Section'])
            : ['assigned_date' => 'Assigned date', 'student' => 'Student', 'section' => 'Section', 'topic' => 'Research topic'];
        $defaults = $role === User::ROLE_ADMIN && $filters['view'] === 'grouped'
            ? ['sort' => 'adviser', 'dir' => 'asc']
            : ['sort' => 'assigned_date', 'dir' => 'desc'];
        $sorting = ListSorting::parse($request, array_keys($sortOptions), $defaults['sort'], $defaults['dir']);

        if ($role === User::ROLE_ADMIN) {
            $grouped = $filters['view'] === 'grouped';
            $base = $this->assignmentQuery($filters, $grouped);
            $courseCounts = $this->assignmentCourseCounts($filters, $grouped);
            $sectionCounts = $filters['course'] === null ? [] : $this->assignmentSectionCounts($filters, $grouped);
            if ($grouped) {
                $assignments = $base->get();
                $facultyUsers = User::where('role', User::ROLE_FACULTY)->with('facultyProfile')->get();
                $facultyProfiles = $facultyUsers->pluck('facultyProfile')->filter()->values();
                $capacities = $threshold->forFaculties($facultyProfiles);
                $byFaculty = $assignments->groupBy('faculty_profile_id');
                $advisers = $facultyUsers
                    ->map(function (User $faculty) use ($byFaculty, $capacities, $filters, $nameSortKey): array {
                        $profile = $faculty->facultyProfile;
                        $students = ($byFaculty->get($profile?->id) ?? collect())->sort(function (AdviserAssignment $left, AdviserAssignment $right) use ($nameSortKey): int {
                            $leftProfile = $left->researchProposal->studentProfile;
                            $rightProfile = $right->researchProposal->studentProfile;
                            return strcasecmp((string) $leftProfile?->course, (string) $rightProfile?->course)
                                ?: strcasecmp((string) $leftProfile?->section, (string) $rightProfile?->section)
                                ?: strcmp($nameSortKey->key((string) $leftProfile?->user?->name), $nameSortKey->key((string) $rightProfile?->user?->name))
                                ?: ($left->id <=> $right->id);
                        })->values();
                        $limit = $profile?->advisory_limit ?? 0;
                        $capacity = $capacities[$profile?->id] ?? ['load' => 0, 'limit' => $limit, 'remaining' => $limit, 'is_full' => $limit <= 0, 'status' => $limit <= 0 ? 'FULL' : 'AVAILABLE'];
                        $hasStudentFilter = $filters['course'] !== null || $filters['section'] !== null || $filters['q'] !== null;

                        return ['faculty' => $faculty, 'profile' => $profile, 'assignments' => $students, 'capacity' => $capacity, 'hasStudentFilter' => $hasStudentFilter];
                    })
                    ->filter(fn (array $adviser) => ! $adviser['hasStudentFilter'] || $adviser['assignments']->isNotEmpty())
                    ->sort(function (array $left, array $right) use ($sorting, $nameSortKey): int {
                        $sort = $sorting['sort'];
                        $comparison = match ($sort) {
                            'students' => $left['assignments']->count() <=> $right['assignments']->count(),
                            'capacity' => $left['capacity']['remaining'] <=> $right['capacity']['remaining'],
                            default => strcmp($nameSortKey->key((string) $left['faculty']->name), $nameSortKey->key((string) $right['faculty']->name)),
                        };
                        if ($comparison !== 0 && $sorting['dir'] === 'desc') {
                            $comparison *= -1;
                        }

                        return $comparison ?: ($left['faculty']->id <=> $right['faculty']->id);
                    })->values();

                return view('assignments.index', [
                    'role' => $role,
                    'viewMode' => 'grouped',
                    'advisers' => $paginator->paginate($advisers, $request),
                    'assignments' => collect(),
                    'filters' => $filters,
                    'sorting' => $sorting,
                    'sortOptions' => $sortOptions,
                    'courseCounts' => $courseCounts,
                    'sectionCounts' => $sectionCounts,
                    'allCourseCount' => $base->count(),
                    'queryParams' => $this->queryParams($filters, $sorting),
                ]);
            }

            $assignments = $this->sortAssignments($base->get(), $sorting, $nameSortKey);

            return view('assignments.index', [
                'role' => $role,
                'viewMode' => 'list',
                'assignments' => $paginator->paginate($assignments, $request),
                'advisers' => collect(),
                'filters' => $filters,
                'sorting' => $sorting,
                'sortOptions' => $sortOptions,
                'courseCounts' => $courseCounts,
                'sectionCounts' => $sectionCounts,
                'allCourseCount' => $base->count(),
                'queryParams' => $this->queryParams($filters, $sorting),
            ]);
        }

        $facultyId = $request->user()->facultyProfile?->id ?? 0;
        $base = $this->assignmentQuery($filters, false, $facultyId);
        $courseCounts = $this->assignmentCourseCounts($filters, false, $facultyId);
        $sectionCounts = $filters['course'] === null ? [] : $this->assignmentSectionCounts($filters, false, $facultyId);
        $assignments = $this->sortAssignments($base->get(), $sorting, $nameSortKey);
        $capacity = $request->user()->facultyProfile
            ? ($threshold->forFaculties([$request->user()->facultyProfile])[$facultyId] ?? ['load' => 0, 'limit' => 0, 'is_full' => true])
            : ['load' => 0, 'limit' => 0, 'is_full' => true];

        return view('assignments.index', [
            'role' => $role,
            'viewMode' => $filters['view'],
            'assignments' => $paginator->paginate($assignments, $request),
            'advisers' => collect(),
            'filters' => $filters,
            'sorting' => $sorting,
            'sortOptions' => $sortOptions,
            'courseCounts' => $courseCounts,
            'sectionCounts' => $sectionCounts,
            'allCourseCount' => $base->count(),
            'queryParams' => $this->queryParams($filters, $sorting),
            'capacity' => $capacity,
        ]);
    }

    public function viewAssignmentProposal(Request $request, AdviserAssignment $assignment): Response
    {
        abort_unless(
            $request->user()->role === User::ROLE_FACULTY
                && $request->user()->facultyProfile?->id === $assignment->faculty_profile_id
                && $assignment->status === AdviserAssignment::STATUS_ACTIVE,
            404
        );

        $proposal = $assignment->researchProposal;
        abort_unless($proposal, 404);

        return response()->view('proposals.reader', [
            'proposal' => $proposal->loadMissing(['studentProfile.user', 'analysis']),
            'backUrl' => route('faculty.assignments.index'),
            'watermarkName' => $request->user()->name,
            'watermarkTime' => now()->format('Y-m-d H:i T'),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function filters(Request $request): array
    {
        $read = fn (string $key, array $rules = []) => $this->validQuery($request, $key, $rules);
        $role = $request->user()->role;
        $view = $read('view', ['in:'.($role === User::ROLE_ADMIN ? 'grouped,list' : 'list,sections')]);
        $course = $read('course', ['in:'.implode(',', config('student_options.courses'))]);
        $section = $read('section', ['in:'.implode(',', config('student_options.sections'))]);
        return [
            'view' => $view ?? ($role === User::ROLE_ADMIN ? 'grouped' : 'list'),
            'course' => $course,
            'section' => $course === null ? null : $section,
            'q' => $read('q', ['max:100']),
        ];
    }

    private function validQuery(Request $request, string $key, array $rules): ?string
    {
        $value = $request->query($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Validator::make([$key => $value], [$key => ['required', 'string', ...$rules]])->passes() ? trim($value) : null;
    }

    private function assignmentQuery(array $filters, bool $activeOnly, ?int $facultyId = null): Builder
    {
        $query = AdviserAssignment::with([
            'researchProposal.studentProfile.user',
            'facultyProfile.user',
            'approver',
        ]);
        if ($activeOnly) {
            $query->where('adviser_assignments.status', AdviserAssignment::STATUS_ACTIVE);
        }
        if ($facultyId !== null) {
            $query->where('faculty_profile_id', $facultyId);
        }
        if ($filters['course'] !== null || $filters['section'] !== null) {
            $query->whereHas('researchProposal.studentProfile', function (Builder $students) use ($filters): void {
                if ($filters['course'] !== null) {
                    $students->where('course', $filters['course']);
                }
                if ($filters['section'] !== null) {
                    $students->where('section', $filters['section']);
                }
            });
        }
        if ($filters['q'] !== null) {
            $query->where(function (Builder $search) use ($filters, $facultyId): void {
                if ($facultyId === null) {
                    $search->whereHas('facultyProfile.user', fn (Builder $users) => $users->where('name', 'like', '%'.$filters['q'].'%'));
                }
                $search->orWhereHas('researchProposal', fn (Builder $proposals) => $proposals->where('title', 'like', '%'.$filters['q'].'%'))
                    ->orWhereHas('researchProposal.studentProfile', fn (Builder $students) => $students->where('student_number', 'like', '%'.$filters['q'].'%'))
                    ->orWhereHas('researchProposal.studentProfile.user', fn (Builder $users) => $users->where('name', 'like', '%'.$filters['q'].'%'));
            });
        }

        return $query;
    }

    private function assignmentCourseCounts(array $filters, bool $activeOnly, ?int $facultyId = null): array
    {
        $query = $this->assignmentQuery([...$filters, 'course' => null, 'section' => null], $activeOnly, $facultyId)
            ->join('research_proposals as count_proposals', 'count_proposals.id', '=', 'adviser_assignments.research_proposal_id')
            ->join('student_profiles as count_students', 'count_students.id', '=', 'count_proposals.student_profile_id')
            ->whereIn('count_students.course', config('student_options.courses'))
            ->selectRaw('count_students.course as course, COUNT(*) as aggregate')
            ->groupBy('count_students.course')
            ->pluck('aggregate', 'course');
        $counts = array_fill_keys(config('student_options.courses'), 0);
        foreach ($query as $course => $count) {
            $counts[$course] = (int) $count;
        }

        return $counts;
    }

    private function assignmentSectionCounts(array $filters, bool $activeOnly, ?int $facultyId = null): array
    {
        $query = $this->assignmentQuery([...$filters, 'section' => null], $activeOnly, $facultyId)
            ->join('research_proposals as count_proposals', 'count_proposals.id', '=', 'adviser_assignments.research_proposal_id')
            ->join('student_profiles as count_students', 'count_students.id', '=', 'count_proposals.student_profile_id')
            ->where('count_students.course', $filters['course'])
            ->whereIn('count_students.section', config('student_options.sections'))
            ->selectRaw('count_students.section as section, COUNT(*) as aggregate')
            ->groupBy('count_students.section')
            ->pluck('aggregate', 'section');
        $counts = array_fill_keys(config('student_options.sections'), 0);
        foreach ($query as $section => $count) {
            $counts[$section] = (int) $count;
        }

        return $counts;
    }

    private function sortAssignments(Collection $assignments, array $sorting, NameSortKey $nameSortKey): Collection
    {
        return $assignments->sort(function (AdviserAssignment $left, AdviserAssignment $right) use ($sorting, $nameSortKey): int {
            $leftStudent = $left->researchProposal->studentProfile;
            $rightStudent = $right->researchProposal->studentProfile;
            $comparison = match ($sorting['sort']) {
                'adviser' => strcmp($nameSortKey->key((string) $left->facultyProfile?->user?->name), $nameSortKey->key((string) $right->facultyProfile?->user?->name)),
                'student' => strcmp($nameSortKey->key((string) $leftStudent?->user?->name), $nameSortKey->key((string) $rightStudent?->user?->name)),
                'course' => strcasecmp((string) $leftStudent?->course, (string) $rightStudent?->course),
                'section' => strcasecmp((string) $leftStudent?->section, (string) $rightStudent?->section),
                'topic' => strcasecmp($left->researchProposal->title, $right->researchProposal->title),
                default => $left->assigned_at <=> $right->assigned_at,
            };
            if ($comparison !== 0 && $sorting['dir'] === 'desc') {
                $comparison *= -1;
            }

            if ($comparison !== 0) {
                return $comparison;
            }

            return $sorting['sort'] === 'assigned_date' && $sorting['dir'] === 'desc'
                ? ($right->id <=> $left->id)
                : ($left->id <=> $right->id);
        })->values();
    }

    private function queryParams(array $filters, array $sorting): array
    {
        return array_filter([
            'view' => $filters['view'],
            'course' => $filters['course'],
            'section' => $filters['section'],
            'q' => $filters['q'],
            'sort' => $sorting['sort'],
            'dir' => $sorting['dir'],
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function approve(Request $request, AdviserRequest $adviserRequest, AdviserAssignmentService $service): RedirectResponse
    {
        try {
            $service->approve($adviserRequest, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('faculty.requests.index')->withErrors($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('faculty.requests.index')->withErrors(['assignment' => 'The assignment could not be saved. No partial approval was kept. Please try again.']);
        }

        return redirect()->route('faculty.assignments.index')->with('status', 'adviser-assigned');
    }
}
