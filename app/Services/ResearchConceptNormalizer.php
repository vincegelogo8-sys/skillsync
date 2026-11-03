<?php

namespace App\Services;

class ResearchConceptNormalizer
{
    private const ALIASES = [
        'ai' => 'artificial intelligence',
        'ml' => 'machine learning',
        'nlp' => 'natural language processing',
        'iot' => 'internet of things',
        'gis' => 'geographic information system',
        'dbms' => 'database management system',
        'recommender system' => 'recommendation system',
        'recommendation engine' => 'recommendation system',
        'nodejs' => 'node.js',
        'dotnet' => '.net',
    ];

    public function text(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["\u{00A0}", "\u{2010}", "\u{2011}", "\u{2013}", "\u{2014}"], [' ', '-', '-', '-', '-'], $text);
        $text = preg_replace('/(?<=\p{L})-(?=\p{L})/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $aliases = self::ALIASES;

        return preg_replace_callback('/(?<![\p{L}\p{N}_])('.implode('|', array_map(fn ($alias) => preg_quote($alias, '/'), array_keys($aliases))).')(?![\p{L}\p{N}_])/u', fn ($match) => $aliases[$match[0]], $text);
    }

    public function concepts(array $concepts): array
    {
        $generic = ['system', 'systems', 'research', 'researcher', 'study', 'student', 'students', 'information', 'data', 'develop', 'determine', 'evaluate', 'time', 'application', 'project', 'technology', 'methodology'];
        $normalized = array_map(fn (string $concept) => $this->text($concept), $concepts);

        return array_values(array_unique(array_filter($normalized, fn ($concept) => $concept !== '' && ! in_array($concept, $generic, true))));
    }
}
