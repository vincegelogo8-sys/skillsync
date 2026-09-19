<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use App\Models\User;
use App\Services\FacultyPreferenceService;
use App\Services\PreferenceCompatibilityService;
use App\Services\ProposalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreferenceCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_specification_example_uses_project_technology_denominator_and_preserves_precision(): void
    {
        $result = app(PreferenceCompatibilityService::class)->calculate('Web-Based System', ['Laravel', 'PHP', 'MySQL'], ['Web-Based System'], ['Laravel', 'MySQL', 'React']);
        $this->assertTrue($result['project_type_match']);
        $this->assertSame(100.0, $result['project_type_score']);
        $this->assertEqualsWithDelta(200 / 3, $result['technology_score'], 1e-10);
        $this->assertSame(50.0, $result['project_type_contribution']);
        $this->assertEqualsWithDelta(100 / 3, $result['technology_contribution'], 1e-10);
        $this->assertEqualsWithDelta(250 / 3, $result['preference_compatibility_score'], 1e-10);
        $this->assertSame(['Laravel', 'MySQL'], $result['matched_technologies']);
        $this->assertSame(['PHP'], $result['unmatched_technologies']);
        $this->assertSame(3, $result['technology_count']);
    }

    public static function combinations(): array
    {
        return [
            'full match' => ['Web-Based System', ['PHP'], ['Web-Based System'], ['PHP'], 100.0],
            'project only' => ['Web-Based System', ['PHP'], ['Web-Based System'], [], 50.0],
            'technology only' => ['Web-Based System', ['PHP'], ['Mobile Application'], ['PHP'], 50.0],
            'no preferences' => ['Web-Based System', ['PHP'], [], [], 0.0],
            'no matching terms' => ['Web-Based System', ['PHP'], ['Mobile Application'], ['Python'], 0.0],
            'no technologies' => ['Web-Based System', [], ['Web-Based System'], ['PHP'], 50.0],
            'no project type' => [null, ['PHP'], ['Web-Based System'], ['PHP'], 50.0],
            'no evidence' => [null, [], [], [], 0.0],
            'multiple preferred types' => ['Web-Based System', ['PHP'], ['Mobile Application', 'Web-Based System'], ['PHP'], 100.0],
            'deduplicated technology lists' => ['Web-Based System', ['PHP', 'PHP', 'MySQL'], [], ['PHP', 'PHP', 'React', 'Vue'], 25.0],
        ];
    }

    #[DataProvider('combinations')]
    public function test_matches_and_missing_evidence_keep_fixed_weights(?string $type, array $technologies, array $types, array $preferred, float $expected): void
    {
        $result = app(PreferenceCompatibilityService::class)->calculate($type, $technologies, $types, $preferred);
        $this->assertSame($expected, $result['preference_compatibility_score']);
        $this->assertGreaterThanOrEqual(0, $result['preference_compatibility_score']);
        $this->assertLessThanOrEqual(100, $result['preference_compatibility_score']);
    }

    public static function invalidValues(): array
    {
        return [
            ['Web App', [], [], []],
            [null, ['Unknown'], [], []],
            [null, [], ['Unknown'], []],
            [null, [], [], ['php']],
            [null, [null], [], []],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_canonical_values_are_rejected(?string $type, array $technologies, array $types, array $preferred): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PreferenceCompatibilityService::class)->calculate($type, $technologies, $types, $preferred);
    }

    public function test_saved_analysis_uses_current_preferences_without_expertise_or_source_writes(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-16', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Application', 'file_path' => 'step16.pdf', 'original_filename' => 'step16.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web application using Laravel, PHP and MySQL.';
        $analysis->save();
        $analysis = app(ProposalAnalysisService::class)->analyze($proposal);
        $before = $analysis->toArray();
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $empty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $preferences = app(FacultyPreferenceService::class);
        $preferences->save($faculty, ['project_types' => ['Web-Based System'], 'technologies' => ['Laravel', 'MySQL', 'React']]);
        $faculty->load('preferences');
        $service = app(PreferenceCompatibilityService::class);
        $candidates = (function () use ($faculty, $empty) {
            yield $faculty;
            yield $empty;
            yield $faculty;
        })();
        $results = $service->forProposal($analysis, $candidates);
        $this->assertSame([$faculty->id, $empty->id], array_keys($results));
        $this->assertEqualsWithDelta(250 / 3, $results[$faculty->id]['preference_compatibility_score'], 1e-10);
        $this->assertSame(0.0, $results[$empty->id]['preference_compatibility_score']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 100]);
        $this->assertSame($results, $service->forProposal($analysis, [$faculty, $empty]));
        $preferences->save($faculty, ['project_types' => [], 'technologies' => []]);
        $this->assertSame(0.0, $service->forProposal($analysis, [$faculty])[$faculty->id]['preference_compatibility_score']);
        $this->assertSame($before, $analysis->fresh()->toArray());
        $this->assertSame('extracted', $proposal->fresh()->status);
        $this->assertDatabaseCount('proposal_analyses', 1);
        $this->assertDatabaseCount('faculty_preferences', 0);
        $this->assertSame([], $service->forProposal($analysis, []));
    }

    public function test_pending_analysis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PreferenceCompatibilityService::class)->forProposal(new ProposalAnalysis, []);
    }

    public function test_unsaved_faculty_is_rejected(): void
    {
        $analysis = new ProposalAnalysis;
        $analysis->analyzed_at = now();
        $analysis->technologies = [];
        $this->expectException(InvalidArgumentException::class);
        app(PreferenceCompatibilityService::class)->forProposal($analysis, [new FacultyProfile]);
    }
}
