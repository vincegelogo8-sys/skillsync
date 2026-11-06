<?php

namespace App\Http\Controllers;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdvisoryThresholdService;
use App\Services\ExpertiseMatchingService;
use App\Services\FacultyNameSorter;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RecommendationController extends Controller
{
    public function index(
        Request $request,
        ResearchProposal $proposal,
        AdvisoryThresholdService $threshold,
        ExpertiseMatchingService $expertiseMatching,
        FacultyNameSorter $nameSorter
    ): View {
        $this->authorizeAccess($request, $proposal);
        $proposal->load('analysis', 'studentProfile.user');
        $hasRelevantFaculty = false;
        if ($proposal->analysis?->hasCurrentAnalysis()) {
            $proposal->analysis->setRelation('researchProposal', $proposal);
            $hasRelevantFaculty = FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY)->active())
                ->with('expertise')->get()
                ->contains(fn (FacultyProfile $faculty) => $expertiseMatching->matches($proposal->analysis, $faculty) !== []);
        }
        $eligible = $proposal->analysis?->hasCurrentAnalysis()
            ? $proposal->recommendations()->with('facultyProfile.user', 'facultyProfile.expertise')->get()
                ->filter(function ($recommendation) use ($proposal, $expertiseMatching) {
                    if (! $recommendation->facultyProfile?->user?->isActive()) {
                        return false;
                    }

                    $matches = $expertiseMatching->matches($proposal->analysis, $recommendation->facultyProfile);
                    if ($matches === []) {
                        return false;
                    }
                    $recommendation->details = [
                        ...($recommendation->details ?? []),
                        'matching_expertise' => $matches,
                    ];

                    return true;
                })
            : collect();
        $eligible = $nameSorter->sort($eligible);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $recommendations = new LengthAwarePaginator(
            $eligible->forPage($page, 10)->values(),
            $eligible->count(),
            10,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('student.recommendations.index', [
            'proposal' => $proposal,
            'recommendations' => $recommendations,
            'activeRequests' => AdviserRequest::where('research_proposal_id', $proposal->id)->whereIn('status', AdviserRequest::ACTIVE_STATUSES)->pluck('faculty_profile_id')->all(),
            'hasAssignment' => $proposal->assignment()->where('status', 'active')->exists(),
            'capacities' => $threshold->forFaculties($recommendations->getCollection()->pluck('facultyProfile')),
            'hasFaculty' => FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY)->active())->exists(),
            'hasRelevantFaculty' => $hasRelevantFaculty,
        ]);
    }

    public function generate(Request $request, ResearchProposal $proposal, RecommendationService $service): RedirectResponse
    {
        $this->authorizeAccess($request, $proposal);
        $redirect = redirect()->route('student.recommendations.index', $proposal);
        try {
            $rows = $service->generate($proposal, $request->user());
        } catch (ValidationException $exception) {
            return $redirect->withErrors($exception->errors());
        } catch (Throwable $exception) {
            report($exception);

            return $redirect->withErrors(['recommendations' => 'Recommendations could not be refreshed. Previously saved results are preserved. Please try again.']);
        }

        return $redirect->with('status', $rows->isEmpty() ? 'recommendations-empty' : 'recommendations-generated');
    }

    private function authorizeAccess(Request $request, ResearchProposal $proposal): void
    {
        abort_unless($request->user()->role === User::ROLE_STUDENT && $request->user()->studentProfile?->id === $proposal->student_profile_id, 404);
    }
}
