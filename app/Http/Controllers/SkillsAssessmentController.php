<?php

namespace App\Http\Controllers;

use App\Models\SkillsAssessmentAttempt;
use App\Models\SkillsAssessmentQuestion;
use App\Services\SkillsAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SkillsAssessmentController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $profile = $request->user()->facultyProfile;
        if (! $profile) {
            return $this->completeProfile();
        }

        return view('faculty.assessment.index', [
            'attempts' => $profile->assessmentAttempts()->latest('id')->paginate(10),
            'active' => $profile->assessmentAttempts()->whereNull('completed_at')->first(),
            'ready' => SkillsAssessmentQuestion::count() >= SkillsAssessmentService::TOTAL_ITEMS,
        ]);
    }

    public function start(Request $request, SkillsAssessmentService $service): RedirectResponse
    {
        if (! $request->user()->facultyProfile) {
            return $this->completeProfile();
        }
        $attempt = $service->start($request->user()->facultyProfile);

        return redirect()->route('faculty.assessment.show', $attempt);
    }

    public function show(Request $request, SkillsAssessmentAttempt $attempt): View
    {
        $this->authorizeOwner($request, $attempt);

        return view('faculty.assessment.show', [
            'attempt' => $attempt,
            // Only public question fields reach the Faculty view.
            'items' => $attempt->completed_at ? collect() : $attempt->answers()->orderBy('position')
                ->get(['id', 'position', 'question', 'option_a', 'option_b', 'option_c', 'option_d']),
        ]);
    }

    public function submit(Request $request, SkillsAssessmentAttempt $attempt, SkillsAssessmentService $service): RedirectResponse
    {
        $this->authorizeOwner($request, $attempt);
        $service->submit($request->user()->facultyProfile, $attempt, $request->input('answers'));

        return redirect()->route('faculty.assessment.show', $attempt)->with('status', 'assessment-completed');
    }

    private function authorizeOwner(Request $request, SkillsAssessmentAttempt $attempt): void
    {
        abort_unless($request->user()->facultyProfile?->id === $attempt->faculty_profile_id, 404);
    }

    private function completeProfile(): RedirectResponse
    {
        return redirect()->route('faculty.profile.edit')->with('status', 'complete-profile-for-assessment');
    }
}
