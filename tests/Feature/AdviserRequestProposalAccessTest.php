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

    private function requestFor(string $status = 'pending', bool $withAnalysis = true): AdviserRequest
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create([
            'student_number' => 'S-'.$student->id,
            'course' => 'BSIT',
            'year_level' => 3,
            'section' => 'A',
        ]);
        $proposal = $profile->researchProposals()->make();
        $path = 'proposal-'.$student->id.'.pdf';
        $proposal->forceFill([
            'title' => 'Student Research Proposal',
            'file_path' => $path,
            'original_filename' => 'original-proposal.pdf',
            'file_type' => 'pdf',
            'status' => 'uploaded',
        ])->save();

        if ($withAnalysis) {
            $proposal->analysis()->make()->forceFill([
                'extracted_text' => 'Extracted proposal text for adviser review.',
            ])->save();
        }

        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => 'Faculty Reader']);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);

        $adviserRequest = new AdviserRequest;
        $adviserRequest->forceFill([
            'student_profile_id' => $profile->id,
            'faculty_profile_id' => $facultyProfile->id,
            'research_proposal_id' => $proposal->id,
            'status' => $status,
            'active_slot' => 1,
            'requested_at' => now(),
        ])->save();

        return $adviserRequest;
    }

    public function test_requested_faculty_reads_proposal_text_without_receiving_the_original_file(): void
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
            ->assertSee('Student Research Proposal')
            ->assertSee($adviserRequest->studentProfile->user->name)
            ->assertSee($adviserRequest->studentProfile->student_number)
            ->assertSee('BSIT')->assertSee('Section')->assertSee('A')
            ->assertSee('Extracted proposal text for adviser review.')
            ->assertSee('Faculty Reader')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('Content-Disposition')
            ->assertDontSee('original-proposal.pdf')
            ->assertDontSee('proposal-'.$adviserRequest->studentProfile->user->id.'.pdf')
            ->assertDontSee('<iframe', false)->assertDontSee('<embed', false)->assertDontSee('<object', false);
        $this->assertNull($response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'Faculty Reader · '.now()->format('Y-m-d H:i T'),
            $response->getContent()
        );
        $this->assertStringContainsString('data-no-copy', $response->getContent());
        $this->assertStringContainsString('href="'.route('faculty.requests.index').'"', $response->getContent());
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('Printing is disabled for proposals.', $response->getContent());
    }

    public function test_pending_and_approved_request_owners_can_open_the_reader(): void
    {
        foreach (['pending', 'approved'] as $status) {
            $adviserRequest = $this->requestFor($status);
            $this->actingAs($adviserRequest->facultyProfile->user)
                ->get(route('adviser-requests.proposal', $adviserRequest))
                ->assertOk()->assertSee('Proposal Reader');
        }
    }

    public function test_closed_request_proposals_are_not_available(): void
    {
        foreach (['declined', 'cancelled'] as $status) {
            $adviserRequest = $this->requestFor($status);
            $this->actingAs($adviserRequest->facultyProfile->user)
                ->get(route('adviser-requests.proposal', $adviserRequest))
                ->assertNotFound();
        }
    }

    public function test_other_faculty_is_forbidden_from_viewing_the_requests_proposal(): void
    {
        $adviserRequest = $this->requestFor();
        $otherFaculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $otherFaculty->facultyProfile()->create(['department' => 'Other']);

        $this->actingAs($otherFaculty)->get(route('adviser-requests.proposal', $adviserRequest))->assertForbidden();
    }

    public function test_students_and_admins_cannot_access_the_adviser_request_reader(): void
    {
        $adviserRequest = $this->requestFor();
        $owner = $adviserRequest->studentProfile->user;
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($owner)->get(route('adviser-requests.proposal', $adviserRequest))->assertForbidden();
        $this->actingAs($admin)->get(route('adviser-requests.proposal', $adviserRequest))->assertForbidden();
    }

    public function test_missing_extracted_text_shows_friendly_message_without_file_details(): void
    {
        $adviserRequest = $this->requestFor('pending', false);

        $this->actingAs($adviserRequest->facultyProfile->user)
            ->get(route('adviser-requests.proposal', $adviserRequest))
            ->assertOk()
            ->assertSee('The text of this proposal is not available yet. The student can retry extraction.')
            ->assertDontSee($adviserRequest->researchProposal->file_path)
            ->assertDontSee($adviserRequest->researchProposal->original_filename);
    }

    public function test_guest_is_redirected_and_requests_link_stays_in_the_same_tab(): void
    {
        $adviserRequest = $this->requestFor();
        $this->app['auth']->forgetGuards();
        $this->get(route('adviser-requests.proposal', $adviserRequest))->assertRedirect('/login');

        $faculty = $adviserRequest->facultyProfile->user;
        $this->actingAs($faculty)->get(route('faculty.requests.index'))
            ->assertOk()->assertDontSee('target="_blank"', false);
    }
}
