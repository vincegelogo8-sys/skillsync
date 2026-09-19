<?php

namespace App\Http\Requests;

use App\Models\FacultyCompetency;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class FacultyCompetencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN
            && $this->route('facultyProfile')?->user->role === User::ROLE_FACULTY;
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys(FacultyCompetency::DIMENSIONS), ['required', 'integer', 'between:1,5'])
            + ['remarks' => ['nullable', 'string', 'max:5000']];
    }

    public function attributes(): array
    {
        return FacultyCompetency::DIMENSIONS;
    }
}
