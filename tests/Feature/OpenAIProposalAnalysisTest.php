<?php

namespace Tests\Feature;

use App\Services\OpenAIProposalAnalysisService;
use App\Services\ProposalTextNormalizer;
use App\Services\ResearchConceptNormalizer;
use App\Services\SimilarityService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProposalAnalysisResponse;
use Tests\TestCase;

class OpenAIProposalAnalysisTest extends TestCase
{
    public function test_whole_proposal_uses_strict_response_schema_and_no_faculty_or_scores(): void
    {
        $result = app(OpenAIProposalAnalysisService::class)->analyze(app(ProposalTextNormalizer::class)->relevant("Background\nCrowdsourced repairs.\nMethodology\nTrack response times and incident reports.\nReferences\nUnrelated cited machine learning."));
        $this->assertSame(['web development', 'database systems', 'records management'], $result['research_concepts']);
        Http::assertSent(function ($request) {
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertFalse($request['store']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertFalse($request['text']['format']['schema']['additionalProperties']);
            $this->assertSame(['primary_research_area', 'research_concepts', 'technical_concepts'], array_keys($request['text']['format']['schema']['properties']));
            $this->assertStringContainsString('crowdsourced repairs', $request['input'][0]['content']);
            $this->assertStringContainsString('track response times', $request['input'][0]['content']);
            $this->assertStringNotContainsString('unrelated cited', $request['input'][0]['content']);
            $this->assertStringContainsString('Never recommend', $request['instructions']);
            $this->assertStringContainsString('untrusted document', $request['instructions']);

            return true;
        });
    }

    public function test_aliases_duplicates_generic_words_and_phrases_are_normalized(): void
    {
        $this->fakeProposalAnalysis(ProposalAnalysisResponse::response([
            'primary_research_area' => 'Artificial Intelligence',
            'research_concepts' => ['machine learning', 'Machine Learning', 'ML', 'system', 'facial recognition', 'recommendation engine'],
            'technical_concepts' => ['AI', 'NLP', 'IoT', 'GIS', 'DBMS', 'C++', 'C#', '.NET', 'Node.js', 'ML'],
        ]));
        $result = app(OpenAIProposalAnalysisService::class)->analyze('A supported proposal.');
        $this->assertSame(['machine learning', 'facial recognition', 'recommendation system'], $result['research_concepts']);
        $this->assertContains('natural language processing', $result['technical_concepts']);
        $this->assertNotContains('machine learning', $result['technical_concepts']);
        $this->assertContains('c++', $result['technical_concepts']);
        $this->assertContains('.net', $result['technical_concepts']);
        $normalizer = app(ResearchConceptNormalizer::class);
        $this->assertSame('email plain claim', $normalizer->text('Email plain claim'));
        $similarity = app(SimilarityService::class)->calculate('artificial intelligence natural language processing', ['aliases' => 'AI NLP', 'empty' => '']);
        $this->assertEqualsWithDelta(100, $similarity['scores']['aliases'], 1e-10);
        $this->assertSame(0.0, $similarity['scores']['empty']);
    }

    public static function invalid(): array
    {
        $good = ProposalAnalysisResponse::concepts();

        return [
            'invalid JSON' => ['not json'],
            'empty response' => [''],
            'missing field' => [json_encode(['research_concepts' => ['vision']])],
            'extra score' => [json_encode([...$good, 'rta' => 95])],
            'wrong scalar' => [json_encode([...$good, 'primary_research_area' => 9])],
            'object instead of list' => [json_encode([...$good, 'research_concepts' => ['bad' => 'vision']])],
            'wrong concept type' => [json_encode([...$good, 'research_concepts' => [9]])],
            'too many' => [json_encode([...$good, 'research_concepts' => array_fill(0, 13, 'vision')])],
            'too long' => [json_encode([...$good, 'research_concepts' => [str_repeat('a', 121)]])],
            'empty label' => [json_encode([...$good, 'research_concepts' => ['']])],
            'empty concepts' => [json_encode([...$good, 'research_concepts' => []])],
            'generic only' => [json_encode([...$good, 'research_concepts' => ['system', 'study']])],
            'HTML' => [json_encode([...$good, 'technical_concepts' => ['<script>alert(1)</script>']])],
            'code' => [json_encode([...$good, 'research_concepts' => ['<?php echo 1;']])],
            'markdown code' => [json_encode([...$good, 'research_concepts' => ['```php```']])],
        ];
    }

    #[DataProvider('invalid')]
    public function test_malformed_output_fails_with_a_safe_message(string $json): void
    {
        $response = ProposalAnalysisResponse::response();
        $response['output'][0]['content'][0]['text'] = $json;
        $this->fakeProposalAnalysis($response);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(OpenAIProposalAnalysisService::UNAVAILABLE);
        app(OpenAIProposalAnalysisService::class)->analyze('Proposal text.');
    }

    public static function failures(): array
    {
        return [[401], [429], [500], [503]];
    }

    #[DataProvider('failures')]
    public function test_api_errors_never_expose_provider_payload(int $status): void
    {
        $this->fakeProposalAnalysis(['error' => ['message' => 'sensitive-provider-message']], $status);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(OpenAIProposalAnalysisService::UNAVAILABLE);
        app(OpenAIProposalAnalysisService::class)->analyze('Proposal text.');
    }

    public function test_timeout_is_safe(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection('sensitive timeout details')]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(OpenAIProposalAnalysisService::UNAVAILABLE);
        app(OpenAIProposalAnalysisService::class)->analyze('Proposal text.');
    }

    public function test_missing_key_does_not_send_a_request(): void
    {
        config(['proposal_analysis.openai.api_key' => '']);
        try {
            app(OpenAIProposalAnalysisService::class)->analyze('Proposal text.');
            $this->fail('Missing key should fail.');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    public function test_long_proposal_is_never_silently_truncated(): void
    {
        config(['proposal_analysis.openai.max_input_characters' => 20]);
        try {
            app(OpenAIProposalAnalysisService::class)->analyze(str_repeat('whole proposal ', 20));
            $this->fail('Oversized input should fail.');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    public function test_incomplete_and_refused_responses_are_rejected(): void
    {
        foreach ([['status' => 'incomplete', 'output' => []], ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]]]] as $response) {
            $this->fakeProposalAnalysis($response);
            try {
                app(OpenAIProposalAnalysisService::class)->analyze('Proposal text.');
                $this->fail('Incomplete/refused response should fail.');
            } catch (ValidationException $exception) {
                $this->assertSame(OpenAIProposalAnalysisService::UNAVAILABLE, $exception->getMessage());
            }
        }
    }
}
