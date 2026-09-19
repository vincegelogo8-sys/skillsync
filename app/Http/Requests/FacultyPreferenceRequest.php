<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacultyPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_FACULTY;
    }

    protected function prepareForValidation(): void
    {
        // Browsers omit a checkbox group when every option is unchecked.
        $this->merge([
            'project_types' => $this->input('project_types', []),
            'technologies' => $this->input('technologies', []),
        ]);
    }

    public function rules(): array
    {
        if (! $this->user()?->facultyProfile) {
            return []; // Redirect to complete the profile before saving preferences.
        }

        return [
            'project_types' => ['present', 'array', 'list', 'max:'.count(config('preferences.project_types'))],
            'project_types.*' => ['required', 'string', 'distinct:strict', Rule::in(config('preferences.project_types'))],
            'technologies' => ['present', 'array', 'list', 'max:'.count(config('preferences.technologies'))],
            'technologies.*' => ['required', 'string', 'distinct:strict', Rule::in(config('preferences.technologies'))],
        ];
    }

    public function messages(): array
    {
        return [
            'project_types.*.in' => 'Select project types from the available list.',
            'technologies.*.in' => 'Select technologies from the available list.',
            'project_types.*.distinct' => 'Select each project type only once.',
            'technologies.*.distinct' => 'Select each technology only once.',
        ];
    }
}
