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
        $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $faculty->preferences()->create(['preference_type' => 'technology', 'preference_value' => 'Laravel']);
    }

    public function test_student_generates_and_reads_saved_ranking_without_recalculation(): void
    {
        $proposal = $this->proposal();
        $this->faculty();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id)->assertSee($url)->assertSee('View Adviser Recommendations');
        $this->get($url)->assertOk()->assertSee('Generate Recommendations')->assertSee('No saved recommendations');
        $this->assertDatabaseCount('recommendations', 0);
        $this->post($url)->assertRedirect($url)->assertSessionHas('status', 'recommendations-generated');
        $saved = $proposal->recommendations()->sole();
        $this->mock(RecommendationService::class)->shouldNotReceive('generate');
        $this->get($url)->assertOk()->assertSee('Professor Example')->assertSee('Rank #1')->assertSee('Refresh Recommendations')
            ->assertSee(number_format((float) $saved->final_score, 2).'%')->assertSee('Research Topic Alignment')
            ->assertSee('Research Advising Competency')->assertSee('Preference Compatibility')->assertSee('Skills Assessment')
            ->assertSee('View score breakdown')->assertSee('Weighted contributions')->assertSee('Cosine Similarity')
            ->assertSee('Multi-Expertise Strength')->assertSee('Missing expertise')->assertSee('Laravel')
            ->assertSee('No competency evaluation')->assertSee('No completed skills assessment')->assertDontSee($proposal->file_path);
        $this->get($url)->assertOk();
        $this->assertSame($saved->toArray(), $saved->fresh()->toArray());
    }

    public function test_admin_can_generate_view_and_refresh_any_proposal(): void
    {
        $proposal = $this->proposal();
        $this->faculty();
        $url = '/admin/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get($url)->assertOk()->assertSee($proposal->studentProfile->user->name);
        $this->post($url)->assertRedirect($url)->assertSessionHasNoErrors();
        $id = $proposal->recommendations()->sole()->id;
        $this->post($url)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame($id, $proposal->recommendations()->sole()->id);
        $this->get($url)->assertOk()->assertSee('/admin/proposals/'.$proposal->id)->assertSee('Professor Example');
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
        $this->get($admin)->assertForbidden();
        $this->post($admin)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]));
        foreach ([$student, $admin] as $url) {
            $this->get($url)->assertForbidden();
            $this->post($url)->assertForbidden();
        }
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_incomplete_analysis_has_actionable_guidance_and_post_error(): void
    {
        $proposal = $this->proposal(false);
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->get($url)->assertOk()->assertSee('Complete text extraction and proposal analysis')->assertDontSee('Generate Recommendations');
        $this->post($url)->assertRedirect($url)->assertSessionHasErrors('recommendations');
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_no_faculty_generation_shows_clear_empty_state(): void
    {
        $proposal = $this->proposal();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertRedirect($url)->assertSessionHas('status', 'recommendations-empty');
        $this->get($url)->assertOk()->assertSee('No faculty profiles are available to rank.')->assertSee('Contact Admin for assistance.');
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

    public function test_saved_ranks_paginate_without_renumbering(): void
    {
        $proposal = $this->proposal();
        for ($i = 1; $i <= 11; $i++) {
            $this->faculty('Faculty '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Faculty 01')->assertSee('Faculty 10')->assertDontSee('Faculty 11');
        $this->get($url.'?page=2')->assertOk()->assertSee('Rank #11')->assertSee('Faculty 11')->assertDontSee('Faculty 01');
    }
}
