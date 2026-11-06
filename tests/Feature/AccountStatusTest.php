<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Notifications\AdviserRequestReceivedNotification;
use App\Services\AccountStatusService;
use App\Services\AdviserRequestNotificationService;
use App\Services\AdviserRequestService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $name, string $course = 'BS Information Technology', string $section = 'A'): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_STUDENT]);
        $user->studentProfile()->create([
            'student_number' => 'S-'.$user->id,
            'course' => $course,
            'year_level' => 3,
            'section' => $section,
        ]);

        return $user;
    }

    private function faculty(string $name = 'Professor Example'): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_FACULTY]);
        $user->facultyProfile()->create(['department' => 'Computing']);

        return $user;
    }

    private function proposal(User $student): ResearchProposal
    {
        $proposal = $student->studentProfile->researchProposals()->make();
        $proposal->forceFill([
            'title' => 'Web Application',
            'file_path' => 'proposal-'.$student->id.'.pdf',
            'original_filename' => 'proposal.pdf',
            'file_type' => 'pdf',
            'status' => 'extracted',
        ])->save();

        return $proposal;
    }

    public function test_admin_can_deactivate_and_reactivate_student_and_faculty_accounts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = $this->student('Student Example');
        $faculty = $this->faculty();
        $studentToken = $student->remember_token;

        $this->actingAs($admin)->patch('/admin/accounts/'.$student->id.'/deactivate')
            ->assertRedirect('/admin/accounts');
        $student->refresh();
        $this->assertTrue($student->isDeactivated());
        $this->assertNotSame($studentToken, $student->remember_token);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);

        $this->patch('/admin/accounts/'.$faculty->id.'/deactivate')->assertRedirect('/admin/accounts');
        $this->assertTrue($faculty->fresh()->isDeactivated());
        $this->patch('/admin/accounts/'.$student->id.'/activate')->assertRedirect('/admin/accounts');
        $this->assertTrue($student->fresh()->isActive());
        $this->patch('/admin/accounts/'.$faculty->id.'/activate')->assertRedirect('/admin/accounts');
        $this->assertTrue($faculty->fresh()->isActive());
    }

    public function test_admin_accounts_cannot_be_deactivated_and_non_admins_cannot_use_status_routes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = $this->student('Student Example');

        $this->actingAs($admin)->patch('/admin/accounts/'.$admin->id.'/deactivate')->assertNotFound();
        $this->patch('/admin/accounts/'.$admin->id.'/activate')->assertNotFound();
        $this->assertSame(['changed' => 0, 'skipped' => 1], app(AccountStatusService::class)->deactivate($admin));

        $this->actingAs($student)->patch('/admin/accounts/'.$student->id.'/deactivate')->assertForbidden();
        $this->post('/admin/accounts/bulk-status', ['action' => 'deactivate', 'ids' => [$student->id]])->assertForbidden();
    }

    public function test_creating_with_deactivated_email_or_student_number_shows_activation_guidance(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $formerStudent = $this->student('Former Student');
        $formerStudent->update(['email' => 'former-student@gmail.com']);
        app(AccountStatusService::class)->deactivate($formerStudent);
        $details = [
            'name' => 'New Student',
            'email' => 'new-student@gmail.com',
            'role' => User::ROLE_STUDENT,
            'password' => 'initial-password',
            'password_confirmation' => 'initial-password',
            'student_number' => 'S-100',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ];
        $this->actingAs($admin)->from('/admin/accounts/create')
            ->post('/admin/accounts', [...$details, 'email' => $formerStudent->email])
            ->assertSessionHasErrors(['email' => 'This belongs to a deactivated account. Activate it instead.']);
        $this->post('/admin/accounts', [...$details, 'student_number' => $formerStudent->studentProfile->student_number])
            ->assertSessionHasErrors(['student_number' => 'This belongs to a deactivated account. Activate it instead.']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_bulk_status_by_ids_changes_only_student_and_faculty_accounts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = $this->student('Student Example');
        $faculty = $this->faculty();
        $alreadyInactive = $this->student('Inactive Student');
        $alreadyInactive->forceFill(['deactivated_at' => now()])->save();
        $targetIds = [$student->id, $faculty->id, $admin->id, $alreadyInactive->id];

        $this->actingAs($admin)->post('/admin/accounts/bulk-status', [
            'action' => 'deactivate',
            'ids' => $targetIds,
            'select_all_matching' => '',
        ])->assertRedirect('/admin/accounts')->assertSessionHas('status', '2 accounts deactivated, 2 were already inactive.');

        $this->assertTrue($student->fresh()->isDeactivated());
        $this->assertTrue($faculty->fresh()->isDeactivated());
        $this->assertTrue($alreadyInactive->fresh()->isDeactivated());
        $this->assertTrue($admin->fresh()->isActive());
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);

        $this->post('/admin/accounts/bulk-status', [
            'action' => 'activate',
            'ids' => [$student->id, $faculty->id],
        ])->assertRedirect('/admin/accounts');
        $this->assertTrue($student->fresh()->isActive());
        $this->assertTrue($faculty->fresh()->isActive());
    }

    public function test_select_all_matching_reapplies_filters_and_only_targets_students(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $matching = $this->student('Alice Matching', 'BS Information Technology', 'B');
        $wrongSection = $this->student('Alice Other Section', 'BS Information Technology', 'A');
        $wrongCourse = $this->student('Alice Other Course', 'BS Information Systems', 'B');
        $alreadyInactive = $this->student('Alice Inactive', 'BS Information Technology', 'B');
        $alreadyInactive->forceFill(['deactivated_at' => now()])->save();
        $sameNameFaculty = $this->faculty('Alice Matching Faculty');

        $this->actingAs($admin)->post('/admin/accounts/bulk-status', [
            'action' => 'deactivate',
            'select_all_matching' => '1',
            'type' => 'student',
            'course' => 'BS Information Technology',
            'section' => 'B',
            'q' => 'Alice',
            'status' => 'active',
        ])->assertRedirect('/admin/accounts');

        $this->assertTrue($matching->fresh()->isDeactivated());
        $this->assertTrue($wrongSection->fresh()->isActive());
        $this->assertTrue($wrongCourse->fresh()->isActive());
        $this->assertTrue($alreadyInactive->fresh()->isDeactivated());
        $this->assertTrue($sameNameFaculty->fresh()->isActive());
    }

    public function test_database_sessions_are_removed_when_an_account_is_deactivated(): void
    {
        config(['session.driver' => 'database']);
        $student = $this->student('Student Example');
        DB::table('sessions')->insert([
            'id' => 'session-to-remove',
            'user_id' => $student->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        app(AccountStatusService::class)->deactivate($student);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-remove']);
    }

    public function test_deactivated_account_cannot_log_in_and_wrong_password_keeps_generic_failure(): void
    {
        $student = $this->student('Student Example');
        app(AccountStatusService::class)->deactivate($student);

        $this->post('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account has been deactivated. Please contact the administrator.']);
        $this->assertGuest();
        $this->assertSame(1, RateLimiter::attempts($student->email.'|127.0.0.1'));

        $this->post('/login', ['email' => $student->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
        $this->assertSame(2, RateLimiter::attempts($student->email.'|127.0.0.1'));
    }

    public function test_deactivated_signed_in_user_is_logged_out_on_the_next_request(): void
    {
        $student = $this->student('Student Example');
        app(AccountStatusService::class)->deactivate($student);

        $this->actingAs($student->fresh())->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'This account has been deactivated. Please contact the administrator.']);
        $this->assertGuest();
    }

    public function test_status_filter_and_pagination_retain_account_filters(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $active = $this->student('Active Student');
        $inactive = $this->student('Inactive Student');
        $inactive->forceFill(['deactivated_at' => now()])->save();
        for ($i = 1; $i <= 21; $i++) {
            $user = $this->student('Inactive '.$i);
            $user->forceFill(['deactivated_at' => now()])->save();
        }

        $this->actingAs($admin)->get('/admin/accounts?type=student&status=deactivated&course=BS%20Information%20Technology&section=A&q=Inactive')
            ->assertOk()->assertSee('Inactive 21')->assertDontSee('Active Student');
        $this->get('/admin/accounts?type=student&status=deactivated&course=BS%20Information%20Technology&section=A&q=Inactive&page=2')
            ->assertOk()->assertSee('Inactive Student')
            ->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 22 && $accounts->currentPage() === 2);
    }

    public function test_deactivated_faculty_are_hidden_from_recommendations_and_reappear_without_regeneration(): void
    {
        $student = $this->student('Student Example');
        $proposal = $this->proposal($student);
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web application using Laravel PHP MySQL.';
        $analysis->save();
        app(\App\Services\ProposalAnalysisService::class)->analyze($proposal);

        $faculty = $this->faculty();
        $faculty->facultyProfile->expertise()->create(['expertise_area' => 'Web Development']);
        app(RecommendationService::class)->generate($proposal, $student);
        $recommendationCount = $proposal->recommendations()->count();
        app(AccountStatusService::class)->deactivate($faculty);

        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($student)->get($url)->assertOk()->assertDontSee('Professor Example');
        $this->post('/student/proposals/'.$proposal->id.'/requests/'.$faculty->facultyProfile->id)
            ->assertSessionHasErrors(['adviser_request' => 'This faculty member is currently inactive. Please choose another adviser.']);

        app(AccountStatusService::class)->activate($faculty);
        $this->get($url)->assertOk()->assertSee('Professor Example');
        $this->assertSame($recommendationCount, $proposal->recommendations()->count());
    }

    public function test_pending_request_for_inactive_faculty_is_labeled_and_remains_cancellable(): void
    {
        Notification::fake();
        $student = $this->student('Student Example');
        $proposal = $this->proposal($student);
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web application using Laravel PHP MySQL.';
        $analysis->save();
        app(\App\Services\ProposalAnalysisService::class)->analyze($proposal);
        $faculty = $this->faculty();
        $faculty->facultyProfile->expertise()->create(['expertise_area' => 'Web Development']);
        app(RecommendationService::class)->generate($proposal, $student);
        $request = app(AdviserRequestService::class)->submit($proposal, $faculty->facultyProfile, $student);
        app(AccountStatusService::class)->deactivate($faculty);

        $this->actingAs($student)->get('/student/requests')->assertOk()
            ->assertSee('Inactive')
            ->assertSee("This adviser's account is inactive. Cancel this request to choose another adviser.");
        $this->patch('/student/requests/'.$request->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_assignment_and_request_views_label_inactive_people(): void
    {
        $student = $this->student('Student Example');
        $proposal = $this->proposal($student);
        $faculty = $this->faculty();
        $request = AdviserRequest::forceCreate([
            'student_profile_id' => $student->studentProfile->id,
            'faculty_profile_id' => $faculty->facultyProfile->id,
            'research_proposal_id' => $proposal->id,
            'status' => 'pending',
            'active_slot' => 1,
            'requested_at' => now(),
        ]);
        AdviserAssignment::forceCreate([
            'research_proposal_id' => $proposal->id,
            'faculty_profile_id' => $faculty->facultyProfile->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);
        app(AccountStatusService::class)->deactivate($student);
        app(AccountStatusService::class)->deactivate($faculty);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/requests')->assertOk()->assertSee('Inactive');
        $this->get('/admin/assignments')->assertOk()->assertSee('Inactive');
        $this->assertNotNull($request->fresh());
    }

    public function test_notifications_are_not_sent_to_deactivated_recipients(): void
    {
        Notification::fake();
        $student = $this->student('Student Example');
        $proposal = $this->proposal($student);
        $faculty = $this->faculty();
        $request = AdviserRequest::forceCreate([
            'student_profile_id' => $student->studentProfile->id,
            'faculty_profile_id' => $faculty->facultyProfile->id,
            'research_proposal_id' => $proposal->id,
            'status' => 'pending',
            'active_slot' => 1,
            'requested_at' => now(),
        ]);
        app(AccountStatusService::class)->deactivate($faculty);

        app(AdviserRequestNotificationService::class)->afterCommit(
            $request,
            AdviserRequestReceivedNotification::class,
            'facultyProfile'
        );

        Notification::assertNothingSent();
    }
}
