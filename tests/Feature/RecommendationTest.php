<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\Recommendation;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_generation_includes_only_saved_expertise_matches_and_keeps_capacity_separate(): void
    {
        $proposal = $this->proposal();
        $before = $proposal->analysis->toArray();
        $unmatched = $this->faculty();
        $webFaculty = $this->faculty();
        $databaseFaculty = $this->faculty();
        $webFaculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $databaseFaculty->expertise()->create(['expertise_area' => 'Database Systems']);
        $unmatched->expertise()->create(['expertise_area' => 'Cloud Computing']);
        $webFaculty->forceFill(['advisory_limit' => 0])->save();
        $service = app(RecommendationService::class);
        $rows = $service->generate($proposal, $proposal->studentProfile->user);
        $this->assertEqualsCanonicalizing([$webFaculty->id, $databaseFaculty->id], $rows->pluck('faculty_profile_id')->all());
        $this->assertSame([null, null], $rows->pluck('rank')->all());
        $this->assertNull($rows->first()->topic_alignment_score);
        $this->assertArrayHasKey('matching_expertise', $rows->first()->details);
        $this->assertSame($before, $proposal->fresh()->analysis->toArray());
        $this->assertSame('extracted', $proposal->fresh()->status);
        $ids = $rows->modelKeys();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $again = $service->generate($proposal, $admin);
        $this->assertEqualsCanonicalizing($ids, $again->modelKeys());
        $this->assertDatabaseCount('recommendations', 2);
        $this->assertSame($ids, $again->pluck('id')->all());
    }

    public function test_recommendations_sort_by_distinct_matching_skills_then_surname_and_first_name(): void
    {
        $proposal = $this->proposal();
        $analysis = $proposal->analysis;
        $analysis->extracted_text = 'Objectives: Web application using Laravel PHP MySQL. The proposal describes storage and retrieval of persistent records, software design, implementation, and testing.';
        $analysis->forceFill([
            'keywords' => ['Web Development'],
            'identified_expertise_areas' => ['Database Systems', 'Software Engineering'],
        ])->save();
        $faculty = [];
        foreach ([
            'Zoe Zulu' => ['Web Development', 'Database Systems', 'Software Engineering', 'Cloud Computing'],
            'Zoe Alpha' => ['Web Development', 'Database Systems'],
            'Amy Alpha' => ['Database Systems', 'Software Engineering'],
            'Ben Beta' => ['Web Development', 'Software Engineering'],
            'Andy Delta' => ['Web Development', 'web development', 'Database Systems'],
            'Una Unmatched' => ['Cloud Computing'],
        ] as $name => $areas) {
            $profile = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => $name])
                ->facultyProfile()->create(['department' => 'Computing']);
            foreach ($areas as $area) {
                $profile->expertise()->create(['expertise_area' => $area]);
            }
            $faculty[$name] = $profile;
        }

        $rows = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user);

        $this->assertSame(
            [3, 2, 2, 2, 2],
            $rows->map(fn ($row) => count($row->details['matching_expertise']))->all()
        );
        $this->assertSame([
            $faculty['Zoe Zulu']->id,
            $faculty['Amy Alpha']->id,
            $faculty['Zoe Alpha']->id,
            $faculty['Ben Beta']->id,
            $faculty['Andy Delta']->id,
        ], $rows->pluck('faculty_profile_id')->all());
        $this->assertCount(3, $rows->first()->details['matching_expertise']);
        $this->assertCount(2, $rows->last()->details['matching_expertise']);
        $this->assertSame(
            ['Database Systems', 'Web Development'],
            array_column($rows->last()->details['matching_expertise'], 'category')
        );
        $this->assertSame(0, $proposal->recommendations()->where('faculty_profile_id', $faculty['Una Unmatched']->id)->count());
    }

    public function test_refresh_removes_ineligible_candidates_only_for_this_proposal_and_no_candidates_is_safe(): void
    {
        $first = $this->proposal();
        $second = $this->proposal();
        $faculty = $this->faculty();
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
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

    public function test_failed_refresh_rolls_back_previous_scores_and_results(): void
    {
        $proposal = $this->proposal();
        $first = $this->faculty();
        $second = $this->faculty();
        $first->expertise()->create(['expertise_area' => 'Web Development']);
        $second->expertise()->create(['expertise_area' => 'Web Development']);
        $service = app(RecommendationService::class);
        $actor = $proposal->studentProfile->user;
        $before = $service->generate($proposal, $actor)->toArray();
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
        $after = $proposal->recommendations()->get()->toArray();
        $this->assertEqualsCanonicalizing(array_column($before, 'id'), array_column($after, 'id'));
        $this->assertEqualsCanonicalizing(array_column($before, 'details'), array_column($after, 'details'));
        $this->assertSame([$first->id, $second->id], $service->generate($proposal, $actor)->pluck('faculty_profile_id')->sort()->values()->all());
    }

    public function test_recommendations_cascade_with_proposal_and_faculty_deletion(): void
    {
        $proposal = $this->proposal();
        $first = $this->faculty();
        $second = $this->faculty();
        $first->expertise()->create(['expertise_area' => 'Web Development']);
        $second->expertise()->create(['expertise_area' => 'Web Development']);
        app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user);
        $first->delete();
        $this->assertDatabaseCount('recommendations', 1);
        $proposal->delete();
        $this->assertDatabaseCount('recommendations', 0);
    }
}
