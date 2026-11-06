<?php

namespace App\Services;

use App\Models\Recommendation;
use App\Support\NameSortKey;
use Illuminate\Support\Collection;

class FacultyNameSorter
{
    public function sort(Collection $recommendations): Collection
    {
        $keys = app(NameSortKey::class);

        return $recommendations->sort(function ($left, $right) use ($keys) {
            $leftName = (string) $left->facultyProfile?->user?->name;
            $rightName = (string) $right->facultyProfile?->user?->name;
            $skillCountOrder = $this->matchingSkillCount($right) <=> $this->matchingSkillCount($left);
            if ($skillCountOrder !== 0) {
                return $skillCountOrder;
            }

            return strcmp($keys->key($leftName), $keys->key($rightName))
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

}
