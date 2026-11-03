<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\LocalNlpService;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

class ProposalQuotaFailureTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->studentProfile()->create(['student_number' => 'Q-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);

        return $student;
    }

    private function quotaFailure(string $code = 'insufficient_quota'): void
    {
        $this->fakeProposalAnalysis(['error' => ['code' => $code, 'message' => 'PRIVATE BILLING DETAILS sk-secret-canary']], 429);
    }

    public static function documents(): array
    {
        return [['pdf', 'insufficient_quota'], ['docx', 'billing_hard_limit_reached']];
    }

    #[DataProvider('documents')]
    public function test_quota_failure_preserves_real_extraction_and_retry_needs_no_reupload(string $type, string $code): void
    {
        Storage::fake('proposals');
        Log::spy();
        $student = $this->student();
        $this->quotaFailure($code);
        $bytes = $type === 'pdf' ? DocumentFixtures::pdf() : DocumentFixtures::docx();
        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'Web records management',
            'document' => UploadedFile::fake()->createWithContent('proposal.'.$type, $bytes),
        ])->assertSessionHasErrors(['analysis' => LocalNlpService::UNAVAILABLE]);
        $proposal = ResearchProposal::sole();
        $analysis = $proposal->analysis;
        $text = $analysis->extracted_text;
        $this->assertSame('extracted', $proposal->status);
        $this->assertNull($proposal->extraction_error);
        $this->assertNotEmpty($text);
        $this->assertSame('failed', $analysis->analysis_status);
        $this->assertNull($analysis->keywords);
        $this->assertNull($analysis->technologies);
        $this->assertNull($analysis->research_representation);
        $this->assertNull($analysis->analyzed_at);
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $url = '/student/proposals/'.$proposal->id;
        $this->get($url)->assertOk()->assertSee('Failed')->assertSee('Reanalyze')
            ->assertSee(LocalNlpService::UNAVAILABLE)->assertDontSee('PRIVATE BILLING DETAILS')->assertDontSee('sk-secret-canary');
        // The message must persist even after the validation-error flash expires.
        $this->get($url)->assertSee(LocalNlpService::UNAVAILABLE);
        $this->post($url.'/recommendations')->assertSessionHasErrors('recommendations');
        $this->assertDatabaseCount('recommendations', 0);
        Log::shouldHaveReceived('warning')->once()->with('Local proposal analysis unavailable.', ['category' => 'python_failure', 'exit_code' => 1]);

        $this->mock(DocumentExtractionRunner::class)->shouldNotReceive('extract');
        $this->fakeProposalAnalysis();
        $this->post($url.'/analyze')->assertSessionHasNoErrors();
        $this->assertSame($text, $analysis->fresh()->extracted_text);
        $this->assertSame('completed', $analysis->fresh()->analysis_status);
        $this->assertTrue($analysis->fresh()->hasCurrentAnalysis());
        $this->assertDatabaseCount('research_proposals', 1);
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
        $this->post($url.'/recommendations')->assertSessionHasNoErrors();
        $this->assertSame('Web Development', $proposal->recommendations()->sole()->details['matching_expertise'][0]['category']);
        $this->assertNull($proposal->recommendations()->sole()->topic_alignment_score);
        Process::assertRanTimes(fn ($process) => true, 1);
        Http::assertNothingSent();
    }

    public function test_pending_and_failed_attempts_cannot_change_existing_recommendations(): void
    {
        $student = $this->student();
        $proposal = $student->studentProfile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Records', 'file_path' => 'records.pdf', 'original_filename' => 'records.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $proposal->analysis()->make()->forceFill(['extracted_text' => 'Web records management with Laravel.'])->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
        $saved = app(RecommendationService::class)->generate($proposal, $student)
            ->map(fn ($recommendation) => $recommendation->getAttributes())->all();
        $concepts = $proposal->fresh()->analysis->keywords;
        Process::swap(new Factory);
        Process::fake(function () use ($proposal, $student) {
            $this->assertSame('pending', $proposal->fresh()->analysis->analysis_status);
            try {
                app(RecommendationService::class)->generate($proposal, $student);
                $this->fail('Pending analysis must block new RTA.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('recommendations', $exception->errors());
            }

            return Process::result(errorOutput: 'private Python failure', exitCode: 1);
        });
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($student)->post($url.'/analyze')->assertSessionHasErrors('analysis');
        $this->assertSame('failed', $proposal->fresh()->analysis->analysis_status);
        $this->assertSame($concepts, $proposal->fresh()->analysis->keywords);
        $this->assertSame($saved, $proposal->recommendations()->get()->map(fn ($recommendation) => $recommendation->getAttributes())->all());
        $this->post($url.'/recommendations')->assertSessionHasErrors('recommendations');
        $this->assertSame($saved, $proposal->recommendations()->get()->map(fn ($recommendation) => $recommendation->getAttributes())->all());
        $this->fakeProposalAnalysis();
        $this->post($url.'/analyze')->assertSessionHasNoErrors();
        $this->assertTrue($proposal->fresh()->analysis->hasCurrentAnalysis());
        $this->assertSame($saved[0]['id'], $proposal->recommendations()->sole()->id);
        Process::assertRanTimes(fn ($process) => true, 1);
        Http::assertNothingSent();
    }

    public function test_safe_diagnostics_never_copy_arbitrary_provider_error_codes(): void
    {
        Log::spy();
        $this->quotaFailure('sk-secret-canary-private-error');
        try {
            app(LocalNlpService::class)->analyze('Private title', 'Private proposal text.');
            $this->fail('Request should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(LocalNlpService::UNAVAILABLE, $exception->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('Local proposal analysis unavailable.', ['category' => 'python_failure', 'exit_code' => 1]);
    }
}
