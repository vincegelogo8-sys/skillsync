<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required_without:select_all_matching', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
            'select_all_matching' => ['nullable', Rule::in(['1', 1, true])],
            'type' => ['required_if:select_all_matching,1', Rule::in([User::ROLE_STUDENT])],
            'course' => ['nullable', Rule::in(config('student_options.courses'))],
            'section' => ['nullable', Rule::in(config('student_options.sections'))],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'active', 'deactivated'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasIds = is_array($this->input('ids')) && $this->input('ids') !== [];
            $selectAll = in_array($this->input('select_all_matching'), ['1', 1, true], true);

            if ($hasIds === $selectAll) {
                $validator->errors()->add('ids', 'Choose selected accounts or all matching students.');
            }
            if ($selectAll && $this->input('type') !== User::ROLE_STUDENT) {
                $validator->errors()->add('type', 'Select all matching is available for students only.');
            }
            if ($selectAll && empty($this->input('course')) && ! empty($this->input('section'))) {
                $validator->errors()->add('section', 'A section filter requires a course.');
            }
        });
    }
}
