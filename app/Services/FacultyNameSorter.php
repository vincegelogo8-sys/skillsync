<?php

namespace App\Services;

use App\Models\Recommendation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FacultyNameSorter
{
    private const PARTICLES = ['al', 'bin', 'da', 'de', 'del', 'dela', 'di', 'la', 'le', 'st', 'van', 'von'];

    private const SUFFIXES = ['ii', 'iii', 'iv', 'jr', 'sr'];

    public function sort(Collection $recommendations): Collection
    {
        return $recommendations->sort(function ($left, $right) {
            $leftName = (string) $left->facultyProfile?->user?->name;
            $rightName = (string) $right->facultyProfile?->user?->name;
            $skillCountOrder = $this->matchingSkillCount($right) <=> $this->matchingSkillCount($left);
            if ($skillCountOrder !== 0) {
                return $skillCountOrder;
            }

            $surnameOrder = strcasecmp($this->surname($leftName), $this->surname($rightName));
            if ($surnameOrder !== 0) {
                return $surnameOrder;
            }

            $firstNameOrder = strcasecmp($this->firstName($leftName), $this->firstName($rightName));

            if ($firstNameOrder !== 0) {
                return $firstNameOrder;
            }

            return strcasecmp(Str::ascii($leftName), Str::ascii($rightName))
                ?: ($left->faculty_profile_id <=> $right->faculty_profile_id);
        })->values();
    }

    private function matchingSkillCount(Recommendation $recommendation): int
    {
        $matches = $recommendation->details['matching_expertise'] ?? [];
        if (! is_array($matches)) {
            return 0;
        }
        $categories = [];

        foreach ($matches as $match) {
            $category = $match['category'] ?? $match['area'] ?? null;
            if (is_string($category) && trim($category) !== '') {
                $categories[mb_strtolower(trim($category))] = true;
            }
        }

        return count($categories);
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($parts !== [] && preg_match('/^(?:dr|prof|professor|mr|mrs|ms|miss)\.?$/iu', $parts[0])) {
            array_shift($parts);
        }

        return Str::ascii($parts[0] ?? '');
    }

    private function surname(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '';
        }

        $last = mb_strtolower(preg_replace('/[.,]+$/u', '', end($parts)));
        if (in_array($last, self::SUFFIXES, true) && count($parts) > 1) {
            array_pop($parts);
        }

        $start = count($parts) - 1;
        while ($start > 0) {
            $previous = mb_strtolower(preg_replace('/[.,]+$/u', '', $parts[$start - 1]));
            if (! in_array($previous, self::PARTICLES, true)) {
                break;
            }
            $start--;
        }

        return Str::ascii(implode(' ', array_slice($parts, $start)));
    }
}
