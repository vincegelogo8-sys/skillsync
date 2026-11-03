<?php

namespace App\Services;

use App\Models\ProposalAnalysis;
use InvalidArgumentException;

class TopicAlignmentService
{
    public function __construct(private SimilarityService $similarity) {}

    /** RTA is the cosine percentage alone. Capacity and proficiency never enter it. */
    public function calculate(float $cosineSimilarity): array
    {
        if (! is_finite($cosineSimilarity)) {
            throw new InvalidArgumentException('Topic alignment must be a finite number.');
        }
        $score = max(0.0, min(100.0, $cosineSimilarity));

        return ['cosine_similarity_score' => $score, 'topic_alignment_score' => $score];
    }

    public function forProposal(ProposalAnalysis $analysis, iterable $faculties): array
    {
        $similarity = $this->similarity->forProposal($analysis, $faculties);
        $results = [];
        foreach ($similarity['scores'] as $id => $score) {
            $results[$id] = $this->calculate($score);
        }

        return $results;
    }
}
