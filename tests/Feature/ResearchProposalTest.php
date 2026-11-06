<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\ProposalExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ResearchProposalTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];
    private int $fixtureSequence = 0;
    private int $uploadSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('proposals');
        // Upload/storage tests stay independent of the separately tested parsers.
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

    private function file(string $name, string $content, string $clientMime = 'application/octet-stream', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $resource = tmpfile();
        fwrite($resource, $content);
        $this->temporaryFiles[] = $resource;

        // Use real fileinfo detection rather than Laravel's fake MIME implementation.
        return new UploadedFile(stream_get_meta_data($resource)['uri'], $name, $clientMime, $error, true);
    }

    private function pdf(string $name = 'proposal.pdf', ?int $bytes = null): UploadedFile
    {
        $content = "%PDF-1.4\n% Fixture ".(++$this->fixtureSequence)."\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        return $this->file($name, $bytes ? str_pad($content, $bytes, ' ') : $content, 'application/pdf');
    }

    private function docx(string $name = 'proposal.docx', string $kind = 'valid'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'proposal-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            if ($kind === 'plain_zip') {
                $zip->addFromString('readme.txt', 'This is not a Word document.');
            } else {
                $mainType = $kind === 'macro' ? 'application/vnd.ms-word.document.macroEnabled.main+xml' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
                $types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="'.$mainType.'"/></Types>';
                if ($kind === 'doctype') {
                    $types = '<!DOCTYPE Types [<!ENTITY example "bad">]>'.$types;
                }
                $zip->addFromString('[Content_Types].xml', $types);
                $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
                if ($kind !== 'missing_document') {
                    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Research proposal</w:t></w:r></w:p></w:body></w:document>');
                }
            }
            $zip->close();

            return $this->file($name, file_get_contents($path), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        } finally {
            unlink($path);
        }
    }

    private function upload(User $student, ?UploadedFile $file = null, array $extra = []): ResearchProposal
    {
        $sequence = ++$this->uploadSequence;
        $this->actingAs($student)->post('/student/proposals', array_replace([
            'title' => $sequence === 1 ? 'Research Proposal Example' : 'Research Proposal Example '.$sequence,
            'document' => $file ?? $this->pdf(),
        ], $extra))->assertSessionHasNoErrors()->assertRedirect();

        return $student->studentProfile->researchProposals()->latest('id')->firstOrFail();
    }

    public static function validTypes(): array
    {
        return [['pdf'], ['docx']];
    }

    #[DataProvider('validTypes')]
    public function test_students_can_upload_list_view_and_download_valid_documents(string $type): void
    {
        $student = $this->student();
        $file = $type === 'pdf' ? $this->pdf() : $this->docx();
        $contents = file_get_contents($file->getRealPath());
        $this->actingAs($student)->get('/student/proposals')->assertOk()->assertSee('No proposals uploaded.');
        $this->get('/student/proposals/create')->assertOk()->assertSee('multipart/form-data', false);
        $proposal = $this->upload($student, $file);
        $this->assertSame($type, $proposal->file_type);
        $this->assertSame('uploaded', $proposal->status);
        $this->assertSame($student->studentProfile->id, $proposal->student_profile_id);
        $this->assertTrue($proposal->studentProfile->is($student->studentProfile));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}\\.'.$type.'$/', $proposal->file_path);
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $this->assertSame($contents, Storage::disk('proposals')->get($proposal->file_path));
        $this->assertArrayNotHasKey('file_path', $proposal->toArray());
        $this->get('/student/proposals/'.$proposal->id)->assertOk()->assertSee('Proposal uploaded successfully.')
            ->assertSee('proposal.'.$type)->assertDontSee($proposal->file_path)->assertDontSee('storage/app/private');
        $this->get('/student/proposals')->assertSee('Research Proposal Example')->assertDontSee($proposal->file_path);
        $response = $this->get('/student/proposals/'.$proposal->id.'/download')->assertOk()->assertDownload('proposal.'.$type)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($contents, $response->streamedContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/storage/'.$proposal->file_path)->assertForbidden();
        $this->get('/proposals/'.$proposal->file_path)->assertNotFound();
    }

    public function test_ten_megabytes_is_accepted_and_one_byte_over_is_rejected(): void
    {
        $student = $this->student();
        $this->upload($student, $this->pdf('maximum.pdf', 10 * 1024 * 1024));
        $this->post('/student/proposals', ['title' => 'Too large', 'document' => $this->pdf('oversize.pdf', 10 * 1024 * 1024 + 1)])
            ->assertSessionHasErrors('document');
        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
    }

    public static function invalidTypes(): array
    {
        return [['text_pdf'], ['pdf_docx'], ['docx_pdf'], ['plain_zip'], ['macro'], ['doctype'], ['missing_document'], ['old_doc'], ['empty'], ['executable'], ['upload_error']];
    }

    #[DataProvider('invalidTypes')]
    public function test_invalid_and_mislabeled_files_are_rejected(string $kind): void
    {
        $file = match ($kind) {
            'text_pdf' => $this->file('forged.pdf', 'Plain text pretending to be PDF', 'application/pdf'),
            'pdf_docx' => $this->pdf('wrong.docx'),
            'docx_pdf' => $this->docx('wrong.pdf'),
            'plain_zip', 'macro', 'doctype', 'missing_document' => $this->docx('invalid.docx', $kind),
            'old_doc' => $this->pdf('legacy.doc'),
            'empty' => $this->file('empty.pdf', ''),
            'executable' => $this->file('proposal.pdf.php', '<?php echo "untrusted";'),
            'upload_error' => $this->file('failed.pdf', '', 'application/pdf', UPLOAD_ERR_INI_SIZE),
        };
        $this->actingAs($this->student())->from('/student/proposals/create')->post('/student/proposals', ['title' => 'Rejected', 'document' => $file])
            ->assertSessionHasErrors('document')->assertRedirect('/student/proposals/create');
        $this->get('/student/proposals/create')->assertOk()->assertSee('Rejected');
        $this->assertDatabaseCount('research_proposals', 0);
        $this->assertCount(0, Storage::disk('proposals')->allFiles());
    }

    public function test_missing_fields_malformed_title_and_long_filename_are_rejected(): void
    {
        $this->actingAs($this->student())->post('/student/proposals', [])->assertSessionHasErrors(['title', 'document']);
        foreach (['', str_repeat('x', 256), ['invalid']] as $title) {
            $this->post('/student/proposals', ['title' => $title, 'document' => $this->pdf()])->assertSessionHasErrors('title');
        }
        $this->post('/student/proposals', ['title' => 'Valid title', 'document' => $this->pdf(str_repeat('x', 256).'.pdf')])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('research_proposals', 0);
        $this->assertCount(0, Storage::disk('proposals')->allFiles());
    }

    public function test_student_profile_is_required_without_creating_blank_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get('/student/proposals')->assertRedirect('/student/profile');
        $this->get('/student/proposals/create')->assertRedirect('/student/profile');
        $this->post('/student/proposals', ['title' => 'Title', 'document' => $this->pdf()])->assertRedirect('/student/profile');
        $this->get('/student/profile')->assertSee('Save your student profile before uploading a research proposal.');
        $this->assertDatabaseCount('student_profiles', 0);
        $this->assertDatabaseCount('research_proposals', 0);
        $this->assertCount(0, Storage::disk('proposals')->allFiles());
    }

    public function test_ownership_status_and_paths_cannot_be_forged_and_duplicate_names_do_not_overwrite_files(): void
    {
        $student = $this->student();
        $other = $this->student();
        $proposal = $this->upload($student, $this->pdf(), [
            'student_profile_id' => $other->studentProfile->id, 'user_id' => $other->id,
            'file_path' => '../../public/evil.php', 'original_filename' => 'forged.php', 'file_type' => 'php', 'status' => 'approved',
        ]);
        $second = $this->upload($student, $this->pdf(), ['title' => 'A Different Research Proposal']);
        $this->assertNotSame($proposal->file_path, $second->file_path);
        $this->assertSame('uploaded', $proposal->status);
        $this->assertSame('pdf', $proposal->file_type);
        $this->assertSame('proposal.pdf', $proposal->original_filename);
        $this->assertSame($student->studentProfile->id, $proposal->student_profile_id);
        $this->assertCount(2, Storage::disk('proposals')->allFiles());
        $this->assertSame(0, $other->studentProfile->researchProposals()->count());
    }

    public function test_other_students_cannot_list_view_or_download_a_proposal(): void
    {
        $proposal = $this->upload($this->student(), null, ['title' => 'Private research title']);
        $this->actingAs($this->student())->get('/student/proposals')->assertDontSee('Private research title');
        $this->get('/student/proposals/'.$proposal->id)->assertNotFound();
        $this->get('/student/proposals/'.$proposal->id.'/download')->assertNotFound();
        $this->get('/student/proposals/999999')->assertNotFound();
    }

    public function test_admin_can_review_and_download_all_student_proposals(): void
    {
        $student = $this->student();
        $proposal = $this->upload($student);
        $this->upload($this->student(), null, ['title' => 'Second proposal']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/proposals')->assertOk()
            ->assertSee('Second proposal')->assertSee($student->name)->assertDontSee(route('student.proposals.create'));
        $this->get('/admin/proposals/'.$proposal->id)->assertOk()->assertSee($student->studentProfile->student_number)->assertDontSee($proposal->file_path);
        $this->get('/admin/proposals/'.$proposal->id.'/download')->assertDownload('proposal.pdf');
        $this->get('/admin/dashboard')->assertSee(route('admin.proposals.index'));
    }

    public function test_guests_and_wrong_roles_cannot_use_proposal_routes(): void
    {
        $student = $this->student();
        $proposal = $this->upload($student);
        $this->app['auth']->forgetGuards();
        $studentUrls = ['/student/proposals', '/student/proposals/create', '/student/proposals/'.$proposal->id, '/student/proposals/'.$proposal->id.'/download'];
        $adminUrls = ['/admin/proposals', '/admin/proposals/'.$proposal->id, '/admin/proposals/'.$proposal->id.'/download'];
        foreach (array_merge($studentUrls, $adminUrls) as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/student/proposals')->assertRedirect('/login');
        foreach ([User::ROLE_ADMIN, User::ROLE_FACULTY] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($studentUrls as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/student/proposals')->assertForbidden();
        }
        foreach ([$student, User::factory()->create(['role' => User::ROLE_FACULTY])] as $user) {
            $this->actingAs($user);
            foreach ($adminUrls as $url) {
                $this->get($url)->assertForbidden();
            }
        }
    }

    public function test_missing_storage_file_returns_a_not_found_response(): void
    {
        $proposal = $this->upload($this->student());
        Storage::disk('proposals')->delete($proposal->file_path);
        $this->get('/student/proposals/'.$proposal->id.'/download')->assertNotFound()->assertDontSee($proposal->file_path);
    }

    public function test_text_is_escaped_and_uppercase_extensions_are_accepted(): void
    {
        $title = '<script>alert("title")</script>';
        $proposal = $this->upload($this->student(), $this->pdf('PROPOSAL.PDF'), ['title' => $title]);
        $this->get('/student/proposals/'.$proposal->id)->assertSee($title)->assertDontSee($title, false);
        $this->assertSame('pdf', $proposal->file_type);
    }

    public function test_database_failure_rolls_back_the_record_and_removes_the_uploaded_file(): void
    {
        Event::listen('eloquent.created: '.ResearchProposal::class, function () {
            throw new RuntimeException('Simulated insert failure');
        });
        try {
            $this->actingAs($this->student())->post('/student/proposals', ['title' => 'Failed save', 'document' => $this->pdf()])
                ->assertSessionHasErrors('document');
        } finally {
            Event::forget('eloquent.created: '.ResearchProposal::class);
        }
        $this->assertDatabaseCount('research_proposals', 0);
        $this->assertCount(0, Storage::disk('proposals')->allFiles());
    }

    public function test_same_file_is_rejected_for_the_same_student_even_when_renamed(): void
    {
        $student = $this->student();
        $file = $this->pdf();
        $contents = file_get_contents($file->getRealPath());
        $proposal = $this->upload($student, $file, ['title' => 'First proposal']);

        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'A different title',
            'document' => $this->file('renamed.pdf', $contents, 'application/pdf'),
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
        $this->assertSame(hash('sha256', $contents), $proposal->fresh()->file_hash);
    }

    public function test_normalized_duplicate_title_is_rejected_with_different_file(): void
    {
        $student = $this->student();
        $this->upload($student, $this->pdf(), ['title' => 'Funeral  Preference Recommender!']);

        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'funeral preference recommender',
            'document' => $this->pdf('different.pdf'),
        ])->assertSessionHasErrors('title');

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
    }

    public function test_duplicate_file_and_title_report_both_errors(): void
    {
        $student = $this->student();
        $file = $this->pdf();
        $contents = file_get_contents($file->getRealPath());
        $this->upload($student, $file, ['title' => 'Funeral  Preference Recommender!']);

        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'funeral preference recommender',
            'document' => $this->file('renamed.pdf', $contents, 'application/pdf'),
        ])->assertSessionHasErrors(['document', 'title']);

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
    }

    public function test_different_students_can_upload_the_same_file_and_title(): void
    {
        $firstStudent = $this->student();
        $secondStudent = $this->student();
        $file = $this->pdf();
        $contents = file_get_contents($file->getRealPath());
        $first = $this->upload($firstStudent, $file, ['title' => 'Shared Group Proposal']);

        $this->actingAs($secondStudent)->post('/student/proposals', [
            'title' => 'Shared Group Proposal',
            'document' => $this->file('shared-copy.pdf', $contents, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $second = $secondStudent->studentProfile->researchProposals()->firstOrFail();
        $this->assertNotSame($first->student_profile_id, $second->student_profile_id);
        $this->assertSame($first->file_hash, $second->file_hash);
        $this->assertSame($first->title_key, $second->title_key);
        $this->assertCount(2, Storage::disk('proposals')->allFiles());
    }

    public function test_deleting_a_proposal_allows_uploading_its_file_and_title_again(): void
    {
        $student = $this->student();
        $file = $this->pdf();
        $contents = file_get_contents($file->getRealPath());
        $proposal = $this->upload($student, $file, ['title' => 'Upload again later']);

        $this->actingAs($student)->delete('/student/proposals/'.$proposal->id)->assertRedirect();
        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'Upload again later',
            'document' => $this->file('again.pdf', $contents, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
    }

    public function test_rejected_duplicate_has_no_side_effects_and_never_extracts_or_analyzes(): void
    {
        $student = $this->student();
        $file = $this->pdf();
        $contents = file_get_contents($file->getRealPath());
        $this->upload($student, $file, ['title' => 'Existing proposal']);
        $this->mock(ProposalExtractionService::class)->shouldNotReceive('extract');
        $this->mock(\App\Services\ProposalAnalysisService::class)->shouldNotReceive('analyze');

        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'Existing proposal',
            'document' => $this->file('duplicate.pdf', $contents, 'application/pdf'),
        ])->assertSessionHasErrors(['document', 'title']);

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertDatabaseCount('proposal_analyses', 0);
        $this->assertCount(1, Storage::disk('proposals')->allFiles());
    }

    public function test_service_cleans_up_a_file_when_unique_constraints_catch_a_race(): void
    {
        $student = $this->student();
        $source = $this->pdf();
        $contents = file_get_contents($source->getRealPath());
        $hash = hash('sha256', $contents);
        $proposal = $student->studentProfile->researchProposals()->make();
        $proposal->forceFill([
            'title' => 'Race proposal',
            'file_path' => 'existing.pdf',
            'file_hash' => $hash,
            'title_key' => 'race proposal',
            'original_filename' => 'existing.pdf',
            'file_type' => 'pdf',
            'status' => 'uploaded',
        ])->save();

        try {
            app(\App\Services\ResearchProposalService::class)->upload(
                $student->studentProfile,
                'Race proposal',
                $this->file('race-copy.pdf', $contents, 'application/pdf')
            );
            $this->fail('The database unique constraint should reject the duplicate.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
            $this->assertArrayHasKey('title', $exception->errors());
        }

        $this->assertDatabaseCount('research_proposals', 1);
        $this->assertCount(0, Storage::disk('proposals')->allFiles());
    }

    public function test_different_file_is_accepted_after_an_extraction_failure(): void
    {
        $student = $this->student();
        $extractor = $this->mock(ProposalExtractionService::class);
        $extractor->shouldReceive('extract')->once()->andThrow(\Illuminate\Validation\ValidationException::withMessages([
            'extraction' => 'Temporary extraction failure.',
        ]));
        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'First failed extraction',
            'document' => $this->pdf(),
        ])->assertSessionHasErrors('extraction');

        $extractor->shouldReceive('extract')->once()->andReturnUsing(fn ($proposal) => $proposal);
        $this->post('/student/proposals', [
            'title' => 'Corrected proposal',
            'document' => $this->pdf('corrected.pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('research_proposals', 2);
        $this->assertCount(2, Storage::disk('proposals')->allFiles());
    }

    public function test_storage_failure_creates_no_proposal_record(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Simulated storage failure'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('proposals')->andReturn($disk);
        $this->actingAs($this->student())->post('/student/proposals', ['title' => 'Failed upload', 'document' => $this->pdf()])
            ->assertSessionHasErrors('document');
        $this->assertDatabaseCount('research_proposals', 0);
    }

}
