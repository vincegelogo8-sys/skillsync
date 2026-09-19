<?php

namespace App\Http\Controllers;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\User;
use App\Services\AdviserAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AdviserAssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $role = $request->user()->role;
        $query = AdviserAssignment::with(['researchProposal.studentProfile.user', 'facultyProfile.user', 'approver']);
        if ($role === User::ROLE_STUDENT) {
            $query->whereHas('researchProposal', fn ($query) => $query->where('student_profile_id', $request->user()->studentProfile?->id ?? 0));
        } elseif ($role === User::ROLE_FACULTY) {
            $query->where('faculty_profile_id', $request->user()->facultyProfile?->id ?? 0);
        }

        return view('assignments.index', ['assignments' => $query->latest('assigned_at')->latest('id')->paginate(15), 'role' => $role]);
    }

    public function approve(Request $request, AdviserRequest $adviserRequest, AdviserAssignmentService $service): RedirectResponse
    {
        try {
            $service->approve($adviserRequest, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('admin.requests.index')->withErrors($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('admin.requests.index')->withErrors(['assignment' => 'The assignment could not be saved. No partial approval was kept. Please try again.']);
        }

        return redirect()->route('admin.assignments.index')->with('status', 'adviser-assigned');
    }
}
