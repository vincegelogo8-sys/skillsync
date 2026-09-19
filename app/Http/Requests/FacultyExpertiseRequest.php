<?php

namespace App\Http\Requests;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacultyExpertiseRequest extends FormRequest
{
    // Also used for read/delete routes so every action checks the same owner.
    public function profile(): ?FacultyProfile
    {
        return $this->user()?->role === User::ROLE_ADMIN
            ? $this->route('facultyProfile')
            : $this->user()?->facultyProfile;
    }

    public function authorize(): bool
    {
        if (! in_array($this->user()?->role, [User::ROLE_FACULTY, User::ROLE_ADMIN], true)) {
            return false;
        }

        $profile = $this->profile();
        $expertise = $this->route('expertise');

        if ($profile && $profile->user->role !== User::ROLE_FACULTY) {
            return false;
        }

        if ($expertise instanceof FacultyExpertise) {
            abort_unless($profile && $expertise->faculty_profile_id === $profile->id, 404);
        }

        return true;
    }

    public function rules(): array
    {
        if (! $this->isMethod('post') && ! $this->isMethod('patch')) {
            return [];
        }

        if (! $this->profile()) {
            return []; // Controller redirects to complete the basic profile first.
        }

        $unique = Rule::unique('faculty_expertise', 'expertise_area')
            ->where('faculty_profile_id', $this->profile()->id);

        if ($this->route('expertise') instanceof FacultyExpertise) {
            $unique->ignore($this->route('expertise'));
        }

        return [
            'expertise_area' => ['required', 'string', Rule::in(config('expertise.areas')), $unique],
            'proficiency_score' => ['required', 'integer', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return ['expertise_area.unique' => 'This expertise area is already listed. Edit its existing score instead.'];
    }
}
