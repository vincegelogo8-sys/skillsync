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
                'extraction' =>
                    'Document text could not be saved. '
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
     * The previous analysis is replaced only when the
     * new extraction succeeds.
     *
     * Existing generated recommendation scores are removed
     * because they were based on the previous extraction.
     */
    public function refresh(
        ResearchProposal $proposal
    ): ResearchProposal {
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
                'extraction' =>
                    'The proposal could not be re-extracted. '
                    .'Your uploaded document and previous analysis were preserved.',
            ]);
        }

        /*
         * The new extraction succeeded.
         *
         * We can now safely replace the previous analysis.
         */
        try {
            return DB::transaction(function () use ($proposal, $text) {
                $proposal = ResearchProposal::whereKey($proposal->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Old recommendations are no longer valid because
                 * they were calculated using the previous text.
                 */
                $proposal->recommendations()->delete();

                /*
                 * Delete the old analysis only after the new
                 * extraction has succeeded.
                 */
                $proposal->analysis()->delete();

                $analysis = $proposal->analysis()->make();

                $analysis->extracted_text = $text;

                /*
                 * All analysis fields are intentionally left blank.
                 * ProposalAnalysisService will recalculate them.
                 */
                $analysis->analyzed_at = null;

                $analysis->save();

                $proposal->status = 'extracted';

                $proposal->extraction_error = null;

                $proposal->save();

                return $proposal->fresh()->load('analysis');
            });
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'extraction' =>
                    'The refreshed extraction could not be saved. '
                    .'Please try again.',
            ]);
        }
    }
}