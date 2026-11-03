<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\Recommendation;
use App\Models\ResearchProposal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecommendationService
{
    public function __construct(
        private ExpertiseMatchingService $expertiseMatching,
        private FacultyNameSorter $nameSorter
    ) {}

    /** Explicit generation replaces the proposal's current recommendations atomically. */
    public function generate(ResearchProposal $proposal, User $actor): Collection
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $proposal = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->role === User::ROLE_ADMIN || ($actor->role === User::ROLE_STUDENT && $actor->studentProfile?->id === $proposal->student_profile_id), 403);
            $analysis = $proposal->analysis()->first();
            if (! $analysis?->hasCurrentAnalysis()) {
                throw ValidationException::withMessages(['recommendations' => 'Complete proposal analysis before generating recommendations.']);
            }
            $analysis->setRelation('researchProposal', $proposal);
            // Matching uses only saved analysis categories and current faculty skills.
            $faculties = FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY))
                ->with(['user', 'expertise'])->get();
            $matchedIds = [];
            foreach ($faculties as $faculty) {
                $matches = $this->expertiseMatching->matches($analysis, $faculty);
                if ($matches === []) {
                    continue;
                }
                $matchedIds[] = $faculty->id;
                $recommendation = $proposal->recommendations()->where('faculty_profile_id', $faculty->id)->first();
                if (! $recommendation) {
                    $recommendation = new Recommendation;
                    $recommendation->research_proposal_id = $proposal->id;
                    $recommendation->faculty_profile_id = $faculty->id;
                }
                $recommendation->details = [
                    'matching_expertise' => $matches,
                    'analysis_id' => $analysis->id,
                    'analyzed_at' => $analysis->analyzed_at->toIso8601String(),
                ];
                $recommendation->updated_at = now();
                $recommendation->save();
            }
            $unmatched = $proposal->recommendations();
            if ($matchedIds !== []) {
                $unmatched->whereNotIn('faculty_profile_id', $matchedIds);
            }
            $unmatched->delete();

            $recommendations = $proposal->recommendations()->with('facultyProfile.user', 'facultyProfile.expertise')->get();

            return $this->nameSorter->sort($recommendations);
        });
    }
}
