<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use App\Models\User;
use App\Services\MultiExpertiseService;
use App\Services\ProposalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MultiExpertiseTest extends TestCase
{
    use RefreshDatabase;

    private const AREAS = ['Web Development', 'Machine Learning / Data Analytics', 'Database Systems'];

    public static function scores(): array
    {
        return [
            'full match' => [[90, 80, 70], 80.0],
            'stronger proficiency' => [[95, 95, 90], 280 / 3],
            'missing area' => [[90, 80], 170 / 3],
            'one matched area' => [[90], 30.0],
            'no matches' => [[], 0.0],
            'zero scores' => [[0, 0, 0], 0.0],
            'maximum' => [[100, 100, 100], 100.0],
            'clamped scores' => [[120, -10, 50], 50.0],
        ];
    }

    #[DataProvider('scores')]
    public function test_strength_averages_all_required_areas(array $values, float $expected): void
    {
        $proficiencies = [];
        foreach ($values as $index => $value) {
            $proficiencies[self::AREAS[$index]] = $value;
        }
        $result = app(MultiExpertiseService::class)->calculate(self::AREAS, $proficiencies);
        $this->assertEqualsWithDelta($expected, $result['score'], 0.0000001);
        $this->assertSame(3, $result['required_count']);
        $this->assertSame(self::AREAS, array_column($result['breakdown'], 'expertise_area'));
        $this->assertSame(count($values) < 3, $result['breakdown'][2]['missing']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_uses_actual_required_count_and_ignores_unrelated_expertise(): void
    {
        $service = app(MultiExpertiseService::class);
        $scores = ['Web Development' => 90, 'Database Systems' => 60, 'Networking' => 100];
        $this->assertSame(90.0, $service->calculate(['Web Development'], $scores)['score']);
        $this->assertSame(75.0, $service->calculate(['Web Development', 'Database Systems'], $scores)['score']);
        $this->assertSame(75.0, $service->calculate(['Web Development', 'Database Systems', 'Web Development'], $scores)['score']);
        $this->assertSame(['score' => 0.0, 'required_count' => 0, 'breakdown' => []], $service->calculate([], $scores));
    }

    public static function invalidInputs(): array
    {
        return [
            'unknown area' => [['AI'], []],
            'malformed area' => [[null], []],
            'too many areas' => [[...self::AREAS, 'Networking'], []],
            'null proficiency' => [['Web Development'], ['Web Development' => null]],
            'nonnumeric proficiency' => [['Web Development'], ['Web Development' => 'high']],
            'infinite proficiency' => [['Web Development'], ['Web Development' => INF]],
            'nan proficiency' => [['Web Development'], ['Web Development' => NAN]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_are_rejected_instead_of_producing_misleading_scores(array $areas, array $scores): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MultiExpertiseService::class)->calculate($areas, $scores);
    }

    public function test_saved_analysis_integrates_with_current_faculty_expertise_without_writes(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-13', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Application', 'file_path' => 'step13.pdf', 'original_filename' => 'step13.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web development with Laravel and MySQL database management.';
        $analysis->save();
        $analysis = app(ProposalAnalysisService::class)->analyze($proposal);
        $this->assertSame(['Web Development', 'Database Systems'], $analysis->identified_expertise_areas);

        $user = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $faculty = $user->facultyProfile()->create(['department' => 'Computing']);
        $entry = $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $faculty->load('expertise');
        $before = $analysis->toArray();
        $service = app(MultiExpertiseService::class);
        $result = $service->forProposal($analysis, $faculty);
        $this->assertSame(45.0, $result['score']);
        $this->assertSame(['expertise_area' => 'Database Systems', 'proficiency_score' => 0.0, 'missing' => true], $result['breakdown'][1]);

        $entry->update(['proficiency_score' => 80]);
        $this->assertSame(40.0, $service->forProposal($analysis, $faculty)['score']);
        $faculty->expertise()->create(['expertise_area' => 'Database Systems', 'proficiency_score' => 0]);
        $this->assertFalse($service->forProposal($analysis, $faculty)['breakdown'][1]['missing']);
        $this->assertSame($before, $analysis->fresh()->toArray());
        $this->assertSame('extracted', $proposal->fresh()->status);
        $this->assertDatabaseCount('faculty_expertise', 2);
        $this->assertDatabaseCount('proposal_analyses', 1);
    }

    public function test_pending_analysis_is_distinguished_from_a_completed_analysis_with_no_evidence(): void
    {
        $service = app(MultiExpertiseService::class);
        $analysis = new ProposalAnalysis;
        $faculty = new FacultyProfile;
        $this->expectException(InvalidArgumentException::class);
        $service->forProposal($analysis, $faculty);
    }
}
