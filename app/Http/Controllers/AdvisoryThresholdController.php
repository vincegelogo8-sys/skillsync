<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdvisoryLimitRequest;
use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\AdvisoryThresholdService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdvisoryThresholdController extends Controller
{
    public function index(AdvisoryThresholdService $service): View
    {
        $faculties = FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY))->with('user')->orderBy('id')->paginate(15);

        return view('admin.advisory-limits.index', ['faculties' => $faculties, 'capacities' => $service->forFaculties($faculties)]);
    }

    public function edit(FacultyProfile $facultyProfile, AdvisoryThresholdService $service): View
    {
        abort_unless($facultyProfile->user->role === User::ROLE_FACULTY, 404);

        return view('admin.advisory-limits.edit', ['faculty' => $facultyProfile, 'capacity' => $service->forFaculties([$facultyProfile])[$facultyProfile->id]]);
    }

    public function update(AdvisoryLimitRequest $request, FacultyProfile $facultyProfile, AdvisoryThresholdService $service): RedirectResponse
    {
        $service->updateLimit($facultyProfile, $request->user(), (int) $request->validated('advisory_limit'));

        return redirect()->route('admin.advisory-limits.edit', $facultyProfile)->with('status', 'advisory-limit-updated');
    }
}
