<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\ProposalAnalysisResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Avoid sharing compiled Blade files with the running local server.
        $compiledPath = storage_path('framework/testing-views');
        $this->app['files']->ensureDirectoryExists($compiledPath);
        config(['view.compiled' => $compiledPath]);
        $this->fakeProposalAnalysis();
    }

    protected function fakeProposalAnalysis(?array $response = null, int $status = 200): void
    {
        config(['proposal_analysis.openai.api_key' => 'test-key-not-real']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($response ?? ProposalAnalysisResponse::response(), $status),
        ]);
        // Workflow tests fake the local process; legacy OpenAI service tests above
        // remain isolated and never form part of the proposal workflow.
        $legacy = $response === null ? ProposalAnalysisResponse::concepts()
            : json_decode($response['output'][0]['content'][0]['text'] ?? '{}', true);
        $payload = isset($legacy['research_concepts']) ? ['keywords' => $legacy['research_concepts']] : ($response ?? []);
        config(['local_nlp.python' => PHP_BINARY]);
        Process::swap(new \Illuminate\Process\Factory);
        Process::preventStrayProcesses();
        Process::fake(['*concept_extractor.py*' => Process::result(
            output: json_encode($payload), exitCode: $status === 200 ? 0 : 1,
        )]);
    }
}
