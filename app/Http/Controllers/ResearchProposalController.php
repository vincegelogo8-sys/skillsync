<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResearchProposalRequest;
use App\Models\ResearchProposal;
use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\ProposalExtractionService;
use App\Services\ResearchProposalService;
use App\Support\CollectionPaginator;
use App\Support\ListSorting;
use App\Support\NameSortKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResearchProposalController extends Controller
{
    public function index(
        Request $request,
        CollectionPaginator $paginator,
        NameSortKey $nameSortKey
    ): View|RedirectResponse {
        $admin =
            $request->user()->role === User::ROLE_ADMIN;

        if (
            ! $admin
            && ! $request->user()->studentProfile
        ) {
            return $this->completeProfile();
        }

        if (! $admin) {
            return view('student.proposal.index', [
                'proposals' => $request->user()->studentProfile->researchProposals()->latest('id')->paginate(15),
                'admin' => false,
            ]);
        }

        $filters = $this->adminProposalFilters($request);
        $sortOptions = [
            'newest' => 'Newest',
            'oldest' => 'Oldest',
            'title' => 'Title (A-Z)',
            'student' => 'Student',
            'course' => 'Course',
            'section' => 'Section',
            'adviser' => 'Adviser',
            'status' => 'Status',
        ];
        $sorting = ListSorting::parse($request, array_keys($sortOptions), 'newest', 'desc');
        $query = $this->adminProposalQuery($filters)
            ->with([
                'studentProfile.user',
                'assignment' => fn ($assignments) => $assignments->where('status', 'active'),
                'assignment.facultyProfile.user',
            ]);
        $proposals = $query->get()->sort(function (ResearchProposal $left, ResearchProposal $right) use ($sorting, $nameSortKey): int {
            $leftStudent = $left->studentProfile;
            $rightStudent = $right->studentProfile;
            $leftAdviser = $left->assignment?->facultyProfile?->user?->name;
            $rightAdviser = $right->assignment?->facultyProfile?->user?->name;
            if ($sorting['sort'] === 'adviser' && ($leftAdviser === null) !== ($rightAdviser === null)) {
                return $leftAdviser === null ? 1 : -1;
            }
            $comparison = match ($sorting['sort']) {
                'oldest' => $left->created_at <=> $right->created_at,
                'title' => strcasecmp($left->title, $right->title),
                'student' => strcmp($nameSortKey->key((string) $leftStudent?->user?->name), $nameSortKey->key((string) $rightStudent?->user?->name)),
                'course' => strcasecmp((string) $leftStudent?->course, (string) $rightStudent?->course),
                'section' => strcasecmp((string) $leftStudent?->section, (string) $rightStudent?->section),
                'adviser' => strcmp($nameSortKey->key((string) $leftAdviser), $nameSortKey->key((string) $rightAdviser)),
                'status' => strcasecmp($left->status, $right->status),
                default => $right->created_at <=> $left->created_at,
            };
            if ($comparison !== 0 && $sorting['dir'] === 'desc' && $sorting['sort'] !== 'newest' && $sorting['sort'] !== 'oldest') {
                $comparison *= -1;
            }
            if ($comparison !== 0) {
                return $comparison;
            }

            return $sorting['sort'] === 'newest'
                ? ($right->id <=> $left->id)
                : ($left->id <=> $right->id);
        })->values();

        $baseFilters = [...$filters, 'course' => null, 'section' => null];
        $courseCounts = $this->proposalOptionCounts($baseFilters, 'course');
        $sectionCounts = $filters['course'] === null ? [] : $this->proposalOptionCounts($filters, 'section');
        $faculty = FacultyProfile::whereHas('user', fn (Builder $users) => $users->where('role', User::ROLE_FACULTY))
            ->with('user')->get()
            ->sort(fn (FacultyProfile $left, FacultyProfile $right) => strcmp(
                $nameSortKey->key((string) $left->user?->name),
                $nameSortKey->key((string) $right->user?->name)
            ) ?: ($left->id <=> $right->id))->values();
        $queryParams = array_filter([
            'course' => $filters['course'],
            'section' => $filters['section'],
            'adviser' => $filters['adviser'],
            'sort' => $sorting['sort'],
            'dir' => $sorting['dir'],
        ], fn ($value) => $value !== null && $value !== '');

        return view('student.proposal.index', [
            'proposals' => $paginator->paginate($proposals, $request),
            'admin' => true,
            'filters' => $filters,
            'sorting' => $sorting,
            'sortOptions' => $sortOptions,
            'courseCounts' => $courseCounts,
            'sectionCounts' => $sectionCounts,
            'allCourseCount' => $this->adminProposalQuery($baseFilters)->count(),
            'facultyOptions' => $faculty,
            'queryParams' => $queryParams,
        ]);
    }

    private function adminProposalFilters(Request $request): array
    {
        $course = $this->validProposalQuery($request, 'course', ['in:'.implode(',', config('student_options.courses'))]);
        $section = $this->validProposalQuery($request, 'section', ['in:'.implode(',', config('student_options.sections'))]);
        $adviser = $request->query('adviser');
        if ($adviser !== 'none') {
            $adviser = is_string($adviser) && ctype_digit($adviser)
                && FacultyProfile::whereKey((int) $adviser)->whereHas('user', fn (Builder $users) => $users->where('role', User::ROLE_FACULTY))->exists()
                ? (string) (int) $adviser
                : null;
        }

        return [
            'course' => $course,
            'section' => $course === null ? null : $section,
            'adviser' => $adviser,
        ];
    }

    private function validProposalQuery(Request $request, string $key, array $rules): ?string
    {
        $value = $request->query($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Validator::make([$key => $value], [$key => ['required', 'string', ...$rules]])->passes() ? trim($value) : null;
    }

    private function adminProposalQuery(array $filters): Builder
    {
        $query = ResearchProposal::query();
        if ($filters['course'] !== null || $filters['section'] !== null) {
            $query->whereHas('studentProfile', function (Builder $students) use ($filters): void {
                if ($filters['course'] !== null) {
                    $students->where('course', $filters['course']);
                }
                if ($filters['section'] !== null) {
                    $students->where('section', $filters['section']);
                }
            });
        }
        if (($filters['adviser'] ?? null) === 'none') {
            $query->whereDoesntHave('assignment', fn (Builder $assignments) => $assignments->where('status', 'active'));
        } elseif (($filters['adviser'] ?? null) !== null) {
            $query->whereHas('assignment', fn (Builder $assignments) => $assignments
                ->where('status', 'active')->where('faculty_profile_id', (int) $filters['adviser']));
        }

        return $query;
    }

    private function proposalOptionCounts(array $filters, string $column): array
    {
        $options = config('student_options.'.($column === 'course' ? 'courses' : 'sections'));
        $query = $this->adminProposalQuery($filters)
            ->join('student_profiles as option_students', 'option_students.id', '=', 'research_proposals.student_profile_id')
            ->whereIn('option_students.'.$column, $options)
            ->select([])
            ->selectRaw('option_students.'.$column.' as option_value, COUNT(*) as aggregate')
            ->groupBy('option_students.'.$column)
            ->pluck('aggregate', 'option_value');
        $counts = array_fill_keys($options, 0);
        foreach ($query as $value => $count) {
            $counts[$value] = (int) $count;
        }

        return $counts;
    }

    public function create(
        Request $request
    ): View|RedirectResponse {
        return $request->user()->studentProfile
            ? view('student.proposal.create')
            : $this->completeProfile();
    }

    public function store(
        ResearchProposalRequest $request,
        ResearchProposalService $service,
        ProposalExtractionService $extractor,
        ProposalAnalysisService $analyzer
    ): RedirectResponse {
        if (! $request->user()->studentProfile) {
            return $this->completeProfile();
        }

        $proposal = $service->upload(
            $request->user()->studentProfile,
            $request->validated('title'),
            $request->file('document')
        );

        try {
            $extracted = $extractor->extract(
                $proposal
            );

            if ($extracted->analysis) {
                $analyzer->analyze(
                    $extracted
                );
            }
        } catch (ValidationException $exception) {
            return redirect()
                ->route(
                    'student.proposals.show',
                    $proposal
                )
                ->withErrors(
                    $exception->errors()
                )
                ->with(
                    'status',
                    'proposal-uploaded'
                );
        }

        return redirect()
            ->route(
                'student.proposals.show',
                $proposal
            )
            ->with(
                'status',
                'proposal-uploaded'
            );
    }

    public function show(
        Request $request,
        ResearchProposal $proposal
    ): View {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        $proposal->load(
            'analysis',
            'assignment.facultyProfile.user'
        );

        return view(
            'student.proposal.show',
            [
                'proposal' => $proposal,

                'admin' => $request->user()->role
                    === User::ROLE_ADMIN,
            ]
        );
    }

    /**
     * Original extraction/retry extraction.
     *
     * This does not overwrite an already existing analysis.
     */
    public function extract(
        Request $request,
        ResearchProposal $proposal,
        ProposalExtractionService $extractor,
        ProposalAnalysisService $analyzer
    ): RedirectResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        $extracted = $extractor->extract(
            $proposal
        );

        if ($extracted->analysis && ! $extracted->analysis->hasCurrentAnalysis()) {
            $analyzer->analyze(
                $extracted
            );
        }

        return redirect()
            ->route(
                (
                    $request->user()->role
                    === User::ROLE_ADMIN
                        ? 'admin'
                        : 'student'
                ).'.proposals.show',
                $proposal
            );
    }

    /**
     * Force a fresh extraction from the original uploaded file.
     *
     * This does NOT delete the uploaded PDF/DOCX.
     */
    public function refreshExtraction(
        Request $request,
        ResearchProposal $proposal,
        ProposalExtractionService $extractor
    ): RedirectResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        try {
            $extractor->refresh($proposal);
        } catch (ValidationException $exception) {
            return redirect()
                ->route(
                    (
                        $request->user()->role
                        === User::ROLE_ADMIN
                            ? 'admin'
                            : 'student'
                    ).'.proposals.show',
                    $proposal
                )
                ->withErrors(
                    $exception->errors()
                );
        }

        $proposal = $proposal->fresh();

        if (
            $proposal->status
            === 'extraction_failed'
        ) {
            return redirect()
                ->route(
                    (
                        $request->user()->role
                        === User::ROLE_ADMIN
                            ? 'admin'
                            : 'student'
                    ).'.proposals.show',
                    $proposal
                )
                ->with(
                    'status',
                    'extraction-refresh-failed'
                );
        }

        return redirect()
            ->route(
                (
                    $request->user()->role
                    === User::ROLE_ADMIN
                        ? 'admin'
                        : 'student'
                ).'.proposals.show',
                $proposal
            )
            ->with(
                'status',
                'extraction-refreshed'
            );
    }

    public function analyze(
        Request $request,
        ResearchProposal $proposal,
        ProposalAnalysisService $analyzer
    ): RedirectResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        $target = ($request->user()->role === User::ROLE_ADMIN ? 'admin' : 'student').'.proposals.show';
        try {
            $analyzer->analyze($proposal);
        } catch (ValidationException $exception) {
            return redirect()->route($target, $proposal)->withErrors($exception->errors());
        }

        return redirect()
            ->route(
                (
                    $request->user()->role
                    === User::ROLE_ADMIN
                        ? 'admin'
                        : 'student'
                ).'.proposals.show',
                $proposal
            )->with('status', 'proposal-analyzed');
    }

    public function download(
        Request $request,
        ResearchProposal $proposal
    ): StreamedResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        $disk = Storage::disk(
            'proposals'
        );

        abort_unless(
            $disk->exists(
                $proposal->file_path
            ),
            404,
            'The proposal document is unavailable.'
        );

        return $disk->download(
            $proposal->file_path,
            $proposal->original_filename,
            [
                'Content-Type' => 'application/octet-stream',

                'X-Content-Type-Options' => 'nosniff',

                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    /**
     * Permanently delete a student's proposal.
     *
     * Related analysis, recommendations and adviser requests
     * are removed automatically through the database's
     * cascadeOnDelete foreign keys.
     *
     * A proposal that already has an adviser assignment is
     * protected from deletion.
     */
    public function destroy(
        Request $request,
        ResearchProposal $proposal
    ): RedirectResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        /*
         * Only the student who owns the proposal may delete it.
         * Admins can review proposals but do not delete them
         * through this student action.
         */
        abort_unless(
            $request->user()->role
            === User::ROLE_STUDENT,
            403
        );

        /*
         * Preserve official assignment records.
         */
        if ($proposal->assignment()->exists()) {
            return redirect()
                ->route(
                    'student.proposals.show',
                    $proposal
                )
                ->withErrors([
                    'delete' => 'This proposal cannot be deleted because '
                        .'it already has an adviser assignment.',
                ]);
        }

        $filePath =
            $proposal->file_path;

        try {
            /*
             * Delete the database record first.
             *
             * Foreign keys in your current database migrations
             * automatically remove:
             *
             * - proposal analysis
             * - recommendations
             * - adviser requests
             */
            DB::transaction(
                function () use ($proposal) {
                    $lockedProposal =
                        ResearchProposal::whereKey(
                            $proposal->id
                        )
                            ->lockForUpdate()
                            ->firstOrFail();

                    if (
                        $lockedProposal
                            ->assignment()
                            ->exists()
                    ) {
                        throw ValidationException::withMessages([
                            'delete' => 'This proposal cannot be deleted because '
                                .'it already has an adviser assignment.',
                        ]);
                    }

                    $lockedProposal->delete();
                }
            );

            /*
             * After the database deletion succeeds,
             * remove the physical PDF/DOCX.
             */
            $disk = Storage::disk(
                'proposals'
            );

            if (
                $filePath
                && $disk->exists($filePath)
            ) {
                $disk->delete(
                    $filePath
                );
            }
        } catch (ValidationException $exception) {
            return redirect()
                ->route(
                    'student.proposals.show',
                    $proposal
                )
                ->withErrors(
                    $exception->errors()
                );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route(
                    'student.proposals.show',
                    $proposal
                )
                ->withErrors([
                    'delete' => 'The proposal could not be deleted. '
                        .'Please try again.',
                ]);
        }

        return redirect()
            ->route(
                'student.proposals.index'
            )
            ->with(
                'status',
                'proposal-deleted'
            );
    }

    private function authorizeAccess(
        Request $request,
        ResearchProposal $proposal
    ): void {
        abort_unless(
            $request->user()->role
            === User::ROLE_ADMIN
            ||
            $request->user()
                ->studentProfile?->id
            === $proposal->student_profile_id,
            404
        );
    }

    private function completeProfile(): RedirectResponse
    {
        return redirect()
            ->route(
                'student.profile.edit'
            )
            ->with(
                'status',
                'complete-profile-for-proposal'
            );
    }
}
