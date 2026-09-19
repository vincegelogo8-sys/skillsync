<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultyExpertiseRequest;
use App\Models\User;
use App\Services\FacultyExpertiseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FacultyExpertiseController extends Controller
{
    public function facultyList(): View
    {
        return view('admin.expertise.index', [
            'faculty' => User::where('role', User::ROLE_FACULTY)
                ->with('facultyProfile')->orderBy('name')->orderBy('id')->paginate(20),
        ]);
    }

    public function index(FacultyExpertiseRequest $request): View|RedirectResponse
    {
        if (! $request->profile()) {
            return $this->completeProfile();
        }

        return view('faculty.expertise.index', $this->viewData($request) + [
            'entries' => $request->profile()->expertise()->orderBy('expertise_area')->get(),
        ]);
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

        return $this->saved($request, 'Expertise added successfully.');
    }

    public function update(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        $service->save($request->profile(), $request->validated(), $request->route('expertise'));

        return $this->saved($request, 'Expertise updated successfully.');
    }

    public function destroy(FacultyExpertiseRequest $request, FacultyExpertiseService $service): RedirectResponse
    {
        $service->delete($request->profile(), $request->route('expertise'));

        return $this->saved($request, 'Expertise removed successfully.');
    }

    private function viewData(FacultyExpertiseRequest $request): array
    {
        $admin = $request->user()->role === User::ROLE_ADMIN;

        return [
            'profile' => $request->profile(),
            'areas' => config('expertise.areas'),
            'routePrefix' => $admin ? 'admin.expertise.' : 'faculty.expertise.',
            'routeParameters' => $admin ? ['facultyProfile' => $request->profile()] : [],
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
            ->with('status', 'complete-profile-for-expertise');
    }
}
