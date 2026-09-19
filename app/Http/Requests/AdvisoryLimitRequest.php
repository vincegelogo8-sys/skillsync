<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\AdvisoryThresholdService;
use Illuminate\Foundation\Http\FormRequest;

class AdvisoryLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN;
    }

    public function rules(): array
    {
        return ['advisory_limit' => ['required', 'integer', 'between:0,'.AdvisoryThresholdService::MAX_LIMIT]];
    }
}
