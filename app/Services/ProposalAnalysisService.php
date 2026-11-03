<?php

namespace App\Services;

use App\Models\ProposalAnalysis;
use App\Models\ResearchProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProposalAnalysisService
{
    public function __construct(
        private ProposalTextNormalizer $textNormalizer,
        private LocalNlpService $nlp,
        private ProposalRtaTextBuilder $rtaText,
        private ExpertiseMatchingService $expertise
    ) {}

    /** Extraction persists independently; only validated concepts can unlock matching. */
    public function analyze(ResearchProposal $proposal, ?string $replacementText = null): ProposalAnalysis
    {
        $attemptId = (string) Str::uuid();
        try {
            [$title, $snapshot] = DB::transaction(function () use ($proposal, $replacementText, $attemptId) {
                $locked = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
                $analysis = $locked->analysis()->first();
                if (! $analysis && $replacementText === null) {
                    throw ValidationException::withMessages(['analysis' => 'Extract readable document text before analyzing this proposal.']);
                }
                $analysis ??= $locked->analysis()->make();
                $analysis->forceFill([
                    'extracted_text' => $replacementText ?? $analysis->extracted_text,
                    'analysis_status' => 'pending',
                    'analysis_attempt_id' => $attemptId,
                ])->save();
                $locked->forceFill(['status' => 'extracted', 'extraction_error' => null])->save();

                return [$locked->title, $analysis->refresh()->getAttributes()];
            });
            // Slow local inference runs outside database locks. No fallback concepts are produced.
            $result = $this->identify($title, $snapshot['extracted_text']);

            return DB::transaction(function () use ($proposal, $snapshot, $title, $result) {
                $locked = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
                $analysis = $locked->analysis()->first();
                if ($locked->title !== $title || $analysis?->getAttributes() !== $snapshot) {
                    throw ValidationException::withMessages(['analysis' => 'This proposal changed during analysis. Please try again.']);
                }
                $analysis->forceFill([
                    ...$result,
                    'analyzed_at' => now(),
                    'analysis_version' => ProposalAnalysis::CURRENT_VERSION,
                    'analysis_status' => 'completed',
                ])->save();
                if ($locked->recommendations()->exists()) {
                    app(RecommendationService::class)->generate($locked, $locked->studentProfile->user);
                }

                return $analysis;
            });
        } catch (ValidationException $exception) {
            $this->markFailed($proposal, $attemptId);
            throw $exception;
        } catch (Throwable) {
            $this->markFailed($proposal, $attemptId);
            // Database errors can contain bound proposal text: log only fixed metadata.
            Log::warning('Proposal analysis could not be saved.', ['category' => 'analysis_save', 'proposal_id' => $proposal->id]);
            throw ValidationException::withMessages(['analysis' => LocalNlpService::UNAVAILABLE]);
        }
    }

    private function markFailed(ResearchProposal $proposal, string $attemptId): void
    {
        try {
            // An older failed request must never overwrite a newer attempt's status.
            DB::table('proposal_analyses')->where('research_proposal_id', $proposal->id)
                ->where('analysis_attempt_id', $attemptId)
                ->update(['analysis_status' => 'failed', 'updated_at' => now()]);
        } catch (Throwable) {
            Log::warning('Proposal analysis status could not be saved.', ['category' => 'status_save', 'proposal_id' => $proposal->id]);
        }
    }

    public function identify(string $title, string $text): array
    {
        if (! preg_match('/\p{L}/u', $text)) {
            throw ValidationException::withMessages(['analysis' => 'No readable proposal text is available. Refresh the document extraction to retry.']);
        }
        $clean = $this->textNormalizer->normalize($text);
        // Feed the complete relevant proposal, not just its title, to the existing local NLP.
        $relevant = $this->textNormalizer->relevant($clean, $title);
        $concepts = $this->nlp->analyze($title, $relevant);
        $identified = $this->expertise->analyze($title, $clean, $concepts);
        if ($identified['direct'] === []) {
            throw ValidationException::withMessages([
                'analysis' => 'No reliable skills from the supported faculty skill categories could be identified. Review the proposal content before generating recommendations.',
            ]);
        }
        $categories = [...$identified['direct'], ...$identified['related']];
        $representation = $this->rtaText->build($title, $categories, $clean);

        return [
            'primary_research_area' => null,
            'keywords' => $identified['direct'],
            'technologies' => $concepts,
            'identified_expertise_areas' => $identified['related'],
            'research_representation' => $representation,
        ];
    }
}
