<?php

namespace App\Services;

use App\Models\ResearchProposal;
use App\Models\StudentProfile;
use App\Support\ProposalFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ResearchProposalService
{
    public function upload(StudentProfile $profile, string $title, UploadedFile $file): ResearchProposal
    {
        $path = null;
        $fileHash = ProposalFingerprint::fileHash($file);
        $titleKey = ProposalFingerprint::titleKey($title);

        try {
            $extension = strtolower($file->getClientOriginalExtension());
            $path = Str::uuid().'.'.$extension;
            if (! $file->storeAs('', $path, 'proposals')) {
                throw new RuntimeException('Proposal storage did not return a path.');
            }

            return DB::transaction(function () use ($profile, $title, $file, $fileHash, $titleKey, $extension, $path) {
                $proposal = $profile->researchProposals()->make();
                $proposal->title = $title;
                $proposal->file_path = $path;
                $proposal->file_hash = $fileHash;
                $proposal->title_key = $titleKey;
                $proposal->original_filename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
                $proposal->file_type = $extension;
                $proposal->status = 'uploaded';
                $proposal->save();

                return $proposal;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->deleteStoredFile($path);
            $messages = $this->duplicateMessages($profile, $fileHash, $titleKey);

            if ($messages !== []) {
                throw ValidationException::withMessages($messages);
            }

            report($exception);
            throw ValidationException::withMessages(['document' => 'The proposal could not be saved. Please choose your document again and retry.']);
        } catch (Throwable $exception) {
            $this->deleteStoredFile($path);
            report($exception);
            throw ValidationException::withMessages(['document' => 'The proposal could not be saved. Please choose your document again and retry.']);
        }
    }

    private function duplicateMessages(StudentProfile $profile, string $fileHash, string $titleKey): array
    {
        $messages = [];
        $duplicateFile = $profile->researchProposals()->where('file_hash', $fileHash)->first();

        if ($duplicateFile) {
            $messages['document'] = sprintf(
                'You already uploaded this document as "%s" on %s. Delete that proposal first if you want to upload it again.',
                $duplicateFile->title,
                $duplicateFile->created_at->toDateString()
            );
        }

        if ($profile->researchProposals()->where('title_key', $titleKey)->exists()) {
            $messages['title'] = 'You already have a proposal with this title.';
        }

        return $messages;
    }

    private function deleteStoredFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('proposals')->delete($path);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
