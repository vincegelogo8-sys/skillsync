<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OpenAIProposalAnalysisService
{
    public const UNAVAILABLE = 'Proposal analysis is temporarily unavailable. Your proposal has been saved. Please try again later.';

    public function __construct(private ResearchConceptNormalizer $normalizer) {}

    public function analyze(string $text): array
    {
        $reason = 'configuration_or_input';
        $httpStatus = null;
        try {
            $key = config('proposal_analysis.openai.api_key');
            if (! is_string($key) || trim($key) === '' || trim($text) === '' || mb_strlen($text) > config('proposal_analysis.openai.max_input_characters')) {
                throw new \RuntimeException('Analysis configuration or input unavailable.');
            }
            $timeout = max(1, (int) config('proposal_analysis.openai.timeout'));
            $executionLimit = (int) ini_get('max_execution_time');
            if ($executionLimit > 0) {
                $elapsed = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
                $timeout = min($timeout, (int) floor($executionLimit - $elapsed - 5));
                if ($timeout < 1) {
                    throw new \RuntimeException('Insufficient request time remaining.');
                }
            }
            // Do not throw an HTTP RequestException: its message can contain response data.
            // No automatic retries: explicit user retries avoid duplicate paid requests.
            $reason = 'request_failed';
            $response = Http::withToken($key)->acceptJson()->asJson()
                ->connectTimeout(min(10, $timeout))->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('proposal_analysis.openai.model'),
                    'store' => false,
                    'instructions' => config('proposal_analysis.prompt'),
                    'input' => [['role' => 'user', 'content' => $text]],
                    'max_output_tokens' => 2000,
                    'text' => ['format' => [
                        'type' => 'json_schema', 'name' => 'proposal_concepts', 'strict' => true,
                        'schema' => $this->schema(),
                    ]],
                ]);
            $httpStatus = $response->status();
            if (! $response->successful()) {
                // Only log a fixed category, never provider-supplied error messages.
                $code = $response->json('error.code');
                $reason = match (true) {
                    in_array($code, ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active'], true) => 'quota_or_billing',
                    $httpStatus === 429 => 'rate_limited',
                    $httpStatus === 401 || $httpStatus === 403 => 'authentication_or_permission',
                    $httpStatus >= 500 => 'provider_unavailable',
                    default => 'request_rejected',
                };
                throw new \RuntimeException('Analysis unavailable.');
            }
            $reason = 'incomplete_response';
            if ($response->json('status') !== 'completed') {
                throw new \RuntimeException('Analysis unavailable.');
            }
            $reason = 'invalid_response';
            $texts = [];
            foreach ($response->json('output', []) as $item) {
                if (($item['type'] ?? '') !== 'message') {
                    continue;
                }
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'refusal') {
                        $reason = 'refused_response';
                        throw new \RuntimeException('Analysis unavailable.');
                    }
                    if (($part['type'] ?? '') === 'output_text') {
                        $texts[] = $part['text'];
                    }
                }
            }
            if (count($texts) !== 1 || ! is_string($texts[0]) || strlen($texts[0]) > 16000) {
                throw new \RuntimeException('Invalid analysis response.');
            }

            return $this->validate(json_decode($texts[0], true, 16, JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            // Intentionally discard exception details, payloads, text, and credentials.
            Log::warning('Proposal AI analysis unavailable.', [
                'category' => $exception instanceof ConnectionException ? 'connection_or_timeout' : $reason,
                'http_status' => $httpStatus,
            ]);
            throw ValidationException::withMessages(['analysis' => self::UNAVAILABLE]);
        }
    }

    public function schema(): array
    {
        $string = ['type' => 'string', 'minLength' => 1, 'maxLength' => 120];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['primary_research_area', 'research_concepts', 'technical_concepts'],
            'properties' => [
                'primary_research_area' => $string,
                'research_concepts' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => $string],
                'technical_concepts' => ['type' => 'array', 'maxItems' => 12, 'items' => $string],
            ],
        ];
    }

    private function validate(mixed $data): array
    {
        if (! is_array($data) || count($data) !== 3 || array_diff(array_keys($this->schema()['properties']), array_keys($data))) {
            throw new \UnexpectedValueException;
        }
        foreach (['research_concepts', 'technical_concepts'] as $field) {
            if (! is_array($data[$field]) || ! array_is_list($data[$field]) || count($data[$field]) > 12) {
                throw new \UnexpectedValueException;
            }
        }
        foreach ([$data['primary_research_area'], ...$data['research_concepts'], ...$data['technical_concepts']] as $value) {
            // Plain labels only, preserving technical punctuation (C++, C#, .NET, Node.js).
            if (! is_string($value) || mb_strlen(trim($value)) < 1 || mb_strlen($value) > 120 || ! preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}\p{Zs}+#.(),\/&\-]*\z|\A\.NET\z/iu', $value)) {
                throw new \UnexpectedValueException;
            }
        }
        $research = $this->normalizer->concepts($data['research_concepts']);
        if ($research === []) {
            throw new \UnexpectedValueException;
        }

        $normalized = [
            'primary_research_area' => $this->normalizer->text($data['primary_research_area']),
            'research_concepts' => $research,
            'technical_concepts' => array_values(array_diff($this->normalizer->concepts($data['technical_concepts']), $research)),
        ];
        foreach ([$normalized['primary_research_area'], ...$normalized['research_concepts'], ...$normalized['technical_concepts']] as $value) {
            if (mb_strlen($value) > 120) {
                throw new \UnexpectedValueException;
            }
        }

        return $normalized;
    }
}
