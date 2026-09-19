<?php

namespace Tests\Feature;

use App\Exceptions\DocumentExtractionException;
use App\Models\ProposalAnalysis;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\DocumentExtractionService;
use App\Services\ResearchProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;
use ZipArchive;

class ProposalExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('proposals');
    }

    private function student(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $user->studentProfile()->create(['student_number' => 'S-'.$user->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);

        return $user;
    }

    private function uploaded(User $student, string $bytes = '', string $type = 'pdf'): ResearchProposal
    {
        return app(ResearchProposalService::class)->upload($student->studentProfile, 'Research Proposal',
            UploadedFile::fake()->createWithContent('proposal.'.$type, $bytes ?: DocumentFixtures::pdf()));
    }

    public static function types(): array
    {
        return [['pdf'], ['docx']];
    }

    #[DataProvider('types')]
    public function test_upload_extracts_real_documents_in_a_local_worker_and_persists_text(string $type): void
    {
        $student = $this->student();
        $bytes = $type === 'pdf' ? DocumentFixtures::pdf() : DocumentFixtures::docx();
        $this->actingAs($student)->post('/student/proposals', ['title' => 'Real document', 'document' => UploadedFile::fake()->createWithContent('proposal.'.$type, $bytes)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $proposal = ResearchProposal::sole();
        $this->assertSame('extracted', $proposal->status, $proposal->extraction_error ?? '');
        $this->assertNull($proposal->extraction_error);
        $this->assertNotNull($proposal->analysis->analyzed_at);
        $this->assertStringContainsString('Laravel', $proposal->analysis->extracted_text);
        $this->assertStringContainsString('C++ C# .NET Node.js', $proposal->analysis->extracted_text);
        $this->assertTrue($proposal->analysis->researchProposal->is($proposal));
        if ($type === 'docx') {
            $this->assertStringContainsString("Research Heading\nLaravel and MySQL\n", $proposal->analysis->extracted_text);
            $this->assertStringContainsString("Technology\nPurpose\nLaravel\nWeb application", $proposal->analysis->extracted_text);
            $this->assertStringContainsString('café', $proposal->analysis->extracted_text);
            $this->assertStringContainsString('List item for research', $proposal->analysis->extracted_text);
        }
        $this->get('/student/proposals/'.$proposal->id)->assertOk()->assertSee('Extracted Document Text')->assertSee('Laravel')->assertDontSee($proposal->file_path);
        Storage::disk('proposals')->assertExists($proposal->file_path);
    }

    public function test_existing_proposals_extract_once_and_pages_only_read_saved_text(): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student);
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Objectives: Saved research text');
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($student)->get($url)->assertSee('Extract Document Text');
        $this->assertDatabaseCount('proposal_analyses', 0);
        $this->post($url.'/extract')->assertRedirect($url);
        $before = $proposal->fresh()->analysis->toArray();
        $this->get($url)->assertSee('Saved research text');
        $this->get($url)->assertSee('Saved research text');
        $this->post($url.'/extract')->assertRedirect($url);
        $this->assertSame($before, $proposal->fresh()->analysis->toArray());
        $this->assertDatabaseCount('proposal_analyses', 1);
    }

    #[DataProvider('types')]
    public function test_documents_without_readable_text_have_clear_errors_and_preserve_uploads(string $type): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student, $type === 'pdf' ? DocumentFixtures::pdf([]) : DocumentFixtures::docx(true), $type);
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($student)->post($url.'/extract')->assertRedirect($url);
        $this->assertSame('extraction_failed', $proposal->fresh()->status);
        $this->assertDatabaseCount('proposal_analyses', 0);
        $this->get($url)->assertOk()->assertSee($type === 'pdf' ? DocumentExtractionService::EMPTY_PDF : 'Unable to extract readable text from this DOCX file.')
            ->assertSee('Retry Text Extraction')->assertDontSee($proposal->file_path);
        $this->get($url.'/download')->assertDownload('proposal.'.$type);
    }

    public function test_failed_extraction_can_be_retried_without_reuploading_and_error_is_cleared(): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student);
        $reader = $this->mock(DocumentExtractionRunner::class);
        $reader->shouldReceive('extract')->once()->ordered()->andThrow(new DocumentExtractionException('Temporary processing failure.'));
        $reader->shouldReceive('extract')->once()->ordered()->andReturn('Objectives: Recovered text');
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($student)->post($url.'/extract')->assertRedirect($url);
        $this->get($url)->assertSee('Temporary processing failure.');
        $this->post($url.'/extract')->assertRedirect($url);
        $this->assertNull($proposal->fresh()->extraction_error);
        $this->assertSame('extracted', $proposal->fresh()->status);
        $this->get($url)->assertSee('Recovered text')->assertDontSee('Temporary processing failure.');
    }

    public function test_admin_can_extract_but_guests_other_students_and_faculty_cannot(): void
    {
        $owner = $this->student();
        $proposal = $this->uploaded($owner);
        $studentUrl = '/student/proposals/'.$proposal->id.'/extract';
        $adminUrl = '/admin/proposals/'.$proposal->id.'/extract';
        $this->post($studentUrl)->assertRedirect('/login');
        $this->post($adminUrl)->assertRedirect('/login');
        $this->actingAs($this->student())->post($studentUrl)->assertNotFound();
        $this->post($adminUrl)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->post($studentUrl)->assertForbidden();
        $this->post($adminUrl)->assertForbidden();
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Objectives: Admin extracted research');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->post($adminUrl)->assertRedirect('/admin/proposals/'.$proposal->id);
        $this->get('/admin/proposals/'.$proposal->id)->assertSee('Admin extracted research');
    }

    public function test_missing_or_corrupt_files_fail_without_exposing_internal_paths(): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student);
        Storage::disk('proposals')->delete($proposal->file_path);
        $this->actingAs($student)->post('/student/proposals/'.$proposal->id.'/extract')->assertRedirect();
        $this->assertSame('extraction_failed', $proposal->fresh()->status);
        $this->get('/student/proposals/'.$proposal->id)->assertSee('The uploaded document is unavailable.')->assertDontSee($proposal->file_path);
        Storage::disk('proposals')->put($proposal->file_path, '%PDF-1.4 broken data');
        $this->post('/student/proposals/'.$proposal->id.'/extract')->assertRedirect();
        $this->assertStringContainsString('Unable to read this PDF.', $proposal->fresh()->extraction_error);
        $this->assertDatabaseCount('proposal_analyses', 0);
    }

    public function test_xml_entities_and_external_document_content_are_rejected(): void
    {
        foreach (['entity', 'external'] as $kind) {
            Storage::disk('proposals')->put('unsafe.docx', DocumentFixtures::docx());
            $path = Storage::disk('proposals')->path('unsafe.docx');
            $zip = new ZipArchive;
            $zip->open($path);
            if ($kind === 'entity') {
                $zip->addFromString('word/document.xml', '<!DOCTYPE document [<!ENTITY test SYSTEM "file:///not-readable">]><document>&test;</document>');
            } else {
                $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="unsafe" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" TargetMode="External" Target="https://example.invalid/image.png"/></Relationships>');
            }
            $zip->close();
            try {
                app(DocumentExtractionService::class)->extract($path, 'docx');
                $this->fail('Unsafe document must be rejected.');
            } catch (DocumentExtractionException $exception) {
                $this->assertStringContainsString($kind === 'entity' ? 'unsupported or malformed XML' : 'external content', $exception->getMessage());
            }
        }
    }

    public function test_failed_database_write_rolls_back_analysis_and_preserves_original_file(): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student);
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Research text');
        Event::listen('eloquent.created: '.ProposalAnalysis::class, function () {
            throw new RuntimeException('Simulated analysis insert failure');
        });
        try {
            $this->actingAs($student)->from('/student/proposals/'.$proposal->id)->post('/student/proposals/'.$proposal->id.'/extract')
                ->assertSessionHasErrors('extraction');
        } finally {
            Event::forget('eloquent.created: '.ProposalAnalysis::class);
        }
        $this->assertSame('uploaded', $proposal->fresh()->status);
        $this->assertDatabaseCount('proposal_analyses', 0);
        Storage::disk('proposals')->assertExists($proposal->file_path);
    }

    public function test_extracted_text_is_escaped_and_cascades_on_account_deletion(): void
    {
        $student = $this->student();
        $proposal = $this->uploaded($student);
        $text = '<script>alert("untrusted")</script>';
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn($text);
        $this->actingAs($student)->post('/student/proposals/'.$proposal->id.'/extract')->assertRedirect();
        $this->get('/student/proposals/'.$proposal->id)->assertSee($text)->assertDontSee($text, false);
        $this->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertDatabaseCount('proposal_analyses', 0);
        Storage::disk('proposals')->assertMissing($proposal->file_path);
    }

    public function test_oversized_docx_parts_are_rejected_before_parsing(): void
    {
        Storage::disk('proposals')->put('oversized.docx', DocumentFixtures::docx());
        $path = Storage::disk('proposals')->path('oversized.docx');
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', '<document>'.str_repeat('a', 11 * 1024 * 1024).'</document>');
        $zip->close();
        $this->expectException(DocumentExtractionException::class);
        $this->expectExceptionMessage('oversized document part');
        app(DocumentExtractionService::class)->extract($path, 'docx');
    }

    public function test_upload_keeps_the_saved_document_accessible_if_extraction_storage_fails(): void
    {
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Research text');
        Event::listen('eloquent.created: '.ProposalAnalysis::class, function () {
            throw new RuntimeException('Simulated storage failure');
        });
        try {
            $this->actingAs($this->student())->post('/student/proposals', [
                'title' => 'Preserved upload', 'document' => UploadedFile::fake()->createWithContent('proposal.pdf', DocumentFixtures::pdf()),
            ])->assertSessionHasErrors('extraction')->assertRedirect('/student/proposals/1');
        } finally {
            Event::forget('eloquent.created: '.ProposalAnalysis::class);
        }
        $this->get('/student/proposals/1')->assertOk()->assertSee('Document text could not be saved.');
        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertDatabaseCount('proposal_analyses', 0);
        Storage::disk('proposals')->assertExists(ResearchProposal::sole()->file_path);
    }
}
