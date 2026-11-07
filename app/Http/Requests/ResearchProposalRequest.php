<?php

namespace App\Http\Requests;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Rules\ProposalDocument;
use App\Support\ProposalFingerprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $profile = $this->user()?->studentProfile;
            $file = $this->file('document');

            if (! $profile || ! ($file instanceof UploadedFile) || $validator->errors()->has('document')) {
                return;
            }

            $fileHash = ProposalFingerprint::fileHash($file);
            $title = $this->input('title');
            $titleKey = is_string($title) && ! $validator->errors()->has('title')
                ? ProposalFingerprint::titleKey($title)
                : null;

            $duplicateFile = $profile->researchProposals()
                ->where('file_hash', $fileHash)
                ->first();
            if ($duplicateFile) {
                $validator->errors()->add(
                    'document',
                    sprintf(
                        'You already uploaded this document as "%s" on %s. Delete that proposal first if you want to upload it again.',
                        $duplicateFile->title,
                        $duplicateFile->created_at->toDateString()
                    )
                );
            }

            $ownTitleDuplicate = $titleKey !== null && $profile->researchProposals()->where('title_key', $titleKey)->exists();
            if ($ownTitleDuplicate) {
                $validator->errors()->add('title', 'You already have a proposal with this title.');
            }

            // A proposal already submitted by a different student (another group) cannot be submitted again.
            // The message never says who submitted it.
            if (! $duplicateFile && ResearchProposal::submittedByAnotherStudent($profile, 'file_hash', $fileHash)) {
                $validator->errors()->add('document', ResearchProposal::OTHER_STUDENT_DOCUMENT_MESSAGE);
            }

            if ($titleKey !== null && ! $ownTitleDuplicate && ResearchProposal::submittedByAnotherStudent($profile, 'title_key', $titleKey)) {
                $validator->errors()->add('title', ResearchProposal::OTHER_STUDENT_TITLE_MESSAGE);
            }
        });
    }
}