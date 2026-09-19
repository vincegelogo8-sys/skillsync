<?php

namespace App\Services;

use App\Models\FacultyProfile;
use App\Models\SkillsAssessmentAttempt;
use App\Models\SkillsAssessmentQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SkillsAssessmentService
{
    public const TOTAL_ITEMS = 10;

    public function score(?SkillsAssessmentAttempt $attempt): ?float
    {
        return $attempt?->completed_at && $attempt->percentage !== null ? (float) $attempt->percentage : null;
    }

    public function start(FacultyProfile $profile): SkillsAssessmentAttempt
    {
        return DB::transaction(function () use ($profile) {
            $profile = FacultyProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $active = $profile->assessmentAttempts()->whereNull('completed_at')->first();
            if ($active) {
                return $active;
            }

            $questions = SkillsAssessmentQuestion::inRandomOrder()->limit(self::TOTAL_ITEMS)->lockForUpdate()->get();
            if ($questions->count() !== self::TOTAL_ITEMS) {
                throw ValidationException::withMessages(['assessment' => 'The assessment needs at least 10 questions. Please contact Admin.']);
            }

            $attempt = $profile->assessmentAttempts()->make();
            $attempt->total_items = self::TOTAL_ITEMS;
            $attempt->save();

            foreach ($questions as $index => $question) {
                $answer = $attempt->answers()->make();
                foreach (['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer'] as $field) {
                    $answer->{$field} = $question->{$field};
                }
                $answer->question_id = $question->id;
                $answer->position = $index + 1;
                $answer->save();
            }

            return $attempt;
        });
    }

    public function submit(FacultyProfile $profile, SkillsAssessmentAttempt $attempt, mixed $answers): void
    {
        DB::transaction(function () use ($profile, $attempt, $answers) {
            $attempt = $profile->assessmentAttempts()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->completed_at) {
                return; // A double-click or replay must not change a completed result.
            }
            $items = $attempt->answers()->orderBy('position')->get();
            $rules = ['answers' => ['required', 'array', 'size:'.self::TOTAL_ITEMS]];
            foreach ($items as $item) {
                $rules['answers.'.$item->id] = ['required', 'string', Rule::in(SkillsAssessmentQuestion::OPTIONS)];
            }
            Validator::make(['answers' => $answers], $rules)->validate();
            abort_unless($items->count() === self::TOTAL_ITEMS, 409);

            $score = 0;
            foreach ($items as $item) {
                $item->selected_answer = $answers[$item->id];
                $score += $item->selected_answer === $item->correct_answer ? 1 : 0;
                $item->save();
            }
            $attempt->score = $score;
            $attempt->percentage = $score / self::TOTAL_ITEMS * 100;
            $attempt->completed_at = now();
            $attempt->save();
        });
    }
}
