<?php

namespace App\Http\Controllers;

use App\Http\Requests\FacultyProfileRequest;
use App\Services\FacultyProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacultyProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('faculty.profile.edit', [
            'user' => $request->user(),
            'profile' => $request->user()->facultyProfile,
        ]);
    }

    public function update(FacultyProfileRequest $request, FacultyProfileService $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->validated());

        return redirect()->route('faculty.profile.edit')->with('status', 'faculty-profile-updated');
    }
}
