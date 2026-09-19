<?php

namespace App\Services;

use App\Models\FacultyPreference;
use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use InvalidArgumentException;

class PreferenceCompatibilityService
{
    public const PROJECT_TYPE_WEIGHT = 0.50;

    public const TECHNOLOGY_WEIGHT = 0.50;

    /** Canonical inputs; duplicates count once. Retain precision for later weighting. */
    public function calculate(?string $projectType, array $technologies, array $preferredProjectTypes, array $preferredTechnologies): array
    {
        $this->validateValues($projectType === null ? [] : [$projectType], 'project_types');
        $this->validateValues($preferredProjectTypes, 'project_types');
        $this->validateValues($technologies, 'technologies');
        $this->validateValues($preferredTechnologies, 'technologies');
        $technologies = array_values(array_unique($technologies));
        $matched = array_values(array_intersect($technologies, $preferredTechnologies));
        $unmatched = array_values(array_diff($technologies, $preferredTechnologies));
        $projectMatch = $projectType !== null && in_array($projectType, $preferredProjectTypes, true);
        $projectScore = $projectMatch ? 100.0 : 0.0;
        $technologyScore = count($technologies) === 0 ? 0.0 : count($matched) / count($technologies) * 100;
        $projectContribution = $projectScore * self::PROJECT_TYPE_WEIGHT;
        $technologyContribution = $technologyScore * self::TECHNOLOGY_WEIGHT;

        return [
            'project_type_match' => $projectMatch,
            'project_type_score' => $projectScore,
            'technology_score' => $technologyScore,
            'project_type_contribution' => $projectContribution,
            'technology_contribution' => $technologyContribution,
            'preference_compatibility_score' => max(0.0, min(100.0, $projectContribution + $technologyContribution)),
            'technology_count' => count($technologies),
            'matched_technologies' => $matched,
            'unmatched_technologies' => $unmatched,
        ];
    }

    /** Results keyed by profile ID; read current preferences once for all candidates. */
    public function forProposal(ProposalAnalysis $analysis, iterable $faculties): array
    {
        if (! $analysis->analyzed_at || ! is_array($analysis->technologies)) {
            throw new InvalidArgumentException('Complete proposal analysis before calculating preference compatibility.');
        }
        $this->calculate($analysis->project_type, $analysis->technologies, [], []);
        $ids = [];
        foreach ($faculties as $faculty) {
            if (! $faculty instanceof FacultyProfile || ! $faculty->exists) {
                throw new InvalidArgumentException('Supply saved faculty profiles for preference compatibility.');
            }
            $ids[] = $faculty->getKey();
        }
        $ids = array_values(array_unique($ids));
        $preferences = FacultyPreference::whereIn('faculty_profile_id', $ids)->get()->groupBy('faculty_profile_id');
        $results = [];
        foreach ($ids as $id) {
            $entries = $preferences->get($id) ?? collect();
            $results[$id] = $this->calculate(
                $analysis->project_type,
                $analysis->technologies,
                $entries->where('preference_type', FacultyPreference::TYPE_PROJECT)->pluck('preference_value')->all(),
                $entries->where('preference_type', FacultyPreference::TYPE_TECHNOLOGY)->pluck('preference_value')->all(),
            );
        }

        return $results;
    }

    private function validateValues(array $values, string $list): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, config('preferences.'.$list), true)) {
                throw new InvalidArgumentException('Preference compatibility requires canonical '.$list.' values.');
            }
        }
    }
}
