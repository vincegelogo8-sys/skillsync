<?php

namespace App\Services;

class ProposalRtaTextBuilder
{
    public function __construct(private ProposalTextNormalizer $normalizer) {}

    /** Fixed textual emphasis, never NLP numeric weights: title x3, concepts x2, context x1. */
    public function build(string $title, array $keywords, string $text): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $this->normalizer->normalize($title)));
        $concepts = implode("\n", $keywords);
        // Reuse existing document cleaning, rather than introducing a second section parser.
        $clean = $this->normalizer->relevant($text);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $context = [];
        $remaining = 80;
        foreach (array_unique($sentences) as $sentence) {
            $supported = false;
            foreach ($keywords as $keyword) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($keyword), '/').'(?![\p{L}\p{N}])/u', $sentence)) {
                    $supported = true;
                    break;
                }
            }
            if (! $supported) {
                continue;
            }
            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
            $taken = array_slice($words, 0, $remaining);
            $context[] = implode(' ', $taken);
            $remaining -= count($taken);
            if ($remaining <= 0) {
                break;
            }
        }

        return implode("\n", array_filter([$title, $title, $title, $concepts, $concepts, implode(' ', $context)]));
    }
}
