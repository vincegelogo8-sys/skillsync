<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class FacultyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_FACULTY;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:150'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'full name'];
    }
}
