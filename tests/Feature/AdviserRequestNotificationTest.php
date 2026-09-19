<?php

namespace Tests\Feature;

use App\Models\AdviserRequest;
use App\Models\User;
use App\Notifications\AdviserRequestAcceptedNotification;
use App\Notifications\AdviserRequestDeclinedNotification;
use App\Notifications\AdviserRequestReceivedNotification;
use App\Services\AdviserAssignmentService;
use App\Services\AdviserRequestService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

class AdviserRequestNotificationTest extends TestCase
{
    // Real commits make the database-before-email assertions meaningful.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('proposals');
    }

    private function scenario(): array
    {
        // Deliberately make User IDs differ from profile IDs.
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = User::factory()->create(['email' => 'student@gmail.com']);
        $profile = $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY, 'email' => 'faculty@gmail.com']);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web application research', 'file_path' => 'proposal.pdf', 'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        Storage::disk('proposals')->put('proposal.pdf', DocumentFixtures::pdf());
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Web application using Laravel and MySQL.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        app(RecommendationService::class)->generate($proposal, $student);

        return [$student, $faculty, $facultyProfile, $proposal];
    }

    public function test_new_request_notifies_only_the_correct_faculty_once(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $url = route('student.requests.store', [$proposal, $profile]);
        $this->actingAs($student)->post($url)->assertSessionHasNoErrors();
        $request = AdviserRequest::sole();
        Notification::assertSentTo($faculty, AdviserRequestReceivedNotification::class, function ($notification, $channels) use ($request, $faculty) {
            $this->assertSame(['mail'], $channels);
            $this->assertSame($request->id, $notification->request->id);
            $this->assertSame('faculty@gmail.com', $faculty->routeNotificationFor('mail', $notification));
            $mail = $notification->toMail($faculty);
            $this->assertSame('New Research Adviser Request - SKILLSYNC', $mail->subject);
            $this->assertSame(route('faculty.requests.index'), $mail->actionUrl);
            $this->assertSame([[$request->studentProfile->user->email, $request->studentProfile->user->name]], $mail->replyTo);
            $this->assertContains('Student Email: '.$request->studentProfile->user->email, $mail->introLines);
            $this->assertStringContainsString($request->researchProposal->title, $mail->render());

            return true;
        });
        $this->post($url)->assertSessionHasErrors('adviser_request');
        Notification::assertCount(1);
        $this->assertDatabaseCount('adviser_requests', 1);
    }

    public function test_two_students_can_request_the_same_adviser_with_their_own_reply_addresses(): void
    {
        [$first, $faculty, $facultyProfile, $firstProposal] = $this->scenario();
        $second = User::factory()->create(['email' => 'second.student@gmail.com']);
        $secondProfile = $second->studentProfile()->create(['student_number' => 'S-'.$second->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $secondProposal = $firstProposal->replicate();
        $secondProposal->forceFill(['student_profile_id' => $secondProfile->id, 'file_path' => 'second.pdf', 'title' => 'Second student proposal'])->save();
        $analysis = $secondProposal->analysis()->make();
        $analysis->extracted_text = 'Web application using Laravel and MySQL.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($secondProposal);
        app(RecommendationService::class)->generate($secondProposal, $second);

        foreach ([[$first, $firstProposal], [$second, $secondProposal]] as [$student, $proposal]) {
            $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($student);
            $this->post(route('student.requests.store', [$proposal, $facultyProfile]))->assertSessionHasNoErrors();
            $this->post('/logout')->assertRedirect('/');
        }

        $this->assertDatabaseCount('adviser_requests', 2);
        Notification::assertSentToTimes($faculty, AdviserRequestReceivedNotification::class, 2);
        foreach ([$first, $second] as $student) {
            Notification::assertSentTo($faculty, AdviserRequestReceivedNotification::class, function ($notification) use ($student, $faculty) {
                return $notification->request->studentProfile->user->is($student)
                    && $notification->toMail($faculty)->replyTo === [[$student->email, $student->name]];
            });
        }

        Notification::fake();
        $requests = AdviserRequest::orderBy('id')->get();
        $this->actingAs($faculty)->post(route('faculty.requests.approve', $requests[0]))->assertSessionHasNoErrors();
        $this->patch(route('faculty.requests.decline', $requests[1]))->assertSessionHasNoErrors();
        Notification::assertSentTo($first, AdviserRequestAcceptedNotification::class);
        Notification::assertSentTo($second, AdviserRequestDeclinedNotification::class);
        Notification::assertCount(2);
    }

    public function test_faculty_acceptance_notifies_student_once_and_keeps_assignment(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        $url = route('faculty.requests.approve', $request);
        $this->actingAs($faculty)->post($url)->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasNoErrors();
        Notification::assertSentTo($student, AdviserRequestAcceptedNotification::class, function ($notification) use ($student, $faculty) {
            $mail = $notification->toMail($student);
            $this->assertSame('Adviser Request Accepted - SKILLSYNC', $mail->subject);
            $this->assertSame(route('student.requests.index'), $mail->actionUrl);
            $this->assertStringContainsString($faculty->name, implode(' ', $mail->introLines));
            $this->assertSame('student@gmail.com', $student->routeNotificationFor('mail', $notification));

            return true;
        });
        Notification::assertCount(1);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertDatabaseCount('adviser_assignments', 1);
    }

    public function test_authorized_decline_notifies_student_once(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        $url = route('faculty.requests.decline', $request);
        $this->actingAs($faculty)->patch($url)->assertSessionHasNoErrors();
        $this->patch($url)->assertSessionHasErrors('adviser_request');
        Notification::assertSentTo($student, AdviserRequestDeclinedNotification::class, function ($notification) use ($student, $proposal) {
            $mail = $notification->toMail($student);
            $this->assertSame('Adviser Request Update - SKILLSYNC', $mail->subject);
            $this->assertSame(route('student.recommendations.index', $proposal), $mail->actionUrl);

            return true;
        });
        Notification::assertCount(1);
        $this->assertSame('declined', $request->fresh()->status);
    }

    public function test_unauthorized_actions_send_nothing(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        $other = User::factory()->create(['role' => 'faculty']);
        $other->facultyProfile()->create(['department' => 'Other']);
        $this->actingAs($other)->patch(route('faculty.requests.decline', $request))->assertNotFound();
        $this->post(route('faculty.requests.approve', $request))->assertNotFound();
        foreach ([$student, User::factory()->create(['role' => 'admin'])] as $actor) {
            $this->actingAs($actor)->post(route('faculty.requests.approve', $request))->assertForbidden();
            $this->patch(route('faculty.requests.decline', $request))->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->post(route('student.requests.store', [$proposal, $profile]))->assertNotFound();
        Notification::assertNothingSent();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_services_reject_admin_and_other_faculty_directly(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        $other = User::factory()->create(['role' => 'faculty']);
        $other->facultyProfile()->create(['department' => 'Other']);
        foreach ([User::factory()->create(['role' => 'admin']), $other] as $actor) {
            foreach (['approve', 'decline'] as $action) {
                try {
                    if ($action === 'approve') {
                        app(AdviserAssignmentService::class)->approve($request, $actor);
                    } else {
                        app(AdviserRequestService::class)->close($request, $actor, 'declined');
                    }
                    $this->fail('Unauthorized response was allowed.');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                    $this->assertContains($exception->getStatusCode(), [403, 404]);
                }
            }
        }
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('adviser_assignments', 0);
        Notification::assertNothingSent();
    }

    public function test_views_refreshes_recommendations_and_extraction_send_nothing(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        foreach ([$student, $faculty] as $actor) {
            $this->actingAs($actor);
            for ($i = 0; $i < 2; $i++) {
                $this->get(route($actor->role.'.requests.index'))->assertOk();
                $this->get(route($actor->role.'.dashboard'))->assertOk();
            }
        }
        $this->actingAs($student)->get(route('student.proposals.show', $proposal))->assertOk();
        $this->get(route('student.recommendations.index', $proposal))->assertOk();
        $this->post(route('student.recommendations.generate', $proposal))->assertSessionHasNoErrors();
        $this->post(route('student.proposals.refresh-extraction', $proposal))->assertSessionHasNoErrors();
        $this->assertSame('extracted', $proposal->fresh()->status);
        Notification::assertNothingSent();
    }

    public static function actions(): array
    {
        return [['submit'], ['approve'], ['decline']];
    }

    #[DataProvider('actions')]
    public function test_delivery_failure_occurs_after_commit_and_preserves_success(string $action): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = $action === 'submit' ? null : app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        $scores = $proposal->recommendations()->get()->toArray();
        $transactionLevel = null;
        Notification::shouldReceive('send')->once()->andReturnUsing(function () use (&$transactionLevel) {
            $transactionLevel = DB::transactionLevel();
            throw new RuntimeException('Simulated confidential SMTP error');
        });
        Log::spy();
        if ($action === 'submit') {
            $this->actingAs($student)->post(route('student.requests.store', [$proposal, $profile]))->assertSessionHasNoErrors();
        } elseif ($action === 'approve') {
            $this->actingAs($faculty)->post(route('faculty.requests.approve', $request))->assertSessionHasNoErrors();
            $this->assertDatabaseCount('adviser_assignments', 1);
        } else {
            $this->actingAs($faculty)->patch(route('faculty.requests.decline', $request))->assertSessionHasNoErrors();
        }
        $this->assertSame(0, $transactionLevel);
        $this->assertSame(match ($action) {
            'submit' => 'pending', 'approve' => 'approved', 'decline' => 'declined'
        }, AdviserRequest::sole()->status);
        $this->assertSame($scores, $proposal->recommendations()->get()->toArray());
        $this->assertStringNotContainsString('confidential SMTP', json_encode(session()->all()));
        Log::shouldHaveReceived('warning')->once()->with('Adviser request email could not be delivered.', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'confidential SMTP')));
    }

    #[DataProvider('actions')]
    public function test_outer_rollback_discards_notifications_and_workflow_changes(string $action): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = $action === 'submit' ? null : app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        DB::beginTransaction();
        try {
            if ($action === 'submit') {
                app(AdviserRequestService::class)->submit($proposal, $profile, $student);
            } elseif ($action === 'approve') {
                app(AdviserAssignmentService::class)->approve($request, $faculty);
            } else {
                app(AdviserRequestService::class)->close($request, $faculty, 'declined');
            }
            Notification::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('adviser_assignments', 0);
        $this->assertDatabaseCount('adviser_requests', $action === 'submit' ? 0 : 1);
        if ($request) {
            $this->assertSame('pending', $request->fresh()->status);
        }
    }

    public function test_cancellation_sends_nothing(): void
    {
        [$student, $faculty, $profile, $proposal] = $this->scenario();
        $request = app(AdviserRequestService::class)->submit($proposal, $profile, $student);
        Notification::fake();
        $this->actingAs($student)->patch(route('student.requests.cancel', $request))->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }
}
