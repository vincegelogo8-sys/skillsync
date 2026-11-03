<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\DocumentExtractionService;
use App\Services\LocalNlpService;
use App\Services\ProposalAnalysisService;
use App\Services\ProposalTextNormalizer;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentFixtures;
use Tests\Support\ProposalAnalysisResponse;
use Tests\TestCase;

class ProposalSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(string $text)
    {
        $student = User::factory()->create(['role' => 'student']);
        $profile = $student->studentProfile()->create(['student_number' => 'SEC-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Development', 'file_path' => 'sections.pdf', 'original_filename' => 'sections.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = $text;
        $analysis->save();

        return $proposal;
    }

    public function test_pdf_docx_formatting_and_page_furniture_normalize_equivalently(): void
    {
        $pdf = "EXAMPLE UNIVERSITY\nIntroduction\nCrowdsourced recommen-\ndation\u{00A0}reports.\fPage 2 of 4\nEXAMPLE UNIVERSITY\nMethodology\n1. Real-\ntime issue monitoring.";
        $docx = "Introduction\nCrowdsourced recommendation reports.\nMethodology\n1. Real-time issue monitoring.";
        $normalizer = app(ProposalTextNormalizer::class);
        $this->assertSame($normalizer->relevant($docx), $normalizer->relevant($pdf));
        $this->assertSame('recommendation', $normalizer->normalize("recommen\u{00AD}\n dation"));
    }

    public function test_nonresearch_sections_are_excluded_and_relevant_sections_remain(): void
    {
        $text = "Approval Sheet\nPanel Person\nTable of Contents\nReferences .... 30\nIntroduction .... 1\nIntroduction\nOur campus maintenance proposal.\nReview of Related Literature\nA cited study used image classification.\nMethodology\nWe use crowdsourced issue reporting.\nBibliography\nOtherAuthor unrelated deep learning.";
        $clean = app(ProposalTextNormalizer::class)->relevant($text);
        $this->assertStringNotContainsString('panel person', $clean);
        $this->assertStringNotContainsString('otherauthor', $clean);
        $this->assertStringContainsString('our campus maintenance', $clean);
        $this->assertStringContainsString('a cited study', $clean);
        $this->assertStringContainsString('we use crowdsourced', $clean);
    }

    public function test_real_pdf_and_docx_extractors_feed_equivalent_text_to_local_nlp(): void
    {
        Storage::fake('proposals');
        $lines = ['Introduction', 'A web application using Laravel PHP MySQL.', 'Objectives:', 'To manage student records.', 'Methodology:', 'Web development and database systems.'];
        Storage::disk('proposals')->put('equivalent.pdf', DocumentFixtures::pdf($lines));
        Storage::disk('proposals')->put('equivalent.docx', DocumentFixtures::docxParagraphs($lines));
        $reader = app(DocumentExtractionService::class);
        $pdf = $reader->extract(Storage::disk('proposals')->path('equivalent.pdf'), 'pdf');
        $docx = $reader->extract(Storage::disk('proposals')->path('equivalent.docx'), 'docx');
        $this->assertSame(app(ProposalTextNormalizer::class)->relevant($pdf), app(ProposalTextNormalizer::class)->relevant($docx));
        app(ProposalAnalysisService::class)->identify('Records', $pdf);
        app(ProposalAnalysisService::class)->identify('Records', $docx);
        Process::assertRanTimes(fn ($process) => json_decode($process->input, true)['title'] === 'Records', 2);
        Http::assertNothingSent();
    }

    public function test_missing_objectives_does_not_block_whole_proposal_analysis(): void
    {
        $proposal = $this->proposal('Introduction: The study manages records. Methodology: A web application with Laravel.');
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasNoErrors();
        $this->assertTrue($proposal->fresh()->analysis->hasCurrentAnalysis());
    }

    public function test_legacy_analysis_requires_explicit_reanalysis_and_get_does_not_run_nlp(): void
    {
        $proposal = $this->proposal('Methodology: A web application.');
        $proposal->analysis->forceFill(['analyzed_at' => now(), 'keywords' => ['old keyword']])->save();
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id)->assertOk()->assertSee('Reanalyze this proposal');
        $this->post('/student/proposals/'.$proposal->id.'/recommendations')->assertSessionHasErrors('recommendations');
        Http::assertNothingSent();
    }

    public function test_refresh_replaces_supported_categories_and_recalculates_matching_recommendations(): void
    {
        Storage::fake('proposals');
        $proposal = $this->proposal('Introduction: Web records.');
        Storage::disk('proposals')->put($proposal->file_path, 'placeholder');
        app(ProposalAnalysisService::class)->analyze($proposal);
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Database Systems']);
        $old = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)->sole();
        $this->fakeProposalAnalysis(ProposalAnalysisResponse::response(['primary_research_area' => 'Mobile Development', 'research_concepts' => ['mobile development', 'database systems'], 'technical_concepts' => ['MySQL']]));
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Methodology: Android mobile application stores attendance records in MySQL.');
        $this->travel(1)->hour();
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/refresh-extraction')->assertSessionHasNoErrors();
        $analysis = $proposal->fresh()->analysis;
        $updated = $proposal->recommendations()->sole();
        $this->assertSame(['Web Development', 'Mobile Development', 'Database Systems'], $analysis->keywords);
        $this->assertSame(['mobile development', 'database systems'], $analysis->technologies);
        $this->assertSame($old->id, $updated->id);
        $this->assertSame('Database Systems', $updated->details['matching_expertise'][0]['category']);
        $this->assertNull($updated->topic_alignment_score);
        $this->assertNull($updated->final_score);
        Process::assertRanTimes(fn ($process) => true, 1);
        Http::assertNothingSent();
        Storage::disk('proposals')->assertExists($proposal->file_path);
    }

    public function test_failed_refresh_preserves_extraction_analysis_and_recommendations(): void
    {
        Storage::fake('proposals');
        $proposal = $this->proposal('Introduction: Web records.');
        Storage::disk('proposals')->put($proposal->file_path, 'placeholder');
        $before = app(ProposalAnalysisService::class)->analyze($proposal)->toArray();
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
        $rows = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)
            ->map(fn ($recommendation) => $recommendation->getAttributes())->all();
        $this->fakeProposalAnalysis(['error' => 'secret-provider-details'], 503);
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('New document text.');
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($proposal->studentProfile->user)->post($url.'/refresh-extraction')->assertRedirect($url)->assertSessionHasErrors('analysis');
        $this->assertSame(Arr::except($before, ['analysis_status', 'analysis_attempt_id', 'updated_at', 'extracted_text']), Arr::except($proposal->fresh()->analysis->toArray(), ['analysis_status', 'analysis_attempt_id', 'updated_at', 'extracted_text']));
        $this->assertSame('New document text.', $proposal->fresh()->analysis->extracted_text);
        $this->assertSame('failed', $proposal->fresh()->analysis->analysis_status);
        $this->assertSame('extracted', $proposal->fresh()->status);
        $this->assertSame($rows, $proposal->recommendations()->get()->map(fn ($recommendation) => $recommendation->getAttributes())->all());
        $this->get($url)->assertOk()->assertSee(LocalNlpService::UNAVAILABLE)->assertDontSee('secret-provider-details');
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $this->fakeProposalAnalysis();
        $this->post($url.'/analyze')->assertSessionHasNoErrors();
        $this->assertSame('New document text.', $proposal->fresh()->analysis->extracted_text);
        $this->assertSame('completed', $proposal->fresh()->analysis->analysis_status);
        Process::assertRan(fn ($process) => json_decode($process->input, true)['text'] === 'web development new document text.');
    }

    public function test_recommendation_save_failure_rolls_back_new_analysis(): void
    {
        $proposal = $this->proposal('Introduction: Records management.');
        $before = app(ProposalAnalysisService::class)->analyze($proposal)->toArray();
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Database Systems']);
        $rows = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)
            ->map(fn ($recommendation) => $recommendation->getAttributes())->all();
        $this->mock(RecommendationService::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Private database details'));
        $this->travel(1)->hour();
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasErrors('analysis');
        $this->assertSame(Arr::except($before, ['analysis_status', 'analysis_attempt_id', 'updated_at']), Arr::except($proposal->fresh()->analysis->toArray(), ['analysis_status', 'analysis_attempt_id', 'updated_at']));
        $this->assertSame($rows, $proposal->recommendations()->get()->map(fn ($recommendation) => $recommendation->getAttributes())->all());
    }
}
