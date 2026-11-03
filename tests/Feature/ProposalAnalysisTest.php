<?php

namespace Tests\Feature;

use App\Models\ProposalAnalysis;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\DocumentExtractionRunner;
use App\Services\ProposalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\DocumentFixtures;
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
            $analysis->extracted_text = "Abstract\nFacial recognition uses OpenCV and Python.\nKeywords: recognition\nObjectives:\nFacial recognition uses OpenCV and Python. MySQL stores attendance records.";
            $analysis->save();
        }

        return $proposal;
    }

    public function test_owner_can_intentionally_reanalyze_and_get_requests_only_read(): void
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
        $this->get($url)->assertOk()->assertSee('Proposal Analysis')->assertDontSee('Project Type')->assertSee('Web Development');
        $this->assertSame($saved['keywords'], $proposal->fresh()->analysis->keywords);
        $this->assertNotEquals($saved['analyzed_at'], $proposal->fresh()->analysis->analyzed_at->toJSON());
        Process::assertRanTimes(fn ($process) => true, 2);
        Http::assertNothingSent();
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
        $this->assertSame(Arr::except($before, ['analysis_status', 'analysis_attempt_id', 'updated_at']), Arr::except($proposal->fresh()->analysis->toArray(), ['analysis_status', 'analysis_attempt_id', 'updated_at']));
        Event::forget('eloquent.updating: '.ProposalAnalysis::class);
        $this->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasNoErrors();
    }

    public function test_malformed_response_preserves_saved_analysis_and_allows_retry(): void
    {
        $proposal = $this->proposal();
        $before = app(ProposalAnalysisService::class)->analyze($proposal)->toArray();
        $this->fakeProposalAnalysis(['status' => 'completed', 'output' => []]);
        $url = '/student/proposals/'.$proposal->id;
        $this->actingAs($proposal->studentProfile->user)->post($url.'/analyze')->assertRedirect($url)->assertSessionHasErrors('analysis');
        $this->assertSame(Arr::except($before, ['analysis_status', 'analysis_attempt_id', 'updated_at']), Arr::except($proposal->fresh()->analysis->toArray(), ['analysis_status', 'analysis_attempt_id', 'updated_at']));
        $this->fakeProposalAnalysis();
        $this->post($url.'/analyze')->assertSessionHasNoErrors();
    }

    public function test_upload_api_failure_preserves_document_and_extracted_text_for_retry(): void
    {
        Storage::fake('proposals');
        $student = $this->proposal()->studentProfile->user;
        $this->fakeProposalAnalysis(['error' => 'unavailable'], 503);
        $this->mock(DocumentExtractionRunner::class)->shouldReceive('extract')->once()->andReturn('Introduction: Readable proposal text.');
        $this->actingAs($student)->post('/student/proposals', [
            'title' => 'Retryable proposal',
            'document' => UploadedFile::fake()->createWithContent('retry.pdf', DocumentFixtures::pdf()),
        ])->assertSessionHasErrors('analysis');
        $proposal = ResearchProposal::latest('id')->first();
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $this->assertSame('Introduction: Readable proposal text.', $proposal->analysis->extracted_text);
        $this->assertNull($proposal->analysis->analyzed_at);
        $this->fakeProposalAnalysis();
        $this->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasNoErrors();
        $this->assertTrue($proposal->fresh()->analysis->hasCurrentAnalysis());
    }

    public function test_concurrent_analysis_changes_are_not_overwritten(): void
    {
        $proposal = $this->proposal();
        Process::swap(new Factory);
        Process::fake(function () use ($proposal) {
            $proposal->analysis->forceFill(['extracted_text' => 'Newer document text.'])->save();

            return Process::result(output: json_encode(['keywords' => ['web development']]));
        });
        $this->actingAs($proposal->studentProfile->user)->post('/student/proposals/'.$proposal->id.'/analyze')->assertSessionHasErrors('analysis');
        $this->assertSame('Newer document text.', $proposal->fresh()->analysis->extracted_text);
        $this->assertNull($proposal->fresh()->analysis->analyzed_at);
    }

    public function test_source_text_is_escaped_in_results(): void
    {
        $proposal = $this->proposal();
        $analysis = $proposal->analysis;
        $analysis->extracted_text = "Abstract\n<script>alert('x')</script>\nIntroduction\nObjectives: To evaluate the system.";
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        $this->actingAs($proposal->studentProfile->user)->get('/student/proposals/'.$proposal->id)
            ->assertOk()->assertSee("<script>alert('x')</script>")->assertDontSee('<script>alert', false);
    }

    public function test_removed_sections_are_hidden_for_students_and_admins_and_legacy_abstract_is_preserved(): void
    {
        $proposal = $this->proposal();
        $analysis = $proposal->analysis;
        $analysis->abstract = 'Legacy stored abstract.';
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);

        foreach (['student' => $proposal->studentProfile->user, 'admin' => User::factory()->create(['role' => User::ROLE_ADMIN])] as $role => $user) {
            $this->actingAs($user)->get('/'.$role.'/proposals/'.$proposal->id)
                ->assertOk()->assertDontSee('Primary Research Area')->assertDontSee('Technical Concepts')->assertSee('Directly identified skills')
                ->assertSee('Web Development')->assertSee('Analyzed')
                ->assertDontSee('Technologies Mentioned')->assertDontSee('Identified Expertise')
                ->assertDontSee('Abstract / Summary')->assertDontSee('Legacy stored abstract.')
                ->assertDontSee('No matching terms found.')
                ->assertDontSee('No labeled abstract or summary section was found.');
        }
        $this->assertSame('Legacy stored abstract.', $analysis->fresh()->abstract);
        $this->assertSame($analysis->extracted_text, $analysis->fresh()->extracted_text);
        $this->assertContains('database systems', $analysis->fresh()->technologies);
        $this->assertContains('Software Engineering', $analysis->fresh()->identified_expertise_areas);
    }
}
