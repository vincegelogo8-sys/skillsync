<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\DocumentExtractionService;
use App\Services\ProposalAnalysisService;
use App\Services\ProposalSectionExtractor;
use App\Services\RecommendationService;
use App\Services\SimilarityService;
use App\Services\TopicAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

class ProposalSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_wrapped_title_and_five_page_spanning_objectives(): void
    {
        $text = "CONCEPT PAPER\r\nProposed Title:\r\nThe Watchful Campus: A Crowdsourced Framework for Real-Time School\r\nInfrastructure Maintenance\r\nResearcher/s:\r\nExcluded Name\r\nObjectives:\r\n1. To identify and categorize recurring infrastructure issues\r\n2. To design and implement a localized crowdsourcing platform\fPage 2 of 2\nRepublic of the Philippines\n3. To compare the response time and detection rate\n4) To evaluate the level of engagement and willingness\n5. To establish a transparent feedback\nmechanism\nParticipants/Respondents/Informants/Evaluators:\nExcluded Participants";
        $sections = app(ProposalSectionExtractor::class)->extract($text);
        $this->assertSame('The Watchful Campus: A Crowdsourced Framework for Real-Time School Infrastructure Maintenance', $sections['title']);
        $this->assertCount(5, explode("\n", $sections['objectives']));
        $this->assertStringContainsString('5. To establish a transparent feedback mechanism', $sections['objectives']);
        $this->assertStringNotContainsString('Excluded', $sections['analysis_text']);
        $this->assertStringNotContainsString('Republic', $sections['analysis_text']);
        $this->assertStringNotContainsString('Page', $sections['analysis_text']);
    }

    public function test_all_title_candidates_are_retained_unless_a_stored_title_is_supplied(): void
    {
        $titles = "Title 1: CampusSkill A Web-Based Skill Exchange and Service Request System\nTitle 2: Development of a Web-Based Platform for Skill Sharing\nTitle 3: Design and Implementation of CampusSkill";
        $text = "proposed\t title\n$titles\nResearchers: Excluded Name\nOBJECTIVES: To develop a platform.";
        $extractor = app(ProposalSectionExtractor::class);
        $this->assertSame($titles, $extractor->extract($text)['title']);
        $this->assertSame('Selected CampusSkill Title', $extractor->extract($text, 'Selected CampusSkill Title')['title']);
    }

    public function test_repeated_headers_and_wrapped_headings_preserve_campus_objectives(): void
    {
        $text = "ISAT U Miagao Campus\nCollege of Computing\nProposed\nTitle:\nThe Watchful Campus\nResearcher: Excluded\nObjectives:\n1. To provide services for the campus\nand its community.\fISAT U Miagao Campus\nCollege of Computing\n2. To improve coordination.\nParticipants/Respondents/\nInformants/Evaluators:\nExcluded Participants";
        $sections = app(ProposalSectionExtractor::class)->extract($text);
        $this->assertSame('The Watchful Campus', $sections['title']);
        $this->assertSame("1. To provide services for the campus and its community.\n2. To improve coordination.", $sections['objectives']);
    }

    public static function stopHeadings(): array
    {
        return array_map(fn ($heading) => [$heading], [
            'Participants:', 'Participants/Respondents/Informants/Evaluators:',
            'Methodology/Research Design:', 'Expected Output/s', 'Expected Outputs:',
            'Significance:', 'Scope:', 'Scope and Limitations:', 'Research Design:',
            'Name and Signature of the Student Researchers', 'Priority Agenda:',
        ]);
    }

    #[DataProvider('stopHeadings')]
    public function test_objectives_stop_at_recognized_sections(string $heading): void
    {
        $sections = app(ProposalSectionExtractor::class)->extract("Objectives\n1. To develop a web-based system.\n2. To evaluate the system.\n$heading\nStudents Faculty Administrators Python Python", 'CampusSkill');
        $this->assertSame("1. To develop a web-based system.\n2. To evaluate the system.", $sections['objectives']);
    }

    public function test_pdf_and_docx_formatting_produce_identical_analysis_and_similarity(): void
    {
        $pdf = "PROPOSED TITLE:\r\nCampusSkill: A Web\u{2011}Based Skill Exchange\r\nSystem\r\nResearcher: Excluded\r\nObjectives:\r\n1. To implement recommen-\r\ndation logic using Laravel and MySQL.\f[PAGE BREAK]\nPage 2\n2) To provide a real-\ntime dashboard.\nMethodology: Python OpenCV";
        $docx = "Proposed Title\nCampusSkill: A Web-Based Skill Exchange System\nResearchers: Excluded\nObjectives\n1. To implement recommendation\u{00A0}logic using Laravel and MySQL.\n\n2) To provide a real-time dashboard.\nMethodology: Python OpenCV";
        $extractor = app(ProposalSectionExtractor::class);
        $this->assertSame($extractor->extract($docx), $extractor->extract($pdf));
        $analyzer = app(ProposalAnalysisService::class);
        $this->assertSame($analyzer->identify('', $docx), $analyzer->identify('', $pdf));
        $similarity = app(SimilarityService::class);
        $this->assertSame(
            $similarity->calculate($extractor->extract($docx)['analysis_text'], ['web' => 'Web Development']),
            $similarity->calculate($extractor->extract($pdf)['analysis_text'], ['web' => 'Web Development'])
        );
        $this->assertSame('recommendation', $extractor->normalize("recommen\u{00AD}\n dation"));
    }

    private function proposal(string $text)
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'SEC-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Development', 'file_path' => 'sections.pdf', 'original_filename' => 'sections.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = $text;
        $analysis->save();

        return $proposal;
    }

    public function test_real_pdf_and_docx_use_the_same_sections_and_detections(): void
    {
        Storage::fake('proposals');
        $lines = ['Proposed Title:', 'CampusSkill Web-Based Skill Exchange', 'Researcher/s:', 'Excluded Name',
            'Objectives:', '1. To develop a web application using Laravel.', '2. To provide a real-time dashboard.',
            'Methodology:', 'Python OpenCV facial recognition'];
        Storage::disk('proposals')->put('equivalent.pdf', DocumentFixtures::pdf($lines));
        $word = new PhpWord;
        $section = $word->addSection();
        foreach ($lines as $line) {
            $section->addText($line);
        }
        IOFactory::createWriter($word, 'Word2007')->save(Storage::disk('proposals')->path('equivalent.docx'));
        $reader = app(DocumentExtractionService::class);
        $pdf = $reader->extract(Storage::disk('proposals')->path('equivalent.pdf'), 'pdf');
        $docx = $reader->extract(Storage::disk('proposals')->path('equivalent.docx'), 'docx');
        $extractor = app(ProposalSectionExtractor::class);
        $this->assertSame($extractor->extract($pdf), $extractor->extract($docx));
        $this->assertSame(app(ProposalAnalysisService::class)->identify('', $pdf), app(ProposalAnalysisService::class)->identify('', $docx));
    }

    public function test_excluded_sections_cannot_change_detection_vectors_alignment_or_recommendations(): void
    {
        $clean = "Proposed Title: Web Development\nObjectives:\nTo develop a web application using Laravel.\n";
        $dirty = "University of Excluded\n".$clean."Participants: Python Python\nSignificance: OpenCV facial recognition\nMethodology: machine learning TensorFlow\nExpected Outputs: GIS geographic information system";
        $analyzer = app(ProposalAnalysisService::class);
        $this->assertSame($analyzer->identify('', $clean), $analyzer->identify('', $dirty));
        $proposal = $this->proposal($dirty);
        $analysis = $analyzer->analyze($proposal);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 80]);
        $similarity = app(SimilarityService::class);
        $expected = $similarity->calculate("Web Development\nTo develop a web application using Laravel.", [$faculty->id => 'Web Development']);
        $this->assertSame($expected, $similarity->forProposal($analysis, [$faculty]));
        $alignment = app(TopicAlignmentService::class)->forProposal($analysis, [$faculty]);
        $this->assertSame($expected['scores'][$faculty->id], $alignment[$faculty->id]['cosine_similarity_score']);
        $this->assertSame(['Laravel'], $analysis->technologies);
        $this->assertSame(['Web Development'], $analysis->identified_expertise_areas);
        // Simulate legacy whole-document detections, then generate fresh recommendations.
        $analysis->technologies = ['Python'];
        $analysis->identified_expertise_areas = ['Computer Vision'];
        $analysis->save();
        $result = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)->sole();
        $this->assertEquals($alignment[$faculty->id], $result->details['topic_alignment']);
        $this->assertSame(['Laravel'], $analysis->fresh()->technologies);
        $this->assertSame($dirty, $analysis->fresh()->extracted_text);
    }

    public function test_missing_objectives_reports_a_validation_error_without_analyzing(): void
    {
        $proposal = $this->proposal("Proposed Title: CampusSkill\nMethodology: Python Python");
        $this->actingAs($proposal->studentProfile->user)->from('/student/proposals/'.$proposal->id)
            ->post('/student/proposals/'.$proposal->id.'/analyze')
            ->assertSessionHasErrors(['analysis' => 'Objectives section could not be identified in the uploaded proposal.']);
        $this->assertNull($proposal->analysis->analyzed_at);
        $this->assertNull($proposal->analysis->keywords);
    }

    public function test_missing_title_without_a_trusted_stored_title_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ProposalSectionExtractor::class)->extract('Objectives: To develop a platform.');
    }

    public function test_legacy_analysis_missing_objectives_shows_error_on_recommendation_page(): void
    {
        $proposal = $this->proposal('Methodology: Python');
        $analysis = $proposal->analysis;
        $analysis->analyzed_at = now();
        $analysis->save();
        $url = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->actingAs($proposal->studentProfile->user)->post($url)->assertSessionHasErrors('analysis');
        $this->get($url)->assertSee('Objectives section could not be identified in the uploaded proposal.');
    }

    public function test_refresh_reanalyzes_and_updates_existing_recommendation_ids(): void
    {
        Storage::fake('proposals');
        $proposal = $this->proposal('Objectives: To develop using Python and OpenCV facial recognition.');
        Storage::disk('proposals')->put($proposal->file_path, 'placeholder');
        app(ProposalAnalysisService::class)->analyze($proposal);
        User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $old = app(RecommendationService::class)->generate($proposal, $proposal->studentProfile->user)->sole();
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()
            ->andReturn("Objectives: To develop a web application using Laravel.\nMethodology: Python OpenCV");
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/refresh-extraction')->assertSessionHasNoErrors();
        $this->assertSame(['Laravel'], $proposal->fresh()->analysis->technologies);
        $this->assertSame($old->id, $proposal->recommendations()->sole()->id);
        $this->assertSame(['Web Development'], $proposal->fresh()->analysis->identified_expertise_areas);
    }

    public function test_refresh_missing_objectives_preserves_previous_results_and_reports_error(): void
    {
        Storage::fake('proposals');
        $proposal = $this->proposal('Objectives: To develop a web application using Laravel.');
        Storage::disk('proposals')->put($proposal->file_path, 'placeholder');
        $before = app(ProposalAnalysisService::class)->analyze($proposal)->toArray();
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Methodology: Python');
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/refresh-extraction')->assertSessionHasErrors('analysis');
        $this->assertSame($before, $proposal->fresh()->analysis->toArray());
    }
}
