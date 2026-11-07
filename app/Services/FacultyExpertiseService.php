<?php

namespace App\Services;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyExpertiseService
{
    public function save(FacultyProfile $profile, array $attributes, ?FacultyExpertise $expertise = null): FacultyExpertise
    {
        abort_if($expertise && $expertise->faculty_profile_id !== $profile->id, 404);

        $expertise ??= $profile->expertise()->make();

        try {
            $expertise->fill([
                'expertise_area' => $attributes['expertise_area'],
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            // The database also prevents duplicates from simultaneous submissions.
            throw ValidationException::withMessages([
                'expertise_area' => 'This faculty skill is already listed.',
            ]);
        }

        return $expertise;
    }

    /**
     * Replace the supported category selection without touching legacy entries outside the allowlist.
     *
     * @param  list<string>  $areas
     * @param  string|null  $otherSpecify  Free text typed under "Others" (comma or line separated terms).
     */
    public function saveCategories(FacultyProfile $profile, array $areas, ?string $otherSpecify = null): void
    {
        DB::transaction(function () use ($profile, $areas, $otherSpecify) {
            $supported = config('expertise.areas');
            $selected = array_values(array_unique($areas));

            $toRemove = array_diff($supported, $selected);
            if ($toRemove !== []) {
                $profile->expertise()->whereIn('expertise_area', $toRemove)->delete();
            }

            $other = config('expertise.other');
            foreach (array_intersect($supported, $selected) as $area) {
                $entry = $profile->expertise()->firstOrCreate(['expertise_area' => $area]);

                if ($area === $other) {
                    // The specified text lives in technical_skills and is what proposals are matched against.
                    $entry->forceFill(['technical_skills' => implode(', ', self::otherTerms($otherSpecify))])->save();
                }
            }
        });
    }

    /**
     * Split the "Others" text into clean, unique terms (comma, semicolon or line separated).
     *
     * @return list<string>
     */
    public static function otherTerms(?string $text): array
    {
        $terms = preg_split('/[,;\r\n]+/u', strip_tags((string) $text)) ?: [];
        $terms = array_map(fn ($term) => mb_substr(trim(preg_replace('/\s+/u', ' ', $term)), 0, 60), $terms);
        $terms = array_filter($terms, fn ($term) => mb_strlen($term) >= 2);

        return array_slice(array_values(array_unique($terms)), 0, 10);
    }

    public function delete(FacultyProfile $profile, FacultyExpertise $expertise): void
    {
        abort_unless($expertise->faculty_profile_id === $profile->id, 404);
        $expertise->delete();
    }
}