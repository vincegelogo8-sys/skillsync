<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResearchProposalRequest;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\ProposalExtractionService;
use App\Services\RecommendationService;
use App\Services\ResearchProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResearchProposalController extends Controller
{
    public function index(
        Request $request
    ): View|RedirectResponse {
        $admin =
            $request->user()->role === User::ROLE_ADMIN;

        if (
            ! $admin
            && ! $request->user()->studentProfile
        ) {
            return $this->completeProfile();
        }

        $query = $admin
            ? ResearchProposal::with('studentProfile.user')
            : $request->user()
                ->studentProfile
                ->researchProposals();

        return view('student.proposal.index', [
            'proposals' => $query->latest('id')->paginate(15),

            'admin' => $admin,
        ]);
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

        if ($extracted->analysis) {
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
        ProposalExtractionService $extractor,
        ProposalAnalysisService $analyzer,
        RecommendationService $recommendations
    ): RedirectResponse {
        $this->authorizeAccess(
            $request,
            $proposal
        );

        try {
            DB::transaction(function () use ($request, $proposal, $extractor, $analyzer, $recommendations) {
                $proposal = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
                $hasRecommendations = $proposal->recommendations()->exists();
                $refreshed = $extractor->refresh($proposal);

                if ($refreshed->status === 'extracted' && $refreshed->analysis) {
                    $analyzer->analyze($refreshed);
                    if ($hasRecommendations) {
                        try {
                            $recommendations->generate($refreshed, $request->user());
                        } catch (ValidationException $exception) {
                            throw $exception;
                        } catch (Throwable $exception) {
                            report($exception);
                            throw ValidationException::withMessages([
                                'analysis' => 'Recommendations could not be recalculated. The previous extraction and results were preserved. Please retry.',
                            ]);
                        }
                    }
                }
            });
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

        $analyzer->analyze(
            $proposal
        );

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
