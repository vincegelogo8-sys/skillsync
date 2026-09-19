<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\User;
use App\Services\AdviserAssignmentService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function activity(string $title): array
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => $title, 'file_path' => 'private-'.$student->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'uploaded'])->save();
        $request = new AdviserRequest;
        $request->forceFill(['student_profile_id' => $profile->id, 'faculty_profile_id' => $facultyProfile->id, 'research_proposal_id' => $proposal->id, 'status' => 'pending', 'active_slot' => 1, 'requested_at' => now()])->save();
        $assignment = new AdviserAssignment;
        $assignment->forceFill(['faculty_profile_id' => $facultyProfile->id, 'research_proposal_id' => $proposal->id, 'status' => 'active', 'assigned_at' => now()])->save();

        return [$student, $faculty, $proposal];
    }

    public function test_student_summary_is_scoped_and_reads_do_not_process_or_write_data(): void
    {
        [$student, $faculty, $proposal] = $this->activity('Owned Proposal');
        $this->activity('Private Other Proposal');
        $this->mock(RecommendationService::class)->shouldNotReceive('generate');
        $this->mock(ProposalAnalysisService::class)->shouldNotReceive('analyze');
        $this->mock(AdviserAssignmentService::class)->shouldNotReceive('approve');
        $response = $this->actingAs($student)->get('/student/dashboard');
        $response->assertOk()->assertSee('Owned Proposal')->assertDontSee('Private Other Proposal')->assertDontSee($proposal->file_path)
            ->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label') === ['Research Proposals' => 1, 'Pending Requests' => 1, 'Active Assignments' => 1, 'Awaiting Analysis' => 1]);
        $this->get('/student/dashboard')->assertOk();
        $this->assertDatabaseCount('research_proposals', 2);
        $this->assertDatabaseCount('adviser_requests', 2);
        $this->assertDatabaseCount('adviser_assignments', 2);
        $this->assertDatabaseCount('proposal_analyses', 0);
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_faculty_sees_own_load_latest_completed_result_and_assigned_students(): void
    {
        [$student, $faculty] = $this->activity('Assigned To Me');
        $this->activity('Assigned Elsewhere');
        $profile = $faculty->facultyProfile;
        foreach ([[90, '2026-01-01 10:00:00'], [70, '2026-01-02 10:00:00'], [null, null]] as [$score, $completed]) {
            $attempt = $profile->assessmentAttempts()->make();
            $attempt->forceFill(['total_items' => 10, 'percentage' => $score, 'score' => $score === null ? null : $score / 10, 'completed_at' => $completed])->save();
        }
        $this->actingAs($faculty)->get('/faculty/dashboard')->assertOk()->assertSee('Assigned To Me')->assertSee($student->name)->assertDontSee('Assigned Elsewhere')->assertSee('1 / 5')->assertSee('70.00%')
            ->assertDontSee(route('admin.advisory-limits.index'))->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label')['Active Assignments'] === 1);
    }

    public function test_admin_summary_counts_all_roles_and_activity(): void
    {
        $this->activity('First Proposal');
        $this->activity('Second Proposal');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('First Proposal')->assertSee('Second Proposal')->assertSee('Management')
            ->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label') === ['Research Proposals' => 2, 'Pending Requests' => 2, 'Active Assignments' => 2, 'Awaiting Analysis' => 2, 'Student Accounts' => 2, 'Faculty Accounts' => 2]);
    }

    public static function profileRoles(): array
    {
        return [['student'], ['faculty']];
    }

    #[DataProvider('profileRoles')]
    public function test_missing_profile_has_guidance_and_never_shows_other_activity(string $role): void
    {
        $this->activity('Other Private Activity');
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get('/'.$role.'/dashboard')->assertOk()->assertSee('Complete Profile')->assertDontSee('Other Private Activity')->assertViewHas('profileReady', false);
        $this->assertDatabaseCount('faculty_profiles', 1);
        $this->assertDatabaseCount('student_profiles', 1);
    }

    public function test_navigation_catalog_routes_exist_and_recommendations_keep_proposals_active(): void
    {
        foreach (User::ROLES as $role) {
            foreach (['primary', 'secondary'] as $group) {
                foreach (config('workspace_navigation.'.$role.'.'.$group) as $link) {
                    $this->assertTrue(Route::has($link['route']), $link['route']);
                }
            }
        }
        [$student, $faculty, $proposal] = $this->activity('Owned');
        $response = $this->actingAs($student)->get('/student/proposals/'.$proposal->id.'/recommendations')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//nav//a[@aria-current="page" and @href="'.route('student.proposals.index').'"]')->length);
    }

    public function test_recent_activity_is_limited_and_names_are_escaped(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->activity('Proposal '.$i);
        }
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertViewHas('recentProposals', fn ($proposals) => $proposals->count() === 5 && $proposals->first()->title === 'Proposal 5')
            ->assertViewHas('recentRequests', fn ($requests) => $requests->count() === 5);
    }
}
