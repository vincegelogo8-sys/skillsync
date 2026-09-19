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
            'course' => ['required', 'string', 'max:150'],
            'year_level' => ['required', 'integer', 'between:1,6'],
            'section' => ['required', 'string', 'max:50'],
        ];
    }
}
