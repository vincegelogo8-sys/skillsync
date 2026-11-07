<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultyExpertiseRequest;
use App\Models\User;
use App\Services\FacultyExpertiseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FacultyExpertiseController extends Controller
{
    public function index(FacultyExpertiseRequest $request): View|RedirectResponse
    {
        if (! $request->profile()) {
            return $this->completeProfile();
        }

        $entries = $request->profile()->expertise()
            ->whereIn('expertise_area', config('expertise.areas'))
            ->orderBy('expertise_area')->get();

        return view('faculty.expertise.index', $this->viewData($request) + [
            'entries' => $entries,
            'otherSpecify' => (string) $entries->firstWhere('expertise_area', config('expertise.other'))?->technical_skills,
        ]);
    }

    public function updateSkills(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        $service->saveCategories(
            $request->profile(),
            $request->validated('skills', []),
            $request->validated('other_specify'),
        );

        return redirect()->route('faculty.expertise.index')->with('status', 'faculty-skills-updated');
    }

    public function edit(FacultyExpertiseRequest $request): View
    {
        return view('faculty.expertise.edit', $this->viewData($request) + [
            'expertise' => $request->route('expertise'),
        ]);
    }

    public function store(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        if (! $request->profile()) {
            return $this->completeProfile();
        }

        $service->save($request->profile(), $request->validated());

        return $this->saved($request, 'Faculty skill added successfully.');
    }

    public function update(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        $service->save($request->profile(), $request->validated(), $request->route('expertise'));

        return $this->saved($request, 'Faculty skill updated successfully.');
    }

    public function destroy(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        $service->delete($request->profile(), $request->route('expertise'));

        return $this->saved($request, 'Faculty skill removed successfully.');
    }

    private function viewData(FacultyExpertiseRequest $request): array
    {
        $isFaculty = $request->user()?->role === User::ROLE_FACULTY;

        return [
            'profile' => $request->profile(),
            'canUpdateSkills' => $isFaculty,
            'isFaculty' => $isFaculty,
            'areas' => array_values(array_diff(config('expertise.areas'), [config('expertise.other')])),
            'otherArea' => config('expertise.other'),
            'routePrefix' => 'faculty.expertise.',
            'routeParameters' => [],
        ];
    }

    private function saved(FacultyExpertiseRequest $request, string $message): RedirectResponse
    {
        $data = $this->viewData($request);

        return redirect()->route($data['routePrefix'].'index', $data['routeParameters'])->with('status', $message);
    }

    private function completeProfile(): RedirectResponse
    {
        return redirect()->route('faculty.profile.edit')
            ->with('status', 'complete-profile-for-skills');
    }
}