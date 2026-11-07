<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\User;
use App\Services\AdviserAssignmentService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
            ->assertSee('View all requests')->assertSee('View all proposals')
            ->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label') === ['Research Proposals' => 1, 'Pending Requests' => 1, 'Active Assignments' => 1, 'Awaiting Analysis' => 1]);
        $this->get('/student/dashboard')->assertOk();
        $this->assertDatabaseCount('research_proposals', 2);
        $this->assertDatabaseCount('adviser_requests', 2);
        $this->assertDatabaseCount('adviser_assignments', 2);
        $this->assertDatabaseCount('proposal_analyses', 0);
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_faculty_sees_own_load_and_assigned_students(): void
    {
        [$student, $faculty] = $this->activity('Assigned To Me');
        $this->activity('Assigned Elsewhere');
        $profile = $faculty->facultyProfile;
        $this->actingAs($faculty)->get('/faculty/dashboard')->assertOk()->assertSee('Assigned To Me')->assertSee($student->name)->assertDontSee('Assigned Elsewhere')->assertSee('1 / 5')->assertDontSee('Latest Completed Assessment')
            ->assertSee('View all requests')->assertSee('View all assignments')
            ->assertDontSee(route('admin.advisory-limits.index'))->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label')['Active Assignments'] === 1);
    }

    public function test_admin_summary_counts_all_roles_and_activity(): void
    {
        $this->activity('First Proposal');
        $this->activity('Second Proposal');
        User::where('role', User::ROLE_STUDENT)->orderBy('id')->first()->forceFill(['deactivated_at' => now()])->save();
        User::where('role', User::ROLE_FACULTY)->orderBy('id')->first()->forceFill(['deactivated_at' => now()])->save();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $response = $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('First Proposal')->assertSee('Second Proposal')
            ->assertSee('1 active')->assertSee('1 deactivated')
            ->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label') === ['Research Proposals' => 2, 'Pending Requests' => 2, 'Active Assignments' => 2, 'Awaiting Analysis' => 2, 'Proposals without an adviser' => 0, 'Student Accounts' => '1 active', 'Faculty Accounts' => '1 active']
                && array_column($metrics, 'secondary', 'label')['Student Accounts'] === '1 deactivated'
                && array_column($metrics, 'secondary', 'label')['Faculty Accounts'] === '1 deactivated');
        $this->assertNoDashboardQuickSections($response->getContent());
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
        $response = $this->actingAs($user)->get('/'.$role.'/dashboard')->assertOk()->assertSee('Complete Profile')->assertDontSee('Other Private Activity')->assertViewHas('profileReady', false);
        $response->assertSee('View all requests')
            ->assertSee($role === 'faculty' ? 'View all assignments' : 'View all proposals');
        $this->assertNoDashboardQuickSections($response->getContent());
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
        for ($i = 0; $i < 12; $i++) {
            $this->activity('Proposal '.$i);
        }
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertViewHas('recentProposalGroups', function (array $groups): bool {
                $proposals = collect($groups)->flatMap(fn (array $group) => $group['items'])->pluck('model');

                return $proposals->count() === 10
                    && $proposals->first()->title === 'Proposal 11'
                    && $proposals->last()->title === 'Proposal 2';
            })
            ->assertViewHas('recentRequestGroups', fn (array $groups): bool => collect($groups)->sum(fn (array $group) => count($group['items'])) === 10);
    }

    public function test_activity_uses_manila_day_boundary_latest_request_time_and_hides_old_items(): void
    {
        config(['app.display_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-11-06 17:30:00', 'UTC'));

        [, , $proposalYesterday] = $this->activity('Yesterday Proposal');
        [, , $proposalToday] = $this->activity('Today Proposal');
        [, , $oldProposal] = $this->activity('Old Proposal');

        DB::table('research_proposals')->where('id', $proposalYesterday->id)->update(['created_at' => '2026-11-06 15:00:00']);
        DB::table('research_proposals')->where('id', $proposalToday->id)->update(['created_at' => '2026-11-06 16:30:00']);
        DB::table('research_proposals')->where('id', $oldProposal->id)->update(['created_at' => '2026-10-30 15:00:00']);

        $declined = AdviserRequest::where('research_proposal_id', $proposalToday->id)->firstOrFail();
        DB::table('adviser_requests')->where('id', $declined->id)->update([
            'status' => 'declined',
            'requested_at' => '2026-11-03 15:00:00',
            'responded_at' => '2026-11-06 16:30:00',
            'updated_at' => '2026-11-06 16:30:00',
        ]);
        DB::table('adviser_requests')->where('research_proposal_id', $proposalYesterday->id)->update([
            'requested_at' => '2026-11-06 15:00:00',
            'responded_at' => null,
            'updated_at' => '2026-11-06 15:00:00',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $response = $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $requestGroups = $response->viewData('recentRequestGroups');
        $proposalGroups = $response->viewData('recentProposalGroups');
        $proposalTitles = collect($proposalGroups)->flatMap(fn (array $group) => $group['items'])->pluck('model.title');
        $this->assertNotContains('Old Proposal', $proposalTitles);
        $this->assertSame(['Today', 'Yesterday'], array_column($requestGroups, 'label'));
        $todayRequests = collect($requestGroups[0]['items']);
        $declinedRequest = $todayRequests->first(fn (array $item): bool => $item['model']->status === 'declined');
        $this->assertNotNull($declinedRequest);
        $this->assertSame('2026-11-07 00:30:00', $declinedRequest['at']->format('Y-m-d H:i:s'));
        $response->assertSee('Today')->assertSee('Yesterday')
            ->assertSee('12:30 AM')->assertSee('11:00 PM')
            ->assertSee('Declined')
            ->assertSee('Uploaded by '.$proposalToday->studentProfile->user->name);
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('2026-11-03 15:00:00', DB::table('adviser_requests')->where('id', $declined->id)->value('requested_at'));

    }

    public function test_dashboard_empty_states_and_sidebar_navigation_remain_while_duplicate_cards_are_gone(): void
    {
        foreach (User::ROLES as $role) {
            $user = User::factory()->create(['role' => $role]);
            if ($role === User::ROLE_STUDENT) {
                $user->studentProfile()->create(['student_number' => 'EMPTY-'.$user->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
            } elseif ($role === User::ROLE_FACULTY) {
                $user->facultyProfile()->create(['department' => 'Computing']);
            }

            $response = $this->actingAs($user)->get('/'.$role.'/dashboard')->assertOk()
                ->assertSee('Recent Requests')->assertSee('View all requests')
                ->assertSee('No adviser requests in the last 7 days.')
                ->assertSee($role === 'faculty' ? 'No adviser assignments in the last 7 days.' : 'No proposals in the last 7 days.')
                ->assertSee($role === 'faculty' ? 'View all assignments' : 'View all proposals');
            $response->assertSee('id="workspace-sidebar"', false);
            foreach ([...config('workspace_navigation.'.$role.'.primary'), ...config('workspace_navigation.'.$role.'.secondary')] as $link) {
                $response->assertSee($link['label']);
            }
            $this->assertNoDashboardQuickSections($response->getContent());
        }
    }

    public function test_view_all_popups_list_older_scoped_requests_and_assignments(): void
    {
        [, $faculty] = $this->activity('Older Own Item');
        $this->activity('Other Faculty Item');
        $old = now()->subDays(30);
        AdviserRequest::query()->where('faculty_profile_id', $faculty->facultyProfile->id)->update(['requested_at' => $old, 'updated_at' => $old]);
        AdviserAssignment::query()->where('faculty_profile_id', $faculty->facultyProfile->id)->update(['assigned_at' => $old, 'updated_at' => $old]);

        $this->actingAs($faculty)->get('/faculty/dashboard')->assertOk()
            ->assertViewHas('recentRequestGroups', [])
            ->assertViewHas('allRequestsTotal', 1)
            ->assertViewHas('allAssignmentsTotal', 1)
            ->assertSee('id="all-requests-modal"', false)
            ->assertSee('id="all-assignments-modal"', false)
            ->assertSee('Older Own Item')
            ->assertDontSee('Other Faculty Item');
    }

    private function assertNoDashboardQuickSections(string $html): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        foreach (['Your Workspace', 'Management', 'Profile', 'Profile & Skills'] as $heading) {
            $this->assertSame(
                0,
                $xpath->query('//main//*[self::h2 or self::h3 or self::h4][normalize-space(.)="'.$heading.'"]')->length,
                $heading.' quick-link section should not render in the dashboard main content.'
            );
        }
    }
}