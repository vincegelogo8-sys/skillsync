<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecommendationService
{
    public const WEIGHTS = [
        'topic_alignment_score' => 0.40,
        'advising_competency_score' => 0.30,
        'preference_compatibility_score' => 0.20,
        'skills_assessment_score' => 0.10,
    ];

    public function __construct(
        private TopicAlignmentService $topicAlignment,
        private PreferenceCompatibilityService $preferences,
        private CompetencyService $competency,
        private SkillsAssessmentService $assessment,
    ) {}

    public function calculate(float $topicAlignment, float $competency, float $preferences, float $assessment): array
    {
        $scores = array_combine(array_keys(self::WEIGHTS), [$topicAlignment, $competency, $preferences, $assessment]);
        $contributions = [];
        foreach ($scores as $field => $score) {
            if (! is_finite($score)) {
                throw new InvalidArgumentException('Recommendation components must be finite numbers.');
            }
            $scores[$field] = max(0.0, min(100.0, $score));
            $contributions[$field] = $scores[$field] * self::WEIGHTS[$field];
        }

        return [...$scores, 'final_score' => max(0.0, min(100.0, array_sum($contributions))), 'contributions' => $contributions];
    }

    /** Explicit generation replaces the proposal's current ranking atomically. */
    public function generate(ResearchProposal $proposal, User $actor): Collection
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $proposal = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->role === User::ROLE_ADMIN || ($actor->role === User::ROLE_STUDENT && $actor->studentProfile?->id === $proposal->student_profile_id), 403);
            $analysis = $proposal->analysis()->first();
            if (! $analysis?->analyzed_at) {
                throw ValidationException::withMessages(['recommendations' => 'Complete proposal analysis before generating recommendations.']);
            }
            // Capacity is independent of scoring: include all current faculty profiles.
            $faculties = FacultyProfile::whereHas('user', fn ($query) => $query->where('role', User::ROLE_FACULTY))
                ->with(['competency', 'latestCompletedAssessment'])->orderBy('id')->get();
            $alignment = $this->topicAlignment->forProposal($analysis, $faculties);
            $preferences = $this->preferences->forProposal($analysis, $faculties);
            $rows = [];
            foreach ($faculties as $faculty) {
                $rac = $this->competency->score($faculty->competency);
                $sa = $this->assessment->score($faculty->latestCompletedAssessment);
                $score = $this->calculate($alignment[$faculty->id]['topic_alignment_score'], $rac ?? 0, $preferences[$faculty->id]['preference_compatibility_score'], $sa ?? 0);
                $rows[] = [
                    'faculty_profile_id' => $faculty->id,
                    'cosine_similarity_score' => $alignment[$faculty->id]['cosine_similarity_score'],
                    'multi_expertise_score' => $alignment[$faculty->id]['multi_expertise_score'],
                    ...array_diff_key($score, ['contributions' => true]),
                    'details' => [
                        'weights' => self::WEIGHTS,
                        'contributions' => $score['contributions'],
                        'topic_alignment' => $alignment[$faculty->id],
                        'preferences' => $preferences[$faculty->id],
                        'missing_competency' => $rac === null,
                        'missing_assessment' => $sa === null,
                        'competency_id' => $faculty->competency?->id,
                        'assessment_attempt_id' => $faculty->latestCompletedAssessment?->id,
                        'analysis_id' => $analysis->id,
                        'analyzed_at' => $analysis->analyzed_at->toIso8601String(),
                    ],
                ];
            }
            // Rank using the same eight-decimal precision persisted in the database.
            usort($rows, fn ($a, $b) => (round($b['final_score'], 8) <=> round($a['final_score'], 8)) ?: ($a['faculty_profile_id'] <=> $b['faculty_profile_id']));
            foreach ($rows as $index => $row) {
                $recommendation = $proposal->recommendations()->where('faculty_profile_id', $row['faculty_profile_id'])->first() ?? $proposal->recommendations()->make();
                $recommendation->forceFill([...$row, 'rank' => $index + 1]);
                // An explicit refresh records its time even when the scores are unchanged.
                $recommendation->updated_at = now();
                $recommendation->save();
            }
            $proposal->recommendations()->whereNotIn('faculty_profile_id', $faculties->modelKeys())->delete();

            return $proposal->recommendations()->orderBy('rank')->get();
        });
    }
}
