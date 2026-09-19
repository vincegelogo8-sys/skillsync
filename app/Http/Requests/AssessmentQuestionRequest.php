<?php

namespace App\Http\Requests;

use App\Models\SkillsAssessmentQuestion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssessmentQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:5000'],
            'option_a' => ['required', 'string', 'max:500'],
            'option_b' => ['required', 'string', 'max:500', 'different:option_a'],
            'option_c' => ['required', 'string', 'max:500', 'different:option_a,option_b'],
            'option_d' => ['required', 'string', 'max:500', 'different:option_a,option_b,option_c'],
            'correct_answer' => ['required', Rule::in(SkillsAssessmentQuestion::OPTIONS)],
            'category' => ['required', 'string', Rule::in(config('expertise.areas'))],
        ];
    }
}
