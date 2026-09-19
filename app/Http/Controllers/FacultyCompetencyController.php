<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultyCompetencyRequest;
use App\Models\FacultyCompetency;
use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\CompetencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FacultyCompetencyController extends Controller
{
    public function index(CompetencyService $service): View
    {
        $faculty = User::where('role', User::ROLE_FACULTY)->with('facultyProfile.competency')
            ->orderBy('name')->orderBy('id')->paginate(20);

        return view('admin.competencies.index', [
            'faculty' => $faculty,
            'scores' => $faculty->getCollection()->mapWithKeys(fn (User $user) => [
                $user->id => $service->score($user->facultyProfile?->competency),
            ]),
        ]);
    }

    public function edit(FacultyProfile $facultyProfile, CompetencyService $service): View
    {
        abort_unless($facultyProfile->user->role === User::ROLE_FACULTY, 403);
        $competency = $facultyProfile->competency;

        return view('admin.competencies.edit', [
            'profile' => $facultyProfile,
            'competency' => $competency,
            'score' => $service->score($competency),
            'dimensions' => FacultyCompetency::DIMENSIONS,
            'rubric' => FacultyCompetency::RUBRIC,
        ]);
    }

    public function update(FacultyCompetencyRequest $request, FacultyProfile $facultyProfile, CompetencyService $service): RedirectResponse
    {
        $service->save($facultyProfile, $request->user(), $request->validated());

        return redirect()->route('admin.competencies.edit', $facultyProfile)->with('status', 'competency-saved');
    }
}
