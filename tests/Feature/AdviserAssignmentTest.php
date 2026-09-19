<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\AdviserAssignmentService;
use App\Services\AdviserRequestService;
use App\Services\AdvisoryThresholdService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\EnforceCsrfTokens;
use Tests\TestCase;

class AdviserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(): FacultyProfile
    {
        return User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
    }

    public function test_approval_requires_a_valid_csrf_token(): void
    {
        $request = $this->pending($this->faculty());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfTokens::class);
        $url = '/faculty/requests/'.$request->id.'/approve';
        $token = str_repeat('a', 40);
        $this->actingAs($request->facultyProfile->user)->withSession(['_token' => $token])->post($url)->assertStatus(419);
        $this->withSession(['_token' => $token])->post($url, ['_token' => 'wrong-token'])->assertStatus(419);
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->assertSame('pending', $request->fresh()->status);
        $this->withSession(['_token' => $token])->post($url, ['_token' => $token])->assertRedirect('/faculty/assignments')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('adviser_assignments', 1);
    }

    public function test_service_rechecks_faculty_role_after_account_changes(): void
    {
        $request = $this->pending($this->faculty());
        $actor = $request->facultyProfile->user;
        User::whereKey($actor->id)->update(['role' => User::ROLE_STUDENT]);
        // The authentication object still says Faculty; the service must read the current role.
        $this->actingAs($actor)->post('/faculty/requests/'.$request->id.'/approve')->assertForbidden();
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_failure_during_competitor_cancellation_rolls_back_every_change(): void
    {
        $faculty = $this->faculty();
        $other = $this->faculty();
        $selected = $this->pending($faculty);
        $competitor = app(AdviserRequestService::class)->submit($selected->researchProposal, $other, $selected->studentProfile->user);
        $unrelated = $this->pending($other);
        $before = AdviserRequest::orderBy('id')->get()->toArray();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'adviser_requests') && in_array('cancelled', $query->bindings, true)) {
                throw new RuntimeException('Simulated failure after competing request update');
            }
        });
        try {
            $this->actingAs($selected->facultyProfile->user)->post('/faculty/requests/'.$selected->id.'/approve')->assertSessionHasErrors('assignment');
        } finally {
            Event::forget(QueryExecuted::class);
        }
        $this->assertSame($before, AdviserRequest::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->post('/faculty/requests/'.$selected->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('approved', $selected->fresh()->status);
        $this->assertSame('cancelled', $competitor->fresh()->status);
        $this->assertSame('pending', $unrelated->fresh()->status);
    }

    public function test_database_rejects_a_second_assignment_for_one_proposal(): void
    {
        $request = $this->pending($this->faculty());
        $assignment = app(AdviserAssignmentService::class)->approve($request, $request->facultyProfile->user);
        $this->expectException(QueryException::class);
        $assignment->replicate()->save();
    }

    private function pending(FacultyProfile $faculty): AdviserRequest
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Proposal '.$student->id, 'file_path' => 'private-'.$student->id.'.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web application using Laravel.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        app(RecommendationService::class)->generate($proposal, $student);

        return app(AdviserRequestService::class)->submit($proposal, $faculty, $student);
    }

    public function test_faculty_acceptance_assigns_once_cancels_competitors_and_preserves_scores(): void
    {
        $faculty = $this->faculty();
        $other = $this->faculty();
        $request = $this->pending($faculty);
        $proposal = $request->researchProposal;
        $competitor = app(AdviserRequestService::class)->submit($proposal, $other, $request->studentProfile->user);
        $scores = $proposal->recommendations()->get()->toArray();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $url = '/faculty/requests/'.$request->id.'/approve';
        $this->actingAs($faculty->user)->get('/faculty/requests')->assertOk()->assertSee('Accept Request', false);
        $this->post($url, ['faculty_profile_id' => $other->id, 'approved_by' => 999])->assertRedirect('/faculty/assignments')->assertSessionHas('status', 'adviser-assigned');
        $assignment = AdviserAssignment::sole();
        $this->assertSame($faculty->id, $assignment->faculty_profile_id);
        $this->assertSame($faculty->user_id, $assignment->approved_by);
        $this->assertSame('active', $assignment->status);
        $this->assertNotNull($assignment->assigned_at);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->active_slot);
        $this->assertNotNull($request->fresh()->responded_at);
        $this->assertSame('cancelled', $competitor->fresh()->status);
        $this->assertNull($competitor->fresh()->active_slot);
        $this->assertNotNull($competitor->fresh()->responded_at);
        $this->assertSame(1, app(AdvisoryThresholdService::class)->forFaculties([$faculty])[$faculty->id]['load']);
        $this->assertSame($scores, $proposal->recommendations()->get()->toArray());
        $before = $assignment->toArray();
        $this->travel(1)->hour();
        $this->post($url)->assertRedirect('/faculty/assignments')->assertSessionHasNoErrors();
        $this->assertSame($before, $assignment->fresh()->toArray());
        $this->actingAs($other->user)->post('/faculty/requests/'.$competitor->id.'/approve')->assertSessionHasErrors('assignment');
        $this->assertDatabaseCount('adviser_assignments', 1);
        $this->actingAs($request->studentProfile->user)->get('/student/proposals/'.$proposal->id)->assertSee('Assigned Adviser')->assertSee($faculty->user->name);
    }

    public function test_last_slot_blocks_second_proposal_until_admin_increases_limit(): void
    {
        $faculty = $this->faculty();
        $first = $this->pending($faculty);
        $second = $this->pending($faculty);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(AdvisoryThresholdService::class)->updateLimit($faculty, $admin, 1);
        $this->actingAs($faculty->user)->post('/faculty/requests/'.$first->id.'/approve')->assertSessionHasNoErrors();
        $this->post('/faculty/requests/'.$second->id.'/approve')->assertRedirect('/faculty/requests')->assertSessionHasErrors('assignment');
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertDatabaseCount('adviser_assignments', 1);
        app(AdvisoryThresholdService::class)->updateLimit($faculty, $admin, 2);
        $this->post('/faculty/requests/'.$second->id.'/approve')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('adviser_assignments', 2);
        $this->assertSame('FULL', app(AdvisoryThresholdService::class)->forFaculties([$faculty])[$faculty->id]['status']);
    }

    public function test_only_requested_faculty_can_accept_and_admin_actions_are_removed(): void
    {
        $request = $this->pending($this->faculty());
        $url = '/faculty/requests/'.$request->id.'/approve';
        $this->post($url)->assertRedirect('/login');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        foreach ([$request->studentProfile->user, $admin] as $actor) {
            $this->actingAs($actor)->post($url)->assertForbidden();
            $this->get('/'.$actor->role.'/requests')->assertDontSee('Accept Request', false);
        }
        $this->actingAs($admin)->get('/admin/requests')->assertOk()->assertDontSee('Decline Request');
        $this->post('/admin/requests/'.$request->id.'/approve')->assertNotFound();
        $this->patch('/admin/requests/'.$request->id.'/decline')->assertNotFound();
        $this->actingAs($this->faculty()->user)->post($url)->assertNotFound();
        $this->actingAs($request->facultyProfile->user)->get('/faculty/requests')->assertOk()->assertSee('Accept Request')->assertSee('Decline Request');
        $this->assertDatabaseCount('adviser_assignments', 0);
    }

    public function test_assignment_lists_are_scoped_to_student_owner_and_assigned_faculty(): void
    {
        $first = $this->pending($this->faculty());
        $second = $this->pending($this->faculty());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $service = app(AdviserAssignmentService::class);
        $service->approve($first, $first->facultyProfile->user);
        $service->approve($second, $second->facultyProfile->user);
        $this->actingAs($first->studentProfile->user)->get('/student/assignments')->assertOk()->assertSee($first->researchProposal->title)->assertDontSee($second->researchProposal->title);
        $this->actingAs($first->facultyProfile->user)->get('/faculty/assignments')->assertOk()->assertSee($first->researchProposal->title)->assertDontSee($second->researchProposal->title)->assertSee('Assigned Students');
        $this->actingAs($admin)->get('/admin/assignments')->assertOk()->assertSee($first->researchProposal->title)->assertSee($second->researchProposal->title);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->get('/faculty/assignments')->assertSee('No adviser assignments yet.');
    }

    public function test_terminal_request_and_changed_faculty_role_cannot_be_approved(): void
    {
        $request = $this->pending($this->faculty());
        app(AdviserRequestService::class)->close($request, $request->studentProfile->user, 'cancelled');
        $this->actingAs($request->facultyProfile->user)->post('/faculty/requests/'.$request->id.'/approve')->assertSessionHasErrors('assignment');
        $other = $this->pending($this->faculty());
        $other->facultyProfile->user->forceFill(['role' => User::ROLE_STUDENT])->save();
        $this->actingAs($other->facultyProfile->user)->post('/faculty/requests/'.$other->id.'/approve')->assertForbidden();
        $this->assertDatabaseCount('adviser_assignments', 0);
    }

    public function test_existing_assignment_is_never_overwritten(): void
    {
        $request = $this->pending($this->faculty());
        $other = $this->faculty();
        $assignment = $request->researchProposal->assignment()->make();
        $assignment->forceFill(['faculty_profile_id' => $other->id, 'assigned_at' => now(), 'status' => 'active'])->save();
        $this->actingAs($request->facultyProfile->user)->post('/faculty/requests/'.$request->id.'/approve')->assertSessionHasErrors('assignment');
        $this->assertSame($other->id, $assignment->fresh()->faculty_profile_id);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_request_save_failure_rolls_back_assignment_and_retains_pending_requests(): void
    {
        $faculty = $this->faculty();
        $other = $this->faculty();
        $request = $this->pending($faculty);
        app(AdviserRequestService::class)->submit($request->researchProposal, $other, $request->studentProfile->user);
        Event::listen('eloquent.updating: '.AdviserRequest::class, function () {
            throw new RuntimeException('secret database failure');
        });
        try {
            $this->actingAs($request->facultyProfile->user)->post('/faculty/requests/'.$request->id.'/approve')->assertRedirect('/faculty/requests')->assertSessionHasErrors('assignment');
            $this->get('/faculty/requests')->assertSee('No partial approval was kept.')->assertDontSee('secret database failure');
        } finally {
            Event::forget('eloquent.updating: '.AdviserRequest::class);
        }
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->assertSame(2, AdviserRequest::where('status', 'pending')->count());
        $this->assertSame(0, $faculty->activeAssignments()->count());
    }

    public function test_assignment_names_are_escaped_and_deleted_approver_has_safe_label(): void
    {
        $request = $this->pending($this->faculty());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $assignment = app(AdviserAssignmentService::class)->approve($request, $request->facultyProfile->user);
        // Historical assignments may still reference a former Admin approver.
        $assignment->forceFill(['approved_by' => $admin->id])->save();
        $request->researchProposal->forceFill(['title' => '<script>alert(1)</script>'])->save();
        $admin->delete();
        $this->actingAs($request->studentProfile->user)->get('/student/assignments')->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)->assertSee('Former account')->assertDontSee($request->researchProposal->file_path);
        $this->assertSame(1, $request->facultyProfile->activeAssignments()->count());
    }
}
