<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Models\StudentProfile;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        $role = $this->input('role');

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'ends_with:@gmail.com', 'max:255', Rule::unique('users', 'email')],
            'role' => ['bail', 'required', 'string', Rule::in([User::ROLE_STUDENT, User::ROLE_FACULTY])],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::defaults()],
            'student_number' => ['exclude_unless:role,'.User::ROLE_STUDENT, 'required', 'string', 'max:50', Rule::unique('student_profiles', 'student_number')],
            'course' => ['exclude_unless:role,'.User::ROLE_STUDENT, 'required', 'string', Rule::in(config('student_options.courses'))],
            'year_level' => ['exclude_unless:role,'.User::ROLE_STUDENT, 'required', 'integer', Rule::in(array_keys(config('student_options.year_levels')))],
            'section' => ['exclude_unless:role,'.User::ROLE_STUDENT, 'required', 'string', Rule::in(config('student_options.sections'))],
            'department' => ['exclude_unless:role,'.User::ROLE_FACULTY, 'required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        $messages = [];
        $email = $this->input('email');
        if (is_string($email) && User::where('email', $email)->deactivated()->exists()) {
            $messages['email.unique'] = 'This belongs to a deactivated account. Activate it instead.';
        }

        $studentNumber = $this->input('student_number');
        if (is_string($studentNumber) && StudentProfile::where('student_number', $studentNumber)
            ->whereHas('user', fn ($query) => $query->deactivated())->exists()) {
            $messages['student_number.unique'] = 'This belongs to a deactivated account. Activate it instead.';
        }

        return $messages;
    }
}
