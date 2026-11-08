<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadBlockedNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'You cannot upload another proposal because your proposal already has an adviser assignment.';

    private function studentWithProposal(): array
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $user->studentProfile()->create(['student_number' => 'U-'.$user->id, 'course' => 'BS Information Technology', 'year_level' => 3, 'section' => 'A']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Locked Study', 'file_path' => 'u-'.$user->id.'.pdf', 'original_filename' => 'p.pdf', 'file_type' => 'pdf', 'status' => 'uploaded'])->save();

        return [$user, $profile, $proposal];
    }

    private function assignAdviser($proposal): void
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);
        $assignment = new AdviserAssignment;
        $assignment->forceFill(['faculty_profile_id' => $facultyProfile->id, 'research_proposal_id' => $proposal->id, 'status' => 'active', 'assigned_at' => now()])->save();
    }

    public function test_clicking_upload_with_an_adviser_shows_the_notice_and_blocks_the_form(): void
    {
        [$user, , $proposal] = $this->studentWithProposal();
        $this->assignAdviser($proposal);

        $this->actingAs($user)->get('/student/proposals/create')->assertRedirect(route('student.proposals.index'));

        $this->actingAs($user)->followingRedirects()->get('/student/proposals/create')
            ->assertOk()->assertSee(self::MESSAGE)->assertSee('Upload Proposal');
    }

    public function test_posting_an_upload_with_an_adviser_is_blocked_and_creates_nothing(): void
    {
        [$user, $profile, $proposal] = $this->studentWithProposal();
        $this->assignAdviser($proposal);

        $this->actingAs($user)->post('/student/proposals', ['title' => 'Second Try'])
            ->assertRedirect(route('student.proposals.index'))
            ->assertSessionHasErrors('upload');
        $this->assertSame(1, $profile->researchProposals()->count());
    }

    public function test_student_without_an_adviser_can_still_open_the_upload_form(): void
    {
        [$user] = $this->studentWithProposal();

        $this->actingAs($user)->get('/student/proposals/create')->assertOk();
        $this->actingAs($user)->get('/student/proposals')->assertOk()->assertDontSee(self::MESSAGE);
    }
}