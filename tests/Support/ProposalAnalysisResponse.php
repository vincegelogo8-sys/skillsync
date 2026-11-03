<?php

namespace Tests\Support;

class ProposalAnalysisResponse
{
    public static function concepts(): array
    {
        return [
            'primary_research_area' => 'Information Systems',
            'research_concepts' => ['web development', 'database systems', 'records management'],
            'technical_concepts' => ['Laravel', 'PHP', 'MySQL'],
        ];
    }

    public static function response(?array $concepts = null): array
    {
        return ['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($concepts ?? self::concepts())],
            ]],
        ]];
    }
}
