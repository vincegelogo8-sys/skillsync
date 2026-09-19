<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use App\Models\User;
use App\Services\MultiExpertiseService;
use App\Services\ProposalAnalysisService;
use App\Services\SimilarityService;
use App\Services\TopicAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TopicAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public static function components(): array
    {
        return [
            'specification example' => [80, 90, 83],
            'capstone example' => [88, 85, 87.1],
            'no evidence' => [0, 0, 0],
            'maximum' => [100, 100, 100],
            'cosine only keeps its weight' => [100, 0, 70],
            'expertise only keeps its weight' => [0, 100, 30],
            'clamp before weighting' => [150, -20, 70],
            'opposite clamp' => [-20, 150, 30],
            'no premature rounding' => [80.123456, 170 / 3, 73.0864192],
        ];
    }

    #[DataProvider('components')]
    public function test_alignment_uses_seventy_thirty_weights_without_final_weight(float $cosine, float $expertise, float $expected): void
    {
        $result = app(TopicAlignmentService::class)->calculate($cosine, $expertise);
        $this->assertEqualsWithDelta($expected, $result['topic_alignment_score'], 1e-10);
        $this->assertEqualsWithDelta($result['cosine_contribution'] + $result['multi_expertise_contribution'], $result['topic_alignment_score'], 1e-10);
        $this->assertSame(max(0.0, min(100.0, $cosine)), $result['cosine_similarity_score']);
        $this->assertSame(max(0.0, min(100.0, $expertise)), $result['multi_expertise_score']);
    }

    public static function nonFinite(): array
    {
        return [[NAN, 80], [INF, 80], [-INF, 80], [80, NAN], [80, INF], [80, -INF]];
    }

    #[DataProvider('nonFinite')]
    public function test_non_finite_components_are_rejected(float $cosine, float $expertise): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->calculate($cosine, $expertise);
    }

    private function analysis(): ProposalAnalysis
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-15', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Development', 'file_path' => 'step15.pdf', 'original_filename' => 'step15.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web development with Laravel and MySQL database management.';
        $analysis->save();

        return app(ProposalAnalysisService::class)->analyze($proposal);
    }

    private function faculty(): FacultyProfile
    {
        return User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
    }

    public function test_saved_pipeline_matches_both_components_in_one_shared_corpus_and_preserves_sources(): void
    {
        $analysis = $this->analysis();
        $before = $analysis->toArray();
        $faculty = $this->faculty();
        $empty = $this->faculty();
        $entry = $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $faculty->load('expertise');
        $service = app(TopicAlignmentService::class);
        // A generator must be consumed only once; duplicate candidates must count once.
        $candidates = (function () use ($faculty, $empty) {
            yield $faculty;
            yield $empty;
            yield $faculty;
        })();
        $results = $service->forProposal($analysis, $candidates);
        $cosine = app(SimilarityService::class)->forProposal($analysis, [$faculty, $empty]);
        $multi = app(MultiExpertiseService::class)->forProposal($analysis, $faculty);
        $this->assertSame([$faculty->id, $empty->id], array_keys($results));
        $this->assertSame($cosine['scores'][$faculty->id], $results[$faculty->id]['cosine_similarity_score']);
        $this->assertSame(45.0, $results[$faculty->id]['multi_expertise_score']);
        $this->assertSame($multi['breakdown'], $results[$faculty->id]['expertise_breakdown']);
        $this->assertEqualsWithDelta($cosine['scores'][$faculty->id] * .7 + 45 * .3, $results[$faculty->id]['topic_alignment_score'], 1e-10);
        $this->assertSame(0.0, $results[$empty->id]['topic_alignment_score']);
        $this->assertSame(2, $results[$empty->id]['required_count']);

        $entry->update(['proficiency_score' => 100]);
        $faculty->preferences()->create(['preference_type' => 'technology', 'preference_value' => 'PHP']);
        $updated = $service->forProposal($analysis, [$faculty, $empty]);
        $this->assertSame($results[$faculty->id]['cosine_similarity_score'], $updated[$faculty->id]['cosine_similarity_score']);
        $this->assertEqualsWithDelta(1.5, $updated[$faculty->id]['topic_alignment_score'] - $results[$faculty->id]['topic_alignment_score'], 1e-10);
        $this->assertSame($before, $analysis->fresh()->toArray());
        $this->assertSame('extracted', $analysis->researchProposal->status);
        $this->assertDatabaseCount('proposal_analyses', 1);
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame([], $service->forProposal($analysis, []));
    }

    public function test_no_required_expertise_does_not_redistribute_the_missing_weight(): void
    {
        $analysis = $this->analysis();
        $analysis->identified_expertise_areas = [];
        $faculty = $this->faculty();
        $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $result = app(TopicAlignmentService::class)->forProposal($analysis, [$faculty])[$faculty->id];
        $this->assertSame(0, $result['required_count']);
        $this->assertSame([], $result['expertise_breakdown']);
        $this->assertSame(0.0, $result['multi_expertise_score']);
        $this->assertEqualsWithDelta($result['cosine_similarity_score'] * .7, $result['topic_alignment_score'], 1e-10);
    }

    public function test_pending_analysis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->forProposal(new ProposalAnalysis, []);
    }

    public function test_invalid_required_areas_are_rejected_even_without_candidates(): void
    {
        $analysis = $this->analysis();
        $analysis->identified_expertise_areas = ['Unknown'];
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->forProposal($analysis, []);
    }

    public function test_unsaved_faculty_is_rejected(): void
    {
        $analysis = $this->analysis();
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->forProposal($analysis, [new FacultyProfile]);
    }
}
