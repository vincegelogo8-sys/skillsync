<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentProfileRequest;
use App\Services\StudentProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('student.profile.edit', [
            'user' => $request->user(),
            'profile' => $request->user()->studentProfile,
        ]);
    }

    public function update(StudentProfileRequest $request, StudentProfileService $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->validated());

        return redirect()->route('student.profile.edit')->with('status', 'student-profile-updated');
    }
}
