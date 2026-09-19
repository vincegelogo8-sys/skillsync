<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultyPreferenceRequest;
use App\Models\FacultyPreference;
use App\Services\FacultyPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacultyPreferenceController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $profile = $request->user()->facultyProfile;

        if (! $profile) {
            return $this->completeProfile();
        }

        $preferences = $profile->preferences()->get();

        return view('faculty.preferences.index', [
            'projectTypes' => config('preferences.project_types'),
            'technologies' => config('preferences.technologies'),
            'selectedProjects' => $preferences->where('preference_type', FacultyPreference::TYPE_PROJECT)->pluck('preference_value')->all(),
            'selectedTechnologies' => $preferences->where('preference_type', FacultyPreference::TYPE_TECHNOLOGY)->pluck('preference_value')->all(),
        ]);
    }

    public function update(FacultyPreferenceRequest $request, FacultyPreferenceService $service): RedirectResponse
    {
        if (! $request->user()->facultyProfile) {
            return $this->completeProfile();
        }

        $service->save($request->user()->facultyProfile, $request->validated());

        return redirect()->route('faculty.preferences.index')->with('status', 'faculty-preferences-updated');
    }

    private function completeProfile(): RedirectResponse
    {
        return redirect()->route('faculty.profile.edit')->with('status', 'complete-profile-for-preferences');
    }
}
