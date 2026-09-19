<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ProposalDocument;
use Illuminate\Foundation\Http\FormRequest;

class ResearchProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_STUDENT;
    }

    public function rules(): array
    {
        if (! $this->user()?->studentProfile) {
            return []; // Controller redirects to complete the academic profile first.
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'document' => ['bail', 'required', 'file', 'max:10240', new ProposalDocument],
        ];
    }

    public function messages(): array
    {
        return [
            'document.max' => 'The proposal document must be 10 MB or smaller.',
            'document.uploaded' => 'The document could not be uploaded. Choose a PDF or DOCX file of 10 MB or smaller and try again.',
        ];
    }
}
