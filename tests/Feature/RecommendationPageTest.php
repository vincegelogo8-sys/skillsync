<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecommendationPageTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(bool $analyzed = true): ResearchProposal
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $user->studentProfile()->create(['student_number' => 'S-'.$user->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Application', 'file_path' => 'private-'.$user->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        if ($analyzed) {
            $analysis = $proposal->analysis()->make();
            $analysis->extracted_text = 'Objectives: Web application using Laravel PHP MySQL.';
            $analysis->save();
            app(ProposalAnalysisService::class)->analyze($proposal);
        }

        return $proposal;
    }

    private function faculty(string $name = 'Professor Example'): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => $name]);
        $faculty = $user->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
    }

    public function test_student_generates_and_reads_only_matching_faculty_skills_without_scores(): void
    {
        $proposal = $this->proposal();
        $this->faculty();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id)->assertSee($url)->assertSee('View Adviser Recommendations');
        $this->get($url)->assertOk()->assertSee('Generate Recommendations')->assertSee('Generate recommendations to see faculty whose skills match your proposal.');
        $this->assertDatabaseCount('recommendations', 0);
        $this->post($url)->assertRedirect($url)->assertSessionHas('status', 'recommendations-generated');
        $saved = $proposal->recommendations()->sole();
        $this->mock(RecommendationService::class)->shouldNotReceive('generate');
        $this->get($url)->assertOk()->assertSee('Professor Example')->assertDontSee('Rank #')->assertSee('Refresh Recommendations')
            ->assertSee('Matching Faculty Skills')->assertSee('Direct match')->assertDontSee('%')->assertDontSee('Research Topic Alignment')
            ->assertSee('Web Development')
            ->assertDontSee('Research Advising Competency')->assertDontSee('Preference Compatibility')->assertDontSee('Skills Assessment')
            ->assertDontSee('Academic Background Alignment')->assertDontSee('View score breakdown')->assertDontSee('Weighted contributions')
            ->assertDontSee($proposal->file_path);
        $this->get($url)->assertOk();
        $this->assertSame($saved->toArray(), $saved->fresh()->toArray());
    }

    public function test_admin_can_review_analysis_but_cannot_view_or_generate_recommendations(): void
    {
        $proposal = $this->proposal();
        $this->faculty();
        $url = '/admin/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/proposals/'.$proposal->id)->assertOk()
            ->assertSee($proposal->title)->assertSee($proposal->studentProfile->user->name)
            ->assertSee($proposal->studentProfile->student_number)->assertSee('proposal.pdf')->assertSee('PDF')
            ->assertSee('Status')->assertSee($proposal->created_at->format('Y-m-d H:i T'))
            ->assertSee('Extracted Document Text')->assertSee($proposal->analysis->extracted_text)
            ->assertSee('Directly identified skills')->assertSee($proposal->analysis->keywords[0])
            ->assertSee(route('admin.proposals.download', $proposal))
            ->assertDontSee('View Adviser Recommendations')->assertDontSee('Research Topic Alignment')
            ->assertDontSee('Request Adviser')->assertDontSee('Professor Example');
        $this->get($url)->assertNotFound();
        $this->post($url)->assertNotFound();
        $studentUrl = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->get($studentUrl)->assertForbidden();
        $this->post($studentUrl)->assertForbidden();
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_guests_other_students_and_faculty_cannot_access_or_generate(): void
    {
        $proposal = $this->proposal();
        $student = '/student/proposals/'.$proposal->id.'/recommendations';
        $admin = '/admin/proposals/'.$proposal->id.'/recommendations';
        $this->get($student)->assertRedirect('/login');
        $this->post($student)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));
        $this->get($student)->assertNotFound();
        $this->post($student)->assertNotFound();
        $this->get($admin)->assertNotFound();
        $this->post($admin)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]));
        $this->get($student)->assertForbidden();
        $this->post($student)->assertForbidden();
        $this->get($admin)->assertNotFound();
        $this->post($admin)->assertNotFound();
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_incomplete_analysis_has_actionable_guidance_and_post_error(): void
    {
        $proposal = $this->proposal(false);
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->get($url)->assertOk()->assertSee('Analyze your proposal before generating recommendations.')->assertDontSee('Generate Recommendations');
        $this->post($url)->assertRedirect($url)->assertSessionHasErrors('recommendations');
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_no_faculty_generation_shows_clear_empty_state(): void
    {
        $proposal = $this->proposal();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertRedirect($url)->assertSessionHas('status', 'recommendations-empty');
        $this->get($url)->assertOk()->assertSee('No advisers with relevant faculty skills were found for this proposal. You may review the extracted skills or consult the authorized academic personnel.');
    }

    public function test_failed_refresh_has_safe_error_and_existing_results_remain_visible(): void
    {
        $proposal = $this->proposal();
        $this->faculty();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasNoErrors();
        $before = $proposal->recommendations()->sole()->toArray();
        $this->mock(RecommendationService::class)->shouldReceive('generate')->once()->andThrow(new RuntimeException('secret SQL failure'));
        $this->post($url)->assertRedirect($url)->assertSessionHasErrors('recommendations');
        $this->get($url)->assertOk()->assertSee('Previously saved results are preserved.')->assertSee('Professor Example')->assertDontSee('secret SQL failure');
        $this->assertSame($before, $proposal->recommendations()->sole()->toArray());
    }

    public function test_faculty_names_are_escaped_and_private_account_fields_are_not_rendered(): void
    {
        $proposal = $this->proposal();
        $this->faculty('<script>alert(1)</script>');
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasNoErrors();
        $faculty = User::where('role', User::ROLE_FACULTY)->sole();
        $this->get($url)->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee($faculty->email)->assertDontSee($faculty->password)->assertDontSee($proposal->file_path);
    }

    public function test_alphabetical_order_paginates_without_visible_ranking_or_percentages(): void
    {
        $proposal = $this->proposal();
        for ($i = 1; $i <= 11; $i++) {
            $this->faculty('Faculty '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Faculty 01')->assertSee('Faculty 10')->assertDontSee('Faculty 11')->assertDontSee('%');
        $this->get($url.'?page=2')->assertOk()->assertDontSee('Rank #')->assertSee('Faculty 11')->assertDontSee('Faculty 01')->assertDontSee('%');
    }

    public function test_page_orders_distinct_matching_skills_then_surname_without_showing_counts(): void
    {
        $proposal = $this->proposal();
        $proposal->analysis->forceFill([
            'keywords' => ['Web Development'],
            'identified_expertise_areas' => ['Database Systems', 'Software Engineering'],
        ])->save();
        $proposal->analysis->forceFill([
            'extracted_text' => 'The web application stores and retrieves persistent records and includes software design, implementation, and testing.',
        ])->save();

        foreach ([
            'Zoe Zulu' => ['Web Development', 'Database Systems', 'Software Engineering'],
            'Ben Beta' => ['Web Development', 'Software Engineering'],
            'Amy Alpha' => ['Database Systems', 'Software Engineering'],
            'Una Unmatched' => ['Cloud Computing'],
        ] as $name => $areas) {
            $user = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => $name]);
            $faculty = $user->facultyProfile()->create(['department' => 'Computing']);
            foreach ($areas as $area) {
                $faculty->expertise()->create(['expertise_area' => $area]);
            }
        }

        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()
            ->assertSeeInOrder(['Zoe Zulu', 'Amy Alpha', 'Ben Beta'])
            ->assertDontSee('Una Unmatched')
            ->assertDontSee('Matching skills')
            ->assertDontSee('Rank #')
            ->assertDontSee('%')
            ->assertSee('Matching Faculty Skills')
            ->assertSee('Direct match')
            ->assertSee('Request Adviser');
    }

    public function test_historical_scores_and_ranks_are_hidden_and_do_not_control_display_or_order(): void
    {
        $proposal = $this->proposal();
        $this->faculty('Zoe Zulu');
        $this->faculty('Amy Alpha');
        $rows = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user);
        $rows[0]->forceFill(['topic_alignment_score' => 87.35, 'final_score' => 12, 'rank' => 2])->save();
        $rows[1]->forceFill(['topic_alignment_score' => 72.10, 'final_score' => 99, 'rank' => 1])->save();
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id.'/recommendations')
            ->assertOk()->assertSeeInOrder(['Amy Alpha', 'Zoe Zulu'])
            ->assertDontSee('87.35')->assertDontSee('72.10')->assertDontSee('12.00')->assertDontSee('99.00')
            ->assertDontSee('%')->assertDontSee('Rank #');
        $this->assertSame('12.00000000', $rows[0]->fresh()->final_score);
    }
}
