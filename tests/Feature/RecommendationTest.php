<?php

namespace Tests\Feature;

use App\Models\FacultyCompetency;
use App\Models\FacultyProfile;
use App\Models\Recommendation;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    public static function scores(): array
    {
        return [
            [88, 80, 75, 90, 83.2],
            [87.1, 80, 75, 90, 82.84],
            [100, 100, 100, 100, 100],
            [0, 0, 0, 0, 0],
            [100, 0, 0, 0, 40],
            [0, 100, 0, 0, 30],
            [0, 0, 100, 0, 20],
            [0, 0, 0, 100, 10],
            [150, -30, 150, -30, 60],
        ];
    }

    #[DataProvider('scores')]
    public function test_exact_weights_and_clamping(float $rta, float $rac, float $pc, float $sa, float $expected): void
    {
        $result = app(RecommendationService::class)->calculate($rta, $rac, $pc, $sa);
        $this->assertEqualsWithDelta($expected, $result['final_score'], 1e-10);
        $this->assertEqualsWithDelta($result['final_score'], array_sum($result['contributions']), 1e-10);
    }

    public function test_non_finite_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RecommendationService::class)->calculate(80, 80, NAN, 80);
    }

    public function test_completed_zero_assessment_is_not_reported_as_missing(): void
    {
        $proposal = $this->proposal();
        $faculty = $this->faculty();
        $attempt = $this->attempt($faculty, 0, '2026-01-01 10:00:00');
        $this->attempt($faculty, null, null);
        $result = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)->sole();
        $this->assertSame('0.00000000', $result->skills_assessment_score);
        $this->assertFalse($result->details['missing_assessment']);
        $this->assertSame($attempt, $result->details['assessment_attempt_id']);
        $this->assertTrue($result->details['missing_competency']);
    }

    private function proposal(bool $analyzed = true): ResearchProposal
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $user->studentProfile()->create(['student_number' => 'S-'.$user->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Application', 'file_path' => 'proposal-'.$user->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        if ($analyzed) {
            $analysis = $proposal->analysis()->make();
            $analysis->extracted_text = 'Objectives: Web application using Laravel PHP MySQL.';
            $analysis->save();
            app(ProposalAnalysisService::class)->analyze($proposal);
        }

        return $proposal;
    }

    private function faculty(): FacultyProfile
    {
        return User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
    }

    private function attempt(FacultyProfile $faculty, ?int $percentage, ?string $completed): int
    {
        $attempt = $faculty->assessmentAttempts()->make();
        $attempt->forceFill(['score' => $percentage === null ? null : $percentage / 10, 'total_items' => 10, 'percentage' => $percentage, 'completed_at' => $completed])->save();

        return $attempt->id;
    }

    public function test_generation_stores_components_latest_assessment_missing_flags_and_stable_ranks(): void
    {
        $proposal = $this->proposal();
        $before = $proposal->analysis->toArray();
        $empty = $this->faculty();
        $strong = $this->faculty();
        $tie = $this->faculty();
        $strong->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $strong->preferences()->create(['preference_type' => 'project_type', 'preference_value' => 'Web-Based System']);
        $strong->competency()->create(array_fill_keys(array_keys(FacultyCompetency::DIMENSIONS), 4));
        $this->attempt($strong, 100, '2026-01-01 10:00:00');
        $this->attempt($strong, 70, '2026-01-02 10:00:00');
        $latest = $this->attempt($strong, 80, '2026-01-02 10:00:00');
        $this->attempt($strong, null, null);
        // A full/zero-limit faculty remains in the ranking with unchanged scoring.
        $strong->forceFill(['advisory_limit' => 0])->save();
        $service = app(RecommendationService::class);
        $rows = $service->generate($proposal, $proposal->studentProfile->user);
        $this->assertSame([$strong->id, $empty->id, $tie->id], $rows->pluck('faculty_profile_id')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('rank')->all());
        $first = $rows->first();
        $this->assertSame('80.00000000', $first->advising_competency_score);
        $this->assertSame('80.00000000', $first->skills_assessment_score);
        $this->assertSame($latest, $first->details['assessment_attempt_id']);
        $this->assertFalse($first->details['missing_competency']);
        $this->assertFalse($first->details['missing_assessment']);
        $this->assertTrue($rows[1]->details['missing_competency']);
        $this->assertTrue($rows[1]->details['missing_assessment']);
        $this->assertEqualsWithDelta((float) $first->topic_alignment_score * .4 + 80 * .3 + (float) $first->preference_compatibility_score * .2 + 80 * .1, (float) $first->final_score, 1e-7);
        $this->assertTrue($first->researchProposal->is($proposal));
        $this->assertTrue($first->facultyProfile->is($strong));
        $this->assertSame($before, $proposal->fresh()->analysis->toArray());
        $this->assertSame('extracted', $proposal->fresh()->status);
        $ids = $rows->modelKeys();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $again = $service->generate($proposal, $admin);
        $this->assertSame($ids, $again->modelKeys());
        $this->assertDatabaseCount('recommendations', 3);
        $strong->forceFill(['advisory_limit' => 100])->save();
        $this->assertSame($first->final_score, $service->generate($proposal, $admin)->first()->final_score);
    }

    public function test_refresh_removes_ineligible_candidates_only_for_this_proposal_and_no_candidates_is_safe(): void
    {
        $first = $this->proposal();
        $second = $this->proposal();
        $faculty = $this->faculty();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $service = app(RecommendationService::class);
        $service->generate($first, $admin);
        $service->generate($second, $admin);
        $faculty->user->forceFill(['role' => User::ROLE_STUDENT])->save();
        $this->assertCount(0, $service->generate($first, $admin));
        $this->assertSame(1, $second->recommendations()->count());
        $this->assertSame(0, $first->recommendations()->count());
    }

    public function test_unauthorized_student_and_faculty_cannot_generate(): void
    {
        $proposal = $this->proposal();
        foreach ([User::ROLE_STUDENT, User::ROLE_FACULTY] as $role) {
            try {
                app(RecommendationService::class)->generate($proposal, User::factory()->create(['role' => $role]));
                $this->fail('Unauthorized generation succeeded.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_pending_analysis_is_rejected_without_writes(): void
    {
        $proposal = $this->proposal(false);
        $this->expectException(ValidationException::class);
        app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user);
    }

    public function test_failed_refresh_rolls_back_previous_scores_and_ranks(): void
    {
        $proposal = $this->proposal();
        $first = $this->faculty();
        $second = $this->faculty();
        $service = app(RecommendationService::class);
        $actor = $proposal->studentProfile->user;
        $before = $service->generate($proposal, $actor)->toArray();
        $second->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 100]);
        $saves = 0;
        Event::listen('eloquent.saving: '.Recommendation::class, function () use (&$saves) {
            if (++$saves === 2) {
                throw new RuntimeException('Simulated second write failure');
            }
        });
        try {
            $service->generate($proposal, $actor);
            $this->fail('Expected save failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated second write failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.saving: '.Recommendation::class);
        }
        $this->assertSame($before, $proposal->recommendations()->orderBy('rank')->get()->toArray());
        $this->assertSame($second->id, $service->generate($proposal, $actor)->first()->faculty_profile_id);
    }

    public function test_recommendations_cascade_with_proposal_and_faculty_deletion(): void
    {
        $proposal = $this->proposal();
        $first = $this->faculty();
        $this->faculty();
        app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user);
        $first->delete();
        $this->assertDatabaseCount('recommendations', 1);
        $proposal->delete();
        $this->assertDatabaseCount('recommendations', 0);
    }
}
