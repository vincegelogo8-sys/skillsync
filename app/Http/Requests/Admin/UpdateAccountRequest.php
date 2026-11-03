<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $this->user()?->role === User::ROLE_ADMIN
            && $account instanceof User
            && in_array($account->role, [User::ROLE_STUDENT, User::ROLE_FACULTY], true);
    }

    public function rules(): array
    {
        /** @var User $account */
        $account = $this->route('account');

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'email' => [
                'bail', 'required', 'string', 'lowercase', 'email',
                'ends_with:@gmail.com', 'max:255',
                Rule::unique('users', 'email')->ignore($account),
            ],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
