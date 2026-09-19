<?php

namespace App\Http\Controllers;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdviserRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdviserRequestController extends Controller
{
    public function index(Request $request): View
    {
        $role = $request->user()->role;
        $query = AdviserRequest::with(['studentProfile.user', 'facultyProfile.user', 'researchProposal']);
        if ($role === User::ROLE_STUDENT) {
            $query->where('student_profile_id', $request->user()->studentProfile?->id ?? 0);
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

    public function decline(Request $request, AdviserRequest $adviserRequest, AdviserRequestService $service): RedirectResponse
    {
        $service->close($adviserRequest, $request->user(), 'declined');

        return redirect()->route($request->user()->role.'.requests.index')->with('status', 'request-declined');
    }
}
