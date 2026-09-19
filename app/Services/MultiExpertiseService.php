<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use InvalidArgumentException;

class MultiExpertiseService
{
    /** Read saved analysis and current faculty proficiency without writing either. */
    public function forProposal(ProposalAnalysis $analysis, FacultyProfile $faculty): array
    {
        if (! $analysis->analyzed_at || ! is_array($analysis->identified_expertise_areas)) {
            throw new InvalidArgumentException('Complete proposal analysis before calculating multi-expertise strength.');
        }

        return $this->calculate(
            $analysis->identified_expertise_areas,
            $faculty->expertise()->pluck('proficiency_score', 'expertise_area')->all(),
        );
    }

    /**
     * @param  array<string>  $requiredAreas  Up to three distinct canonical expertise names.
     * @param  array<string, int|float>  $proficiencies  Faculty scores keyed by canonical name.
     * @return array{score: float, required_count: int, breakdown: array<int, array{expertise_area: string, proficiency_score: float, missing: bool}>}
     */
    public function calculate(array $requiredAreas, array $proficiencies): array
    {
        foreach ($requiredAreas as $area) {
            if (! is_string($area) || ! in_array($area, config('expertise.areas'), true)) {
                throw new InvalidArgumentException('Required expertise must use canonical expertise names.');
            }
        }
        $requiredAreas = array_values(array_unique($requiredAreas));
        if (count($requiredAreas) > 3) {
            throw new InvalidArgumentException('A proposal can require at most three expertise areas.');
        }

        $breakdown = [];
        foreach ($requiredAreas as $area) {
            $missing = ! array_key_exists($area, $proficiencies);
            $value = $missing ? 0 : $proficiencies[$area];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Proficiency scores must be finite numbers.');
            }
            $breakdown[] = [
                'expertise_area' => $area,
                'proficiency_score' => (float) max(0, min(100, $value)),
                'missing' => $missing,
            ];
        }

        $count = count($requiredAreas);

        return [
            // Keep precision for later weighting; presentation should use two decimals.
            'score' => $count === 0 ? 0.0 : array_sum(array_column($breakdown, 'proficiency_score')) / $count,
            'required_count' => $count,
            'breakdown' => $breakdown,
        ];
    }
}
