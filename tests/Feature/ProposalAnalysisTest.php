<?php

namespace Tests\Feature;

use App\Models\ProposalAnalysis;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\ProposalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ProposalAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(bool $withText = true): ResearchProposal
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-'.$student->id, 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = new ResearchProposal;
        $proposal->student_profile_id = $profile->id;
        $proposal->title = 'Facial Recognition for Attendance';
        $proposal->file_path = 'private.pdf';
        $proposal->original_filename = 'proposal.pdf';
        $proposal->file_type = 'pdf';
        $proposal->status = $withText ? 'extracted' : 'uploaded';
        $proposal->save();
        if ($withText) {
            $analysis = $proposal->analysis()->make();
            $analysis->extracted_text = "Abstract\nFacial recognition uses OpenCV and Python.\nKeywords: recognition\n1. Introduction\nMySQL stores attendance records.";
            $analysis->save();
        }

        return $proposal;
    }

    public function test_technical_rules_use_evidence_and_preserve_labeled_abstract(): void
    {
        $result = app(ProposalAnalysisService::class)->identify('Facial Recognition Attendance', "ABSTRACT\nWe use OpenCV for facial recognition.\nKeywords: attendance\nIntroduction\nPython and MySQL are used.");
        $this->assertSame('We use OpenCV for facial recognition.', $result['abstract']);
        $this->assertSame('Computer Vision System', $result['project_type']);
        $this->assertEqualsCanonicalizing(['Python', 'MySQL', 'OpenCV'], $result['technologies']);
        $this->assertSame(['Computer Vision', 'Database Systems'], $result['identified_expertise_areas']);
        $this->assertContains('facial recognition', $result['keywords']);
        $this->assertNotContains('TensorFlow', $result['technologies']);
    }

    public function test_aliases_and_punctuation_do_not_create_substring_technologies(): void
    {
        $result = app(ProposalAnalysisService::class)->identify('', 'JavaScript, C++, C#, NodeJS, dotnet, React Native, postgres, sklearn and Amazon Web Services.');
        $this->assertEqualsCanonicalizing(['JavaScript', 'C++', 'C#', 'Node.js', '.NET', 'React Native', 'PostgreSQL', 'scikit-learn', 'AWS'], $result['technologies']);
        foreach (['Java', 'C', 'React', 'SQL'] as $absent) {
            $this->assertNotContains($absent, $result['technologies']);
        }
        $this->assertCount(3, $result['identified_expertise_areas']);
        $this->assertSame([], app(ProposalAnalysisService::class)->identify('', 'flasklike reaction mysqldata javabeans')['technologies']);
    }

    public function test_generic_words_and_unknown_projects_do_not_force_results(): void
    {
        $result = app(ProposalAnalysisService::class)->identify('Research Project', 'The study study system system research research project development information application.');
        $this->assertNull($result['abstract']);
        $this->assertNull($result['project_type']);
        $this->assertSame([], $result['keywords']);
        $this->assertSame([], $result['technologies']);
        $this->assertSame([], $result['identified_expertise_areas']);
    }

    public function test_title_has_more_weight_and_repetition_does_not_dominate_rules(): void
    {
        $service = app(ProposalAnalysisService::class);
        $result = $service->identify('Mobile Application', str_repeat('web application ', 100).' Flutter');
        $this->assertSame('Mobile Application', $result['project_type']);
        $this->assertSame('Mobile Development', $result['identified_expertise_areas'][0]);
        $this->assertSame($result, $service->identify('Mobile Application', str_repeat('web application ', 100).' Flutter'));
        $summary = $service->identify('', "Executive Summary: This is the actual summary.\nSecond sentence.\n2. Methodology\nNot part of the summary.");
        $this->assertSame("This is the actual summary.\nSecond sentence.", $summary['abstract']);
    }

    public function test_configuration_uses_shared_canonical_categories(): void
    {
        $this->assertSame(config('preferences.project_types'), array_keys(config('proposal_analysis.project_types')));
        $this->assertSame(config('expertise.areas'), array_keys(config('proposal_analysis.expertise')));
        $this->assertSame([], array_diff(array_keys(config('proposal_analysis.aliases')), config('preferences.technologies')));
    }

    public function test_owner_analyzes_existing_text_once_and_get_requests_only_read(): void
    {
        $proposal = $this->proposal();
        $url = '/student/proposals/'.$proposal->id;
        $this->mock(DocumentExtractionRunner::class)->shouldNotReceive('extract');
        $this->actingAs($proposal->studentProfile->user)->get($url)->assertOk()->assertSee('Analyze Proposal');
        $this->assertNull($proposal->analysis->analyzed_at);
        $this->post($url.'/analyze')->assertRedirect($url)->assertSessionHasNoErrors();
        $saved = $proposal->fresh()->analysis->toArray();
        $this->assertNotNull($saved['analyzed_at']);
        $this->assertSame('Facial Recognition for Attendance', $proposal->fresh()->title);
        $this->travel(1)->hour();
        $this->post($url.'/analyze')->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Analysis complete.')->assertSee('Computer Vision System')->assertSee('OpenCV');
        $this->assertSame($saved, $proposal->fresh()->analysis->toArray());
        $this->assertDatabaseCount('proposal_analyses', 1);
    }

    public function test_analysis_routes_enforce_roles_and_ownership(): void
    {
        $proposal = $this->proposal();
        $studentUrl = '/student/proposals/'.$proposal->id.'/analyze';
        $adminUrl = '/admin/proposals/'.$proposal->id.'/analyze';
        $this->post($studentUrl)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->post($studentUrl)->assertNotFound();
        $this->post($adminUrl)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->post($studentUrl)->assertForbidden();
        $this->post($adminUrl)->assertForbidden();
        $this->assertNull($proposal->fresh()->analysis->analyzed_at);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->post($adminUrl)->assertSessionHasNoErrors()->assertRedirect('/admin/proposals/'.$proposal->id);
        $this->assertNotNull($proposal->fresh()->analysis->analyzed_at);
    }

    public function test_missing_extraction_returns_validation_error_and_does_not_create_analysis(): void
    {
        $proposal = $this->proposal(false);
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasErrors('analysis');
        $this->assertDatabaseCount('proposal_analyses', 0);
    }

    public function test_failed_save_preserves_extracted_text_and_can_be_retried(): void
    {
        $proposal = $this->proposal();
        $before = $proposal->analysis->toArray();
        Event::listen('eloquent.updating: '.ProposalAnalysis::class, function () {
            throw new RuntimeException('Simulated write failure');
        });
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasErrors('analysis');
        $this->assertSame($before, $proposal->fresh()->analysis->toArray());
        Event::forget('eloquent.updating: '.ProposalAnalysis::class);
        $this->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasNoErrors();
    }

    public function test_abstract_is_escaped_in_results(): void
    {
        $proposal = $this->proposal();
        $analysis = $proposal->analysis;
        $analysis->extracted_text = "Abstract\n<script>alert('x')</script>\nIntroduction";
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id)
            ->assertOk()->assertSee("<script>alert('x')</script>")->assertDontSee('<script>alert', false);
    }
}
