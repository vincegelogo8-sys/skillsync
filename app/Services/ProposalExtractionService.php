<?php

namespace App\Services;

use App\Exceptions\DocumentExtractionException;
use App\Models\ResearchProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProposalExtractionService
{
    public function __construct(
        private DocumentExtractionRunner $reader
    ) {}

    /**
     * Normal extraction.
     *
     * If the proposal already has an analysis, the existing
     * analysis is returned and no duplicate extraction occurs.
     */
    public function extract(
        ResearchProposal $proposal
    ): ResearchProposal {
        try {
            return DB::transaction(function () use ($proposal) {
                $proposal = ResearchProposal::whereKey($proposal->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($proposal->analysis()->exists()) {
                    return $proposal->load('analysis');
                }

                try {
                    $text = $this->reader->extract(
                        Storage::disk('proposals')->path(
                            $proposal->file_path
                        ),
                        $proposal->file_type
                    );

                    $analysis = $proposal->analysis()->make();

                    $analysis->extracted_text = $text;

                    $analysis->save();

                    $proposal->status = 'extracted';

                    $proposal->extraction_error = null;
                } catch (DocumentExtractionException $exception) {
                    $proposal->status = 'extraction_failed';

                    $proposal->extraction_error =
                        $exception->getMessage();
                }

                $proposal->save();

                return $proposal->load('analysis');
            });
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'extraction' => 'Document text could not be saved. '
                    .'Your uploaded file is preserved. '
                    .'Please retry from the proposal page.',
            ]);
        }
    }

    /**
     * Refresh an existing extraction.
     *
     * The original uploaded PDF/DOCX is NOT deleted.
     *
     * The document is extracted again using the latest
     * DocumentExtractionService rules.
     *
     * Successful text extraction persists even when local NLP fails.
     * Concepts and recommendations are replaced together only after validated analysis.
     */
    public function refresh(
        ResearchProposal $proposal
    ): ResearchProposal {
        if (! $proposal->analysis()->exists()) {
            $extracted = $this->extract($proposal);
            if ($extracted->analysis) {
                app(ProposalAnalysisService::class)->analyze($extracted);
            }

            return $proposal->fresh()->load('analysis');
        }
        /*
         * First try extracting the document WITHOUT deleting
         * the previous analysis.
         *
         * This means that if extraction fails, the previous
         * successful analysis remains available.
         */
        try {
            $disk = Storage::disk('proposals');

            if (! $disk->exists($proposal->file_path)) {
                throw new DocumentExtractionException(
                    'The uploaded proposal document could not be found.'
                );
            }

            $text = $this->reader->extract(
                $disk->path($proposal->file_path),
                $proposal->file_type
            );
        } catch (DocumentExtractionException $exception) {
            $proposal->status = 'extraction_failed';

            $proposal->extraction_error =
                $exception->getMessage();

            $proposal->save();

            return $proposal->fresh()->load('analysis');
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'extraction' => 'The proposal could not be re-extracted. '
                    .'Your uploaded document and previous analysis were preserved.',
            ]);
        }

        // Persist successful extraction independently; only validated local concepts update RTA text.
        app(ProposalAnalysisService::class)->analyze($proposal, $text);

        return $proposal->fresh()->load('analysis');
    }
}
