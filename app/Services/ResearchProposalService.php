<?php

namespace App\Services;

use App\Models\ResearchProposal;
use App\Models\StudentProfile;
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
        try {
            $extension = strtolower($file->getClientOriginalExtension());
            $path = Str::uuid().'.'.$extension;
            if (! $file->storeAs('', $path, 'proposals')) {
                throw new RuntimeException('Proposal storage did not return a path.');
            }

            return DB::transaction(function () use ($profile, $title, $file, $extension, $path) {
                $proposal = $profile->researchProposals()->make();
                $proposal->title = $title;
                $proposal->file_path = $path;
                $proposal->original_filename = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
                $proposal->file_type = $extension;
                $proposal->status = 'uploaded';
                $proposal->save();

                return $proposal;
            });
        } catch (Throwable $exception) {
            if ($path) {
                try {
                    Storage::disk('proposals')->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            report($exception);
            throw ValidationException::withMessages(['document' => 'The proposal could not be saved. Please choose your document again and retry.']);
        }
    }
}
