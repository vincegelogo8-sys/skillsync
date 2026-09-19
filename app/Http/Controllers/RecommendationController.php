<?php

namespace App\Http\Controllers;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdvisoryThresholdService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RecommendationController extends Controller
{
    public function index(Request $request, ResearchProposal $proposal, AdvisoryThresholdService $threshold): View
    {
        $this->authorizeAccess($request, $proposal);
        $proposal->load('analysis', 'studentProfile.user');
        $recommendations = $proposal->recommendations()->with('facultyProfile.user')->orderBy('rank')->paginate(10);

        return view('student.recommendations.index', [
            'proposal' => $proposal,
            'admin' => $request->user()->role === User::ROLE_ADMIN,
            'recommendations' => $recommendations,
            'activeRequests' => AdviserRequest::where('research_proposal_id', $proposal->id)->whereIn('status', AdviserRequest::ACTIVE_STATUSES)->pluck('faculty_profile_id')->all(),
            'hasAssignment' => $proposal->assignment()->where('status', 'active')->exists(),
            'capacities' => $threshold->forFaculties($recommendations->getCollection()->pluck('facultyProfile')),
            'hasFaculty' => FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY))->exists(),
        ]);
    }

    public function generate(Request $request, ResearchProposal $proposal, RecommendationService $service): RedirectResponse
    {
        $this->authorizeAccess($request, $proposal);
        $redirect = redirect()->route(($request->user()->role === User::ROLE_ADMIN ? 'admin' : 'student').'.recommendations.index', $proposal);
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
        abort_unless($request->user()->role === User::ROLE_ADMIN || ($request->user()->role === User::ROLE_STUDENT && $request->user()->studentProfile?->id === $proposal->student_profile_id), 404);
    }
}
