<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrossStudentDuplicateProposalTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('proposals');
        $this->mock(ProposalExtractionService::class)
            ->shouldReceive('extract')->andReturnUsing(fn ($proposal) => $proposal);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    private function student(): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);

        return $student;
    }

    /** A valid PDF whose bytes depend only on $variant, so equal variants hash identically. */
    private function pdf(int $variant): UploadedFile
    {
        $resource = tmpfile();
        fwrite($resource, "%PDF-1.4\n% Fixture {$variant}\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->temporaryFiles[] = $resource;

        return new UploadedFile(stream_get_meta_data($resource)['uri'], 'proposal.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
    }

    public function test_a_second_student_cannot_upload_the_same_document(): void
    {
        $carlo = $this->student();
        $michael = $this->student();

        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Soil Analysis App', 'document' => $this->pdf(1)])
            ->assertSessionHasNoErrors();

        $this->actingAs($michael)->post('/student/proposals', ['title' => 'A Different Title', 'document' => $this->pdf(1)])
            ->assertSessionHasErrors(['document' => ResearchProposal::OTHER_STUDENT_DOCUMENT_MESSAGE]);

        $this->assertSame(0, $michael->studentProfile->researchProposals()->count());
        $this->assertSame(1, ResearchProposal::count());
    }

    public function test_a_second_student_cannot_reuse_the_same_title(): void
    {
        $carlo = $this->student();
        $michael = $this->student();

        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Soil Analysis App', 'document' => $this->pdf(1)])
            ->assertSessionHasNoErrors();

        // Different file, same title (case and punctuation do not matter).
        $this->actingAs($michael)->post('/student/proposals', ['title' => 'soil analysis app!', 'document' => $this->pdf(2)])
            ->assertSessionHasErrors(['title' => ResearchProposal::OTHER_STUDENT_TITLE_MESSAGE]);

        $this->assertSame(0, $michael->studentProfile->researchProposals()->count());
    }

    public function test_the_error_does_not_reveal_who_submitted_the_proposal(): void
    {
        $carlo = $this->student();
        $michael = $this->student();

        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Soil Analysis App', 'document' => $this->pdf(1)]);

        $response = $this->actingAs($michael)->from('/student/proposals/create')
            ->post('/student/proposals', ['title' => 'Another Title', 'document' => $this->pdf(1)]);

        $response->assertSessionHasErrors('document');
        $this->assertStringNotContainsString($carlo->name, json_encode(session('errors')->getBag('default')->all()));
        $this->assertStringNotContainsString('Soil Analysis App', json_encode(session('errors')->getBag('default')->all()));
    }

    public function test_different_students_can_upload_different_proposals(): void
    {
        $carlo = $this->student();
        $michael = $this->student();

        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Soil Analysis App', 'document' => $this->pdf(1)])
            ->assertSessionHasNoErrors();
        $this->actingAs($michael)->post('/student/proposals', ['title' => 'Library Booking System', 'document' => $this->pdf(2)])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ResearchProposal::count());
    }

    public function test_the_original_student_keeps_the_existing_own_duplicate_message(): void
    {
        $carlo = $this->student();

        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Soil Analysis App', 'document' => $this->pdf(1)]);
        $this->actingAs($carlo)->post('/student/proposals', ['title' => 'Another Title', 'document' => $this->pdf(1)])
            ->assertSessionHasErrors('document');

        $this->assertStringContainsString('You already uploaded this document', session('errors')->first('document'));
    }
}