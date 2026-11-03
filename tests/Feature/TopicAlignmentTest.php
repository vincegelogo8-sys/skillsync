<?php

namespace Tests\Feature;

use App\Models\ProposalAnalysis;
use App\Models\User;
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

    public static function scores(): array
    {
        return [[80, 80], [100, 100], [0, 0], [150, 100], [-20, 0], [80.123456789, 80.123456789]];
    }

    #[DataProvider('scores')]
    public function test_cosine_percentage_is_the_only_alignment_score(float $cosine, float $expected): void
    {
        $result = app(TopicAlignmentService::class)->calculate($cosine);
        $this->assertSame($expected, $result['topic_alignment_score']);
        $this->assertSame($expected, $result['cosine_similarity_score']);
        $this->assertArrayNotHasKey('multi_expertise_score', $result);
    }

    public static function invalidScores(): array
    {
        return [[NAN], [INF], [-INF]];
    }

    #[DataProvider('invalidScores')]
    public function test_non_finite_scores_are_rejected(float $score): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->calculate($score);
    }

    public function test_saved_concepts_produce_deterministic_rta_independent_of_capacity_and_proficiency(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $profile = $student->studentProfile()->create(['student_number' => 'T-1', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Records', 'file_path' => 'r.pdf', 'original_filename' => 'r.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Methodology: The web development system builds a web application using Laravel and PHP.';
        $analysis->save();
        $analysis = app(ProposalAnalysisService::class)->analyze($proposal);
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $empty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $entry = $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 10]);
        $service = app(TopicAlignmentService::class);
        $candidates = (function () use ($faculty, $empty) {
            yield $faculty;
            yield $empty;
            yield $faculty;
        })();
        $before = $service->forProposal($analysis, $candidates);
        $expected = app(SimilarityService::class)->forProposal($analysis, [$faculty, $empty]);
        $this->assertSame([$faculty->id, $empty->id], array_keys($before));
        $this->assertArrayHasKey('web', $expected['faculty_vectors'][$faculty->id]);
        $this->assertGreaterThan(0, $before[$faculty->id]['topic_alignment_score']);
        $this->assertSame($expected['scores'][$faculty->id], $before[$faculty->id]['topic_alignment_score']);
        $this->assertSame(0.0, $before[$empty->id]['topic_alignment_score']);
        $entry->update(['proficiency_score' => 100]);
        $faculty->update(['advisory_limit' => 0]);
        $this->assertSame($before, $service->forProposal($analysis, [$faculty, $empty]));
        $this->assertSame([], $service->forProposal($analysis, []));
    }

    public function test_legacy_or_pending_analysis_requires_reanalysis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TopicAlignmentService::class)->forProposal(new ProposalAnalysis, []);
    }
}
