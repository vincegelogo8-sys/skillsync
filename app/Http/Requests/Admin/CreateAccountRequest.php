<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'ends_with:@gmail.com', 'max:255', Rule::unique('users', 'email')],
            'role' => ['bail', 'required', 'string', Rule::in([User::ROLE_STUDENT, User::ROLE_FACULTY])],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
