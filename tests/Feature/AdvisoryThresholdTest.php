<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdvisoryThresholdService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvisoryThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(): FacultyProfile
    {
        return User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
    }

    private function proposal(): ResearchProposal
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $user->studentProfile()->create(['student_number' => 'S-'.$user->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Application', 'file_path' => 'private-'.$user->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();

        return $proposal;
    }

    private function assignment(FacultyProfile $faculty, User $admin, string $status = 'active'): AdviserAssignment
    {
        $assignment = $faculty->assignments()->make();
        $assignment->forceFill(['research_proposal_id' => $this->proposal()->id, 'approved_by' => $admin->id, 'assigned_at' => now(), 'status' => $status])->save();

        return $assignment;
    }

    public static function capacityCases(): array
    {
        return [[0, 5, false, 5], [4, 5, false, 1], [5, 5, true, 0], [5, 6, false, 1], [6, 5, true, 0], [0, 0, true, 0]];
    }

    #[DataProvider('capacityCases')]
    public function test_capacity_boundaries(int $load, int $limit, bool $full, int $remaining): void
    {
        $result = app(AdvisoryThresholdService::class)->calculate($load, $limit);
        $this->assertSame($full, $result['is_full']);
        $this->assertSame($remaining, $result['remaining']);
        $this->assertSame($full ? 'FULL' : 'AVAILABLE', $result['status']);
    }

    public function test_load_counts_only_active_assignments_and_updates_after_status_change_or_deletion(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $faculty = $this->faculty();
        $other = $this->faculty();
        $active = $this->assignment($faculty, $admin);
        $this->assignment($faculty, $admin, 'completed');
        $this->assignment($faculty, $admin, 'cancelled');
        $this->assignment($other, $admin);
        $service = app(AdvisoryThresholdService::class);
        $this->assertSame(1, $service->forFaculties([$faculty])[$faculty->id]['load']);
        $this->assertTrue($active->researchProposal->assignment->is($active));
        $this->assertTrue($active->approver->is($admin));
        $active->status = 'completed';
        $active->save();
        $this->assertSame(0, $service->forFaculties([$faculty])[$faculty->id]['load']);
        $next = $this->assignment($faculty, $admin);
        $admin->delete();
        $this->assertNull($next->fresh()->approved_by);
        $this->assertSame(1, $service->forFaculties([$faculty])[$faculty->id]['load']);
        $next->researchProposal->delete();
        $this->assertSame(0, $service->forFaculties([$faculty])[$faculty->id]['load']);
        $this->assertSame([], $service->forFaculties([]));
    }

    public function test_admin_increase_restores_availability_without_regenerating_or_changing_rank(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $faculty = $this->faculty();
        for ($i = 0; $i < 5; $i++) {
            $this->assignment($faculty, $admin);
        }
        $proposal = $this->proposal();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web application using PHP.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        $before = app(RecommendationService::class)->generate($proposal, $admin)->sole()->toArray();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->get($url)->assertOk()->assertSee('5 / 5')->assertSee('FULL')->assertSee('disabled', false)->assertSee('Request Adviser');
        $edit = '/admin/faculty/'.$faculty->id.'/advisory-limit';
        $this->actingAs($admin)->get('/admin/advisory-limits')->assertOk()->assertSee($faculty->user->name)->assertSee('5 / 5');
        $this->get($edit)->assertOk()->assertSee('Advisory limit');
        $this->patch($edit, ['advisory_limit' => 6, 'current_advisee_count' => 0, 'department' => 'Tampered'])->assertRedirect($edit)->assertSessionHasNoErrors();
        $this->get($edit)->assertSee('5 / 6')->assertSee('AVAILABLE');
        $this->assertSame('Computing', $faculty->fresh()->department);
        $this->actingAs($proposal->studentProfile->user)->get($url)->assertOk()->assertSee('5 / 6')->assertSee('AVAILABLE')->assertDontSee('This adviser has reached their advisory limit.');
        $this->assertSame($before, $proposal->recommendations()->sole()->toArray());
        $this->assertDatabaseCount('adviser_assignments', 5);
        $this->actingAs($admin)->patch($edit, ['advisory_limit' => 2])->assertSessionHasNoErrors();
        $this->get($edit)->assertSee('5 / 2')->assertSee('FULL');
        $this->assertDatabaseCount('adviser_assignments', 5);
        $this->assertSame(2, $faculty->fresh()->advisory_limit);
    }

    public static function invalidLimits(): array
    {
        return [[null], [-1], [65536], [1.5], ['invalid'], [[]]];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_are_rejected(mixed $value): void
    {
        $faculty = $this->faculty();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->patch('/admin/faculty/'.$faculty->id.'/advisory-limit', ['advisory_limit' => $value])->assertSessionHasErrors('advisory_limit');
        $this->assertSame(5, $faculty->fresh()->advisory_limit);
    }

    public function test_zero_and_maximum_limits_are_supported(): void
    {
        $faculty = $this->faculty();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $url = '/admin/faculty/'.$faculty->id.'/advisory-limit';
        $this->patch($url, ['advisory_limit' => 0])->assertSessionHasNoErrors();
        $this->get($url)->assertSee('0 / 0')->assertSee('FULL');
        $this->patch($url, ['advisory_limit' => 65535])->assertSessionHasNoErrors();
        $this->assertSame(65535, $faculty->fresh()->advisory_limit);
    }

    public function test_only_admin_can_view_and_change_limits(): void
    {
        $faculty = $this->faculty();
        $url = '/admin/faculty/'.$faculty->id.'/advisory-limit';
        $this->get('/admin/advisory-limits')->assertRedirect('/login');
        $this->patch($url, ['advisory_limit' => 9])->assertRedirect('/login');
        foreach ([User::ROLE_STUDENT, User::ROLE_FACULTY] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/admin/advisory-limits')->assertForbidden();
            $this->get($url)->assertForbidden();
            $this->patch($url, ['advisory_limit' => 9])->assertForbidden();
        }
        $this->assertSame(5, $faculty->fresh()->advisory_limit);
        $this->assertDatabaseCount('adviser_assignments', 0);
    }

    public function test_non_faculty_profile_is_not_editable_and_admin_empty_state_is_clear(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $profile = $admin->facultyProfile()->create(['department' => 'Computing']);
        $this->actingAs($admin)->get('/admin/advisory-limits')->assertOk()->assertSee('No faculty profiles available.');
        $this->get('/admin/faculty/'.$profile->id.'/advisory-limit')->assertNotFound();
        $this->patch('/admin/faculty/'.$profile->id.'/advisory-limit', ['advisory_limit' => 9])->assertNotFound();
    }
}
