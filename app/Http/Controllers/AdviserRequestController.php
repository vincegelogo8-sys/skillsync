<?php

namespace App\Http\Controllers;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdviserRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

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

        if ($user->role === User::ROLE_FACULTY) {
            abort_unless(
                $user->facultyProfile?->id === $adviserRequest->faculty_profile_id,
                403
            );
        } elseif ($user->role === User::ROLE_STUDENT) {
            abort_unless(
                $user->studentProfile?->id === $adviserRequest->student_profile_id,
                404
            );
        } elseif ($user->role !== User::ROLE_ADMIN) {
            abort(403);
        }

        $proposal = $adviserRequest->researchProposal;
        abort_unless($proposal, 404);

        $disk = Storage::disk('proposals');
        abort_unless(
            $proposal->file_path && $disk->exists($proposal->file_path),
            404,
            'The proposal document is unavailable.'
        );

        $isPdf = strtolower($proposal->file_type) === 'pdf';
        $headers = [
            'Content-Type' => $isPdf ? 'application/pdf' : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        return $isPdf
            ? $disk->response($proposal->file_path, $proposal->original_filename, $headers, 'inline')
            : $disk->download($proposal->file_path, $proposal->original_filename, $headers);
    }
}
