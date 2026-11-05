<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_STUDENT;
    }

    public function rules(): array
    {
        return [
            'student_number' => [
                'required', 'string', 'max:50',
                Rule::unique('student_profiles', 'student_number')
                    ->ignore($this->user()->studentProfile?->id),
            ],
            'course' => ['required', 'string', Rule::in(config('student_options.courses'))],
            'year_level' => ['required', 'integer', Rule::in(array_keys(config('student_options.year_levels')))],
            'section' => ['required', 'string', Rule::in(config('student_options.sections'))],
        ];
    }
}
