<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\AdviserRequestService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdviserRequestClosureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(): FacultyProfile
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);

        return $faculty;
    }

    /** A student with one proposal who has requested all three advisers. */
    private function studentWithRequests(FacultyProfile ...$advisers): array
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

        $requests = [];
        foreach ($advisers as $adviser) {
            $requests[] = app(AdviserRequestService::class)->submit($proposal, $adviser, $student);
        }

        return [$student, $requests];
    }

    public function test_when_one_adviser_accepts_the_others_are_closed_hidden_from_the_student_and_explained_to_them(): void
    {
        [$first, $second, $third] = [$this->faculty(), $this->faculty(), $this->faculty()];
        [$student, $requests] = $this->studentWithRequests($first, $second, $third);

        $this->actingAs($first->user)->post('/faculty/requests/'.$requests[0]->id.'/approve')->assertSessionHasNoErrors();

        // Student: only the accepted request remains.
        $this->actingAs($student)->get('/student/requests')
            ->assertOk()
            ->assertSee($first->user->name)
            ->assertDontSee($second->user->name)
            ->assertDontSee($third->user->name)
            ->assertDontSee('Delete Request');

        // The other advisers still see their request, closed, and who accepted the student.
        foreach ([$second, $third] as $other) {
            $this->actingAs($other->user)->get('/faculty/requests')
                ->assertOk()
                ->assertSee('This request was closed because the student has already been accepted by '.$first->user->name)
                ->assertDontSee('Accept Request');
        }

        // They can no longer accept it.
        $this->actingAs($second->user)->post('/faculty/requests/'.$requests[1]->id.'/approve')->assertSessionHasErrors('assignment');
        $this->assertSame('cancelled', $requests[1]->fresh()->status);
        $this->assertSame('cancelled', $requests[2]->fresh()->status);

        // The accepting adviser does not see the "closed" notice on their own request.
        $this->actingAs($first->user)->get('/faculty/requests')->assertOk()->assertDontSee('This request was closed because');
    }

    public function test_cancelled_requests_stay_visible_to_the_student_while_nobody_has_accepted(): void
    {
        [$first, $second] = [$this->faculty(), $this->faculty()];
        [$student, $requests] = $this->studentWithRequests($first, $second);

        $this->actingAs($student)->patch('/student/requests/'.$requests[1]->id.'/cancel')->assertSessionHasNoErrors();

        $this->get('/student/requests')->assertOk()->assertSee($second->user->name)->assertSee('Delete Request');
    }
}