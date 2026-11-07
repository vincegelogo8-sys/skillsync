<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;

class ExpertiseMatchingService
{
    public function __construct(private ProposalTextNormalizer $normalizer) {}

    /**
     * Identify supported areas directly from relevant proposal content and infer
     * related areas only when their configured technical evidence is present.
     *
     * @return array{direct: list<string>, related: list<string>, reasons: array<string, string>}
     */
    public function analyze(string $title, string $text, array $concepts = []): array
    {
        $evidence = $this->normalizer->relevant($text, $title);
        $evidence .= ' '.mb_strtolower(implode(' ', $concepts));
        $direct = [];

        foreach (array_keys(config('expertise_matching.categories', [])) as $category) {
            foreach (config('expertise_matching.categories.'.$category, []) as $trigger) {
                if ($this->contains($evidence, $trigger)) {
                    $direct[] = $category;
                    break;
                }
            }
        }

        $related = [];
        $reasons = [];
        foreach (config('expertise_matching.related', []) as $rule) {
            if (
                in_array($rule['from'], $direct, true)
                && ! in_array($rule['to'], $direct, true)
                && preg_match($rule['evidence'], $evidence)
            ) {
                $related[$rule['to']] = true;
                $reasons[$rule['to']] ??= $rule['reason'];
            }
        }

        return [
            'direct' => $direct,
            'related' => array_values(array_intersect(array_keys(config('expertise_matching.categories')), array_keys($related))),
            'reasons' => $reasons,
        ];
    }

    /**
     * Return only faculty skill categories supported by the saved proposal analysis.
     *
     * @return list<array{area: string, category: string, match_type: string, explanation: string}>
     */
    public function matches(ProposalAnalysis $analysis, FacultyProfile $faculty): array
    {
        $direct = $this->canonicalCategories($analysis->keywords);
        $related = $this->canonicalCategories($analysis->identified_expertise_areas);
        $matches = [];
        $matchedCategories = [];
        $proposal = $analysis->relationLoaded('researchProposal')
            ? $analysis->researchProposal
            : $analysis->researchProposal()->first();
        $proposalText = $this->normalizer->relevant(
            (string) $analysis->extracted_text,
            (string) $proposal?->title
        );

        foreach ($faculty->expertise as $entry) {
            $category = $this->canonical($entry->expertise_area);
            if ($category === null || in_array($category, $matchedCategories, true)) {
                continue;
            }
            if (in_array($category, $direct, true)) {
                $matches[] = [
                    'area' => $category,
                    'category' => $category,
                    'match_type' => 'direct',
                    'explanation' => "The proposal analysis directly identified {$category}, which is listed in this faculty member's saved faculty skills.",
                ];
                $matchedCategories[] = $category;

                continue;
            }
            if (in_array($category, $related, true)) {
                $reason = $this->relatedReason($direct, $category, $proposalText);
                if ($reason === null) {
                    continue;
                }
                $matches[] = [
                    'area' => $category,
                    'category' => $category,
                    'match_type' => 'related',
                    'explanation' => "The proposal analysis identified {$category} as relevant because {$reason}; this faculty member has it in their saved faculty skills.",
                ];
                $matchedCategories[] = $category;
            }
        }

        // "Others": terms the faculty typed themselves are matched directly against the proposal text.
        $evidence = mb_strtolower($proposalText.' '.(is_array($analysis->technologies) ? implode(' ', array_filter($analysis->technologies, 'is_string')) : ''));
        foreach ($faculty->expertise as $entry) {
            if ($entry->expertise_area !== config('expertise.other') || blank($entry->technical_skills)) {
                continue;
            }
            foreach (FacultyExpertiseService::otherTerms($entry->technical_skills) as $term) {
                if ($this->contains($evidence, $term)) {
                    $matches[] = [
                        'area' => 'Others: '.$term,
                        'category' => config('expertise.other'),
                        'match_type' => 'direct',
                        'explanation' => "The proposal mentions \"{$term}\", which this faculty member listed under Others in their faculty skills.",
                    ];
                }
            }
        }

        return $matches;
    }

    public function canonical(string $area): ?string
    {
        $normalized = $this->normalizeArea($area);

        foreach (config('expertise_matching.aliases', []) as $alias => $category) {
            if ($this->normalizeArea($alias) === $normalized) {
                return in_array($category, array_keys(config('expertise_matching.categories', [])), true)
                    ? $category
                    : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function canonicalCategories(mixed $areas): array
    {
        if (! is_array($areas)) {
            return [];
        }

        $categories = [];
        foreach ($areas as $area) {
            if (! is_string($area)) {
                continue;
            }
            $category = $this->canonical($area);
            if ($category !== null) {
                $categories[$category] = true;
            }
        }

        return array_keys($categories);
    }

    private function relatedReason(array $direct, string $category, string $proposalText): ?string
    {
        foreach (config('expertise_matching.related', []) as $rule) {
            if (
                $rule['to'] === $category
                && in_array($rule['from'], $direct, true)
                && preg_match($rule['evidence'], $proposalText)
            ) {
                return $rule['reason'];
            }
        }

        return null;
    }

    private function contains(string $text, string $phrase): bool
    {
        $phrase = preg_quote(mb_strtolower($phrase), '~');
        $phrase = preg_replace('/\\\\\s+/u', '\\s+', $phrase);

        return (bool) preg_match('~(?<![\p{L}\p{N}])'.$phrase.'(?![\p{L}\p{N}])~iu', $text);
    }

    private function normalizeArea(string $area): string
    {
        $area = mb_strtolower(trim($area));
        $area = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $area);

        return trim(preg_replace('/\s+/u', ' ', $area));
    }
}