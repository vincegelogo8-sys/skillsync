<?php

namespace App\Services;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use InvalidArgumentException;

class TopicAlignmentService
{
    public const COSINE_WEIGHT = 0.70;

    public const MULTI_EXPERTISE_WEIGHT = 0.30;

    public function __construct(private SimilarityService $similarity, private MultiExpertiseService $multiExpertise) {}

    /** Full-precision percentages; final recommendation weighting happens later. */
    public function calculate(float $cosineSimilarity, float $multiExpertiseStrength): array
    {
        if (! is_finite($cosineSimilarity) || ! is_finite($multiExpertiseStrength)) {
            throw new InvalidArgumentException('Topic alignment components must be finite numbers.');
        }
        $cosine = max(0.0, min(100.0, $cosineSimilarity));
        $expertise = max(0.0, min(100.0, $multiExpertiseStrength));
        $cosineContribution = $cosine * self::COSINE_WEIGHT;
        $expertiseContribution = $expertise * self::MULTI_EXPERTISE_WEIGHT;

        return [
            'cosine_similarity_score' => $cosine,
            'multi_expertise_score' => $expertise,
            'cosine_contribution' => $cosineContribution,
            'multi_expertise_contribution' => $expertiseContribution,
            'topic_alignment_score' => max(0.0, min(100.0, $cosineContribution + $expertiseContribution)),
        ];
    }

    /**
     * Calculate all candidates together using one shared cosine corpus and expertise read.
     * Results are keyed by faculty profile ID, in supplied order; no ranking or writes.
     */
    public function forProposal(ProposalAnalysis $analysis, iterable $faculties): array
    {
        if (! $analysis->analyzed_at || ! is_array($analysis->identified_expertise_areas)) {
            throw new InvalidArgumentException('Complete proposal analysis before calculating topic alignment.');
        }
        $areas = $analysis->identified_expertise_areas;
        // Validate canonical requirements even when the candidate list is empty.
        $this->multiExpertise->calculate($areas, []);
        $ids = [];
        foreach ($faculties as $faculty) {
            if (! $faculty instanceof FacultyProfile || ! $faculty->exists) {
                throw new InvalidArgumentException('Supply saved faculty profiles for topic alignment.');
            }
            $ids[] = $faculty->getKey();
        }
        $ids = array_values(array_unique($ids));
        $title = $analysis->researchProposal()->value('title');
        if ($title === null) {
            throw new InvalidArgumentException('The saved proposal is required for topic alignment.');
        }
        $expertise = FacultyExpertise::whereIn('faculty_profile_id', $ids)->orderBy('expertise_area')->get()->groupBy('faculty_profile_id');
        $documents = [];
        $proficiencies = [];
        foreach ($ids as $id) {
            $entries = $expertise->get($id) ?? collect();
            $documents[$id] = $entries->pluck('expertise_area')->unique()->implode(' ');
            $proficiencies[$id] = $entries->pluck('proficiency_score', 'expertise_area')->all();
        }
        $similarity = $this->similarity->calculate($title."\n".$analysis->extracted_text, $documents);
        $results = [];
        foreach ($ids as $id) {
            $multi = $this->multiExpertise->calculate($areas, $proficiencies[$id]);
            $results[$id] = [
                ...$this->calculate($similarity['scores'][$id], $multi['score']),
                'required_count' => $multi['required_count'],
                'expertise_breakdown' => $multi['breakdown'],
            ];
        }

        return $results;
    }
}
