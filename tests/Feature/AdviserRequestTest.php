<?php

namespace Tests\Feature;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\AdviserRequestService;
use App\Services\AdvisoryThresholdService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EnforceCsrfTokens;
use Tests\TestCase;

class AdviserRequestTest extends TestCase
{
    use RefreshDatabase;

    private function setupProposal(): array
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Proposal '.$student->id, 'file_path' => 'private-'.$student->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Web application using Laravel.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        app(RecommendationService::class)->generate($proposal, $student);

        return [$student, $proposal, $faculty];
    }

    private function url(ResearchProposal $proposal, FacultyProfile $faculty): string
    {
        return '/student/proposals/'.$proposal->id.'/requests/'.$faculty->id;
    }

    public function test_request_submission_and_cancellation_enforce_csrf(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfTokens::class);
        $token = str_repeat('b', 40);
        $url = $this->url($proposal, $faculty);
        $this->actingAs($student)->withSession(['_token' => $token])->post($url)->assertStatus(419);
        $this->assertDatabaseCount('adviser_requests', 0);
        $this->withSession(['_token' => $token])->post($url, ['_token' => $token])->assertSessionHasNoErrors();
        $request = AdviserRequest::sole();
        $cancel = '/student/requests/'.$request->id.'/cancel';
        $this->withSession(['_token' => $token])->patch($cancel)->assertStatus(419);
        $this->assertSame('pending', $request->fresh()->status);
        $this->withSession(['_token' => $token])->patch($cancel, ['_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_all_closed_request_history_survives_repeated_resubmission(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $service = app(AdviserRequestService::class);
        $first = $service->submit($proposal, $faculty, $student);
        $service->close($first, $student, 'cancelled');
        $second = $service->submit($proposal, $faculty, $student);
        $service->close($second, $faculty->user, 'declined');
        $third = $service->submit($proposal, $faculty, $student);
        $this->assertSame(['cancelled', 'declined', 'pending'], AdviserRequest::orderBy('id')->pluck('status')->all());
        $this->assertSame([null, null, 1], AdviserRequest::orderBy('id')->pluck('active_slot')->all());
        $this->assertNotSame($first->id, $third->id);
        $this->assertDatabaseCount('adviser_assignments', 0);
    }

    public function test_student_request_is_pending_server_owned_and_does_not_consume_capacity(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $before = $proposal->recommendations()->get()->toArray();
        $this->actingAs($student)->post($this->url($proposal, $faculty), ['status' => 'approved', 'student_profile_id' => 999, 'active_slot' => null])
            ->assertRedirect('/student/requests')->assertSessionHas('status', 'request-submitted');
        $request = AdviserRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->active_slot);
        $this->assertSame($student->studentProfile->id, $request->student_profile_id);
        $this->assertNotNull($request->requested_at);
        $this->assertNull($request->responded_at);
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->assertSame(0, app(AdvisoryThresholdService::class)->forFaculties([$faculty])[$faculty->id]['load']);
        $this->get('/student/requests')->assertOk()->assertSee('Pending')->assertSee('Cancel Request');
        $this->get('/student/proposals/'.$proposal->id.'/recommendations')->assertOk()->assertSee('Already Requested');
        $this->assertSame($before, $proposal->recommendations()->get()->toArray());
    }

    public function test_duplicate_replay_is_rejected_and_cancel_allows_new_request_with_history(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $url = $this->url($proposal, $faculty);
        $this->actingAs($student)->post($url)->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasErrors('adviser_request');
        $request = AdviserRequest::sole();
        $this->patch('/student/requests/'.$request->id.'/cancel')->assertRedirect('/student/requests');
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
        $this->assertNotNull($request->fresh()->responded_at);
        $this->post($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('adviser_requests', 2);
        $this->assertSame(1, AdviserRequest::where('status', 'pending')->count());
        $this->patch('/student/requests/'.$request->id.'/cancel')->assertSessionHasErrors('adviser_request');
    }

    public function test_full_capacity_is_rechecked_on_post_and_an_admin_increase_allows_request(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $this->actingAs($student)->get('/student/proposals/'.$proposal->id.'/recommendations')->assertOk()->assertSee('Request Adviser');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(AdvisoryThresholdService::class)->updateLimit($faculty, $admin, 0);
        $this->post($this->url($proposal, $faculty))->assertSessionHasErrors('adviser_request');
        $this->assertDatabaseCount('adviser_requests', 0);
        app(AdvisoryThresholdService::class)->updateLimit($faculty, $admin, 1);
        $this->post($this->url($proposal, $faculty))->assertSessionHasNoErrors();
    }

    public function test_only_the_owner_can_submit_and_roles_cannot_bypass_student_routes(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $url = $this->url($proposal, $faculty);
        $this->post($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->post($url)->assertNotFound();
        foreach ([User::ROLE_FACULTY, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post($url)->assertForbidden();
        }
        $this->assertDatabaseCount('adviser_requests', 0);
    }

    public function test_non_recommended_or_non_faculty_targets_are_rejected(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $proposal->recommendations()->delete();
        $this->actingAs($student)->post($this->url($proposal, $faculty))->assertSessionHasErrors('adviser_request');
        $faculty->user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->post($this->url($proposal, $faculty))->assertNotFound();
        $this->assertDatabaseCount('adviser_requests', 0);
    }

    public function test_assigned_proposal_cannot_submit_a_new_request(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $assignment = $proposal->assignment()->make();
        $assignment->forceFill(['faculty_profile_id' => $faculty->id, 'assigned_at' => now(), 'status' => 'active'])->save();
        $this->actingAs($student)->post($this->url($proposal, $faculty))->assertSessionHasErrors('adviser_request');
        $this->get('/student/proposals/'.$proposal->id.'/recommendations')->assertSee('already has an active adviser assignment');
        $this->assertDatabaseCount('adviser_requests', 0);
    }

    public function test_lists_are_scoped_and_only_recipient_can_decline(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        [$otherStudent, $otherProposal, $otherFaculty] = $this->setupProposal();
        $service = app(AdviserRequestService::class);
        $request = $service->submit($proposal, $faculty, $student);
        $service->submit($otherProposal, $otherFaculty, $otherStudent);
        $this->actingAs($student)->get('/student/requests')->assertSee($proposal->title)->assertDontSee($otherProposal->title);
        $this->actingAs($faculty->user)->get('/faculty/requests')->assertSee($proposal->title)->assertDontSee($otherProposal->title)->assertSee('Decline Request');
        $this->actingAs($otherFaculty->user)->patch('/faculty/requests/'.$request->id.'/decline')->assertNotFound();
        $this->actingAs($otherStudent)->patch('/student/requests/'.$request->id.'/cancel')->assertNotFound();
        $this->actingAs($faculty->user)->patch('/faculty/requests/'.$request->id.'/decline')->assertRedirect('/faculty/requests');
        $this->assertSame('declined', $request->fresh()->status);
        $this->assertNull($request->fresh()->active_slot);
        $replacement = $service->submit($proposal, $faculty, $student);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/requests')->assertSee($proposal->title)->assertSee($otherProposal->title);
        $this->patch('/admin/requests/'.$replacement->id.'/decline')->assertNotFound();
        $this->assertDatabaseCount('adviser_assignments', 0);
    }

    public function test_approved_request_cannot_be_repeated_or_closed_by_pending_actions(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $request = app(AdviserRequestService::class)->submit($proposal, $faculty, $student);
        $request->status = 'approved';
        $request->save();
        $this->actingAs($student)->post($this->url($proposal, $faculty))->assertSessionHasErrors('adviser_request');
        $this->patch('/student/requests/'.$request->id.'/cancel')->assertSessionHasErrors('adviser_request');
        $this->actingAs($faculty->user)->patch('/faculty/requests/'.$request->id.'/decline')->assertSessionHasErrors('adviser_request');
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_database_unique_constraint_blocks_duplicate_active_pair(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $request = app(AdviserRequestService::class)->submit($proposal, $faculty, $student);
        $this->expectException(QueryException::class);
        $request->replicate()->save();
    }

    public function test_request_text_is_escaped_and_proposal_deletion_cascades(): void
    {
        [$student, $proposal, $faculty] = $this->setupProposal();
        $proposal->title = '<script>alert(1)</script>';
        $proposal->save();
        app(AdviserRequestService::class)->submit($proposal, $faculty, $student);
        $this->actingAs($student)->get('/student/requests')->assertSee($proposal->title)->assertDontSee($proposal->title, false)->assertDontSee($proposal->file_path);
        $proposal->delete();
        $this->assertDatabaseCount('adviser_requests', 0);
        $this->get('/student/requests')->assertSee('No adviser requests yet.');
    }
}
