<?php

namespace App\Http\Controllers;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdviserRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdviserRequestController extends Controller
{
    public function index(Request $request): View
    {
        $role = $request->user()->role;
        $query = AdviserRequest::with(['studentProfile.user', 'facultyProfile.user', 'researchProposal.assignment.facultyProfile.user']);
        if ($role === User::ROLE_STUDENT) {
            $query->where('student_profile_id', $request->user()->studentProfile?->id ?? 0);
            // Once an adviser has accepted the proposal, requests that were closed because
            // of it are not shown to the student. Only the accepted request remains.
            $query->where(fn ($q) => $q->where('status', '!=', 'cancelled')
                ->orWhereDoesntHave('researchProposal.assignment', fn ($a) => $a->where('status', AdviserAssignment::STATUS_ACTIVE)));
        } elseif ($role === User::ROLE_FACULTY) {
            $query->where('faculty_profile_id', $request->user()->facultyProfile?->id ?? 0);
        }

        return view('requests.index', ['requests' => $query->latest('id')->paginate(15), 'role' => $role]);
    }

    public function store(Request $request, ResearchProposal $proposal, FacultyProfile $facultyProfile, AdviserRequestService $service): RedirectResponse
    {
        $service->submit($proposal, $facultyProfile, $request->user());

        return redirect()->route('student.requests.index')->with('status', 'request-submitted');
    }

    public function cancel(Request $request, AdviserRequest $adviserRequest, AdviserRequestService $service): RedirectResponse
    {
        $service->close($adviserRequest, $request->user(), 'cancelled');

        return redirect()->route('student.requests.index')->with('status', 'request-cancelled');
    }

    public function destroy(Request $request, AdviserRequest $adviserRequest, AdviserRequestService $service): RedirectResponse
    {
        try {
            $service->deleteCancelled($adviserRequest, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('student.requests.index')
                ->withErrors($exception->errors());
        } catch (QueryException $exception) {
            report($exception);

            return redirect()
                ->route('student.requests.index')
                ->withErrors([
                    'adviser_request' => 'The cancelled adviser request could not be removed. Please try again.',
                ]);
        }

        return redirect()->route('student.requests.index')->with('status', 'request-deleted');
    }

    public function decline(Request $request, AdviserRequest $adviserRequest, AdviserRequestService $service): RedirectResponse
    {
        $service->close($adviserRequest, $request->user(), 'declined');

        return redirect()->route($request->user()->role.'.requests.index')->with('status', 'request-declined');
    }

    public function viewProposal(Request $request, AdviserRequest $adviserRequest): Response
    {
        $user = $request->user();

        abort_unless($user->role === User::ROLE_FACULTY, 403);
        abort_unless($user->facultyProfile?->id === $adviserRequest->faculty_profile_id, 403);
        abort_unless(in_array($adviserRequest->status, AdviserRequest::ACTIVE_STATUSES, true), 404);

        $proposal = $adviserRequest->researchProposal;
        abort_unless($proposal, 404);

        return response()->view('proposals.reader', [
            'proposal' => $proposal->loadMissing(['studentProfile.user', 'analysis']),
            'backUrl' => route('faculty.requests.index'),
            'watermarkName' => $user->name,
            'watermarkTime' => now()->format('Y-m-d H:i T'),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}