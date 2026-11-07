<?php

namespace App\Http\Requests;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacultyExpertiseRequest extends FormRequest
{
    public function profile(): ?FacultyProfile
    {
        return $this->user()?->facultyProfile;
    }

    public function authorize(): bool
    {
        if ($this->user()?->role !== User::ROLE_FACULTY) {
            return false;
        }

        if ($this->routeIs('faculty.expertise.index')) {
            return true;
        }

        return $this->routeIs('faculty.expertise.skills.update') && $this->profile() !== null;
    }

    public function rules(): array
    {
        if ($this->routeIs('faculty.expertise.skills.update')) {
            return [
                'skills' => ['required', 'array', 'min:1', 'max:'.count(config('expertise.areas'))],
                'skills.*' => ['required', 'string', 'distinct', Rule::in(config('expertise.areas'))],
                // "Others" must say what the skill is, so it can be matched against proposals.
                'other_specify' => [
                    Rule::requiredIf(fn () => in_array(config('expertise.other'), (array) $this->input('skills', []), true)),
                    'nullable', 'string', 'min:2', 'max:255',
                ],
            ];
        }

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
            'expertise_area' => ['required', 'string', Rule::in(array_values(array_diff(config('expertise.areas'), [config('expertise.other')]))), $unique],
        ];
    }

    public function messages(): array
    {
        return [
            'expertise_area.required' => 'Select a faculty skill category.',
            'expertise_area.in' => 'Select one of the supported faculty skill categories.',
            'expertise_area.unique' => 'This faculty skill is already listed.',
            'other_specify.required' => 'Please specify your skill when "Others" is selected.',
            'other_specify.min' => 'Please describe your skill in at least 2 characters.',
            'other_specify.max' => 'Please keep the specified skill under 255 characters.',
            'skills.min' => 'Select at least one supported faculty skill category.',
            'skills.*.in' => 'Select only one of the supported faculty skill categories.',
        ];
    }
}