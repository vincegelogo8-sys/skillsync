<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\SimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SimilarityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokenization_preserves_required_technical_terms_and_removes_generic_words(): void
    {
        $tokens = app(SimilarityService::class)->tokenize("PHP C++ C# .NET Node.js AI ML NLP IoT SQL API\nTHE research system study project development information application");
        $this->assertSame(['php', 'c++', 'c#', '.net', 'node.js', 'ai', 'ml', 'nlp', 'iot', 'sql', 'api'], $tokens);
        $this->assertSame(['web', 'café', 'node.js', '.net', 'mysql', 'javascript'], app(SimilarityService::class)->tokenize('Web-based CAFÉ nodejs dotnet MySQL JavaScript'));
    }

    public function test_tfidf_and_cosine_match_a_hand_calculated_shared_corpus(): void
    {
        $result = app(SimilarityService::class)->calculate('apple apple banana', ['first' => 'apple banana', 'second' => 'banana cherry']);
        $appleIdf = log(4 / 3) + 1;
        $cherryIdf = log(4 / 2) + 1;
        $this->assertEqualsWithDelta($appleIdf, $result['inverse_document_frequencies']['apple'], 1e-12);
        $this->assertSame(1.0, $result['inverse_document_frequencies']['banana']);
        $this->assertEqualsWithDelta($cherryIdf, $result['inverse_document_frequencies']['cherry'], 1e-12);
        $this->assertEqualsWithDelta(2 / 3 * $appleIdf, $result['proposal_vector']['apple'], 1e-12);
        $this->assertEqualsWithDelta(1 / 2 * $appleIdf, $result['faculty_vectors']['first']['apple'], 1e-12);
        $expectedFirst = (2 * $appleIdf ** 2 + 1) / (sqrt(4 * $appleIdf ** 2 + 1) * sqrt($appleIdf ** 2 + 1)) * 100;
        $expectedSecond = 1 / (sqrt(4 * $appleIdf ** 2 + 1) * sqrt(1 + $cherryIdf ** 2)) * 100;
        $this->assertEqualsWithDelta($expectedFirst, $result['scores']['first'], 1e-10);
        $this->assertEqualsWithDelta($expectedSecond, $result['scores']['second'], 1e-10);
        $this->assertGreaterThan($result['scores']['second'], $result['scores']['first']);
    }

    public function test_identical_disjoint_and_empty_documents_have_safe_scores(): void
    {
        $service = app(SimilarityService::class);
        $result = $service->calculate('PHP SQL', [11 => 'sql php', 12 => 'network routing', 13 => '', 14 => 'the system research']);
        $this->assertEqualsWithDelta(100, $result['scores'][11], 1e-10);
        foreach ([12, 13, 14] as $id) {
            $this->assertSame(0.0, $result['scores'][$id]);
        }
        $this->assertSame([1 => 0.0], $service->calculate('', [1 => 'PHP'])['scores']);
        $this->assertSame([1 => 0.0], $service->calculate('the system', [1 => 'research study'])['scores']);
        $this->assertSame([], $service->calculate('PHP', [])['scores']);
        $this->assertSame([], $service->calculate('', [])['proposal_vector']);
    }

    public function test_document_order_and_uniform_repetition_do_not_change_similarity(): void
    {
        $service = app(SimilarityService::class);
        $first = $service->calculate('web database', ['a' => 'web database', 'b' => 'network database']);
        $second = $service->calculate('web database web database', ['b' => 'network database', 'a' => 'web database']);
        $this->assertSame($first['inverse_document_frequencies'], $second['inverse_document_frequencies']);
        $this->assertEqualsWithDelta($first['scores']['a'], $second['scores']['a'], 1e-10);
        $this->assertEqualsWithDelta($first['scores']['b'], $second['scores']['b'], 1e-10);
        $this->assertEqualsWithDelta(100, $service->calculate('C++', ['same' => 'C++'])['scores']['same'], 1e-10);
        $this->assertSame(0.0, $service->calculate('C++', ['different' => 'C#'])['scores']['different']);
    }

    public function test_document_frequency_counts_documents_instead_of_repeated_tokens(): void
    {
        $result = app(SimilarityService::class)->calculate('php php php', ['a' => 'php php', 'b' => 'sql']);
        $this->assertEqualsWithDelta(log(4 / 3) + 1, $result['inverse_document_frequencies']['php'], 1e-12);
        $this->assertEqualsWithDelta(log(4 / 2) + 1, $result['inverse_document_frequencies']['sql'], 1e-12);
    }

    public function test_non_string_faculty_documents_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SimilarityService::class)->calculate('PHP', ['faculty' => null]);
    }

    public function test_unfinished_analysis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SimilarityService::class)->forProposal(new ProposalAnalysis, []);
    }

    public function test_unsaved_faculty_profiles_are_rejected(): void
    {
        $analysis = new ProposalAnalysis;
        $analysis->analyzed_at = now();
        $this->expectException(InvalidArgumentException::class);
        app(SimilarityService::class)->forProposal($analysis, [new FacultyProfile]);
    }

    public function test_saved_analysis_and_current_expertise_are_used_without_preferences_or_proficiency_weighting(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'S-14', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Development', 'file_path' => 'step14.pdf', 'original_filename' => 'step14.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Objectives: Web development and database systems.';
        $analysis->save();
        $analysis = app(ProposalAnalysisService::class)->analyze($proposal);
        $before = $analysis->toArray();
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $emptyFaculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $entry = $faculty->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 10]);
        $faculty->load('expertise');
        $service = app(SimilarityService::class);
        $result = $service->forProposal($analysis, [$faculty, $emptyFaculty, $faculty]);
        $expected = $service->calculate("Web Development\nWeb development and database systems.", [$faculty->id => 'Web Development', $emptyFaculty->id => '']);
        $this->assertSame($expected, $result);
        $this->assertSame(0.0, $result['scores'][$emptyFaculty->id]);
        $entry->update(['proficiency_score' => 100]);
        $faculty->preferences()->create(['preference_type' => 'technology', 'preference_value' => 'PHP']);
        $this->assertSame($result, $service->forProposal($analysis, [$faculty, $emptyFaculty]));
        $entry->update(['expertise_area' => 'Networking']);
        $this->assertSame(0.0, $service->forProposal($analysis, [$faculty, $emptyFaculty])['scores'][$faculty->id]);
        $this->assertSame($before, $analysis->fresh()->toArray());
        $this->assertSame('Web Development', $proposal->fresh()->title);
        $this->assertDatabaseCount('proposal_analyses', 1);
        $this->assertDatabaseCount('faculty_expertise', 1);
    }
}
