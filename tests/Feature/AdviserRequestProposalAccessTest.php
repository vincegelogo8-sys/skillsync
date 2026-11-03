<?php

namespace Tests\Feature;

use App\Models\AdviserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdviserRequestProposalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('proposals');
        $this->clearProposalFiles();
    }

    protected function tearDown(): void
    {
        $this->clearProposalFiles();
        parent::tearDown();
    }

    private function clearProposalFiles(): void
    {
        foreach (Storage::disk('proposals')->allFiles() as $file) {
            Storage::disk('proposals')->delete($file);
        }
    }

    private function requestFor(string $fileType = 'pdf', bool $storeFile = true): AdviserRequest
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create([
            'student_number' => 'S-'.$student->id,
            'course' => 'BSIT',
            'year_level' => 3,
            'section' => 'A',
        ]);
        $proposal = $profile->researchProposals()->make();
        $path = 'proposal-'.$student->id.'.'.$fileType;
        $proposal->forceFill([
            'title' => 'Student Research Proposal',
            'file_path' => $path,
            'original_filename' => 'original-proposal.'.$fileType,
            'file_type' => $fileType,
            'status' => 'uploaded',
        ])->save();

        if ($storeFile) {
            Storage::disk('proposals')->put($path, 'original uploaded contents');
        }

        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);

        $adviserRequest = new AdviserRequest;
        $adviserRequest->forceFill([
            'student_profile_id' => $profile->id,
            'faculty_profile_id' => $facultyProfile->id,
            'research_proposal_id' => $proposal->id,
            'status' => 'pending',
            'active_slot' => 1,
            'requested_at' => now(),
        ])->save();

        return $adviserRequest;
    }

    public function test_requested_faculty_can_view_the_proposal_attached_to_the_request(): void
    {
        $adviserRequest = $this->requestFor();
        $faculty = $adviserRequest->facultyProfile->user;

        $this->actingAs($faculty)->get(route('faculty.requests.index'))
            ->assertOk()
            ->assertSee('Student Research Proposal')
            ->assertSee($adviserRequest->studentProfile->user->name)
            ->assertSee('View Proposal')
            ->assertSee(route('adviser-requests.proposal', $adviserRequest));

        $response = $this->get(route('adviser-requests.proposal', $adviserRequest))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('original uploaded contents', $response->streamedContent());
    }

    public function test_other_faculty_is_forbidden_from_viewing_the_requests_proposal(): void
    {
        $adviserRequest = $this->requestFor();
        $otherFaculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $otherFaculty->facultyProfile()->create(['department' => 'Other']);

        $this->actingAs($otherFaculty)->get(route('adviser-requests.proposal', $adviserRequest))->assertForbidden();
    }

    public function test_students_can_access_only_proposals_on_their_own_requests(): void
    {
        $adviserRequest = $this->requestFor();
        $owner = $adviserRequest->studentProfile->user;
        $otherStudent = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $otherStudent->studentProfile()->create([
            'student_number' => 'S-'.$otherStudent->id,
            'course' => 'BSIT',
            'year_level' => 2,
            'section' => 'B',
        ]);

        $this->actingAs($owner)->get(route('adviser-requests.proposal', $adviserRequest))->assertOk();
        $this->actingAs($otherStudent)->get(route('adviser-requests.proposal', $adviserRequest))->assertNotFound();
    }

    public function test_admin_can_access_a_request_proposal_and_docx_is_downloaded(): void
    {
        $adviserRequest = $this->requestFor('docx');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('adviser-requests.proposal', $adviserRequest))
            ->assertOk()
            ->assertDownload('original-proposal.docx')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_missing_proposal_file_returns_safe_not_found_response(): void
    {
        $adviserRequest = $this->requestFor('pdf', false);

        $this->actingAs($adviserRequest->facultyProfile->user)
            ->get(route('adviser-requests.proposal', $adviserRequest))
            ->assertNotFound()
            ->assertDontSee($adviserRequest->researchProposal->file_path);
    }
}
