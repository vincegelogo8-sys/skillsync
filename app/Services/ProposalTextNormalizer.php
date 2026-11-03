<?php

namespace App\Services;

class ProposalTextNormalizer
{
    /** Preserve section boundaries while repairing extraction artifacts. */
    public function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\f", "\u{2028}", "\u{2029}"], "\n", $text);
        $text = preg_replace('/\x{00AD}\h*\n\h*/u', '', $text);
        $text = str_replace("\u{00AD}", '', $text);
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        $text = preg_replace_callback('/(\p{L}+)-\h*\n\h*(\p{Ll}+)/u', function ($match) {
            $hyphen = in_array(mb_strtolower($match[1]), ['web', 'real', 'face', 'to', 'based', 'school', 'campus'], true) ? '-' : '';

            return $match[1].$hyphen.$match[2];
        }, $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/\h+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    /** Conservative section filtering; ambiguous content is left for contextual analysis. */
    public function relevant(string $text, string $title = ''): string
    {
        $lines = explode("\n", $this->normalize($text));
        $counts = array_count_values(array_map('mb_strtolower', $lines));
        $content = [];
        $skip = false;
        foreach ($lines as $line) {
            if ($line === '' || preg_match('/^(?:(?:page\h*)?\d+(?:\h*(?:of|\/)\h*\d+)?|[ivxlcdm]+|\[page\h+break\])$/iu', $line)) {
                continue;
            }
            // Dotted leaders and trailing page numbers identify TOC entries, not sections.
            if (preg_match('/\.{2,}\s*\d+\s*$/u', $line)) {
                continue;
            }
            if (($counts[mb_strtolower($line)] ?? 0) > 1 && mb_strlen($line) < 160 && preg_match('/university|college|republic of|department of|all rights reserved|copyright/iu', $line)) {
                continue;
            }
            $heading = preg_replace('/^(?:\d+(?:\.\d+)*[.)]?|[ivx]+[.)])\h+/iu', '', $line);
            if (preg_match('/^(?:references|bibliography|works cited)\h*:?$/iu', $heading)) {
                if ($skip) {
                    continue;
                }
                break;
            }
            if (preg_match('/^(?:table of contents|approval sheet|certification|acknowledg(?:e)?ments?|dedication|administrative forms?|priority agenda(?:\h*\([^)]*\))?)\h*:?$/iu', $heading)) {
                $skip = true;

                continue;
            }
            if (preg_match('/^(?:chapter\h+[\divx]+|(?:proposed|research) title|abstract|introduction|background(?: of the study)?|statement of the problem|(?:general |specific )?objectives|scope(?: and (?:limitations|delimitations))?|significance(?: of the study)?|conceptual framework|review of related (?:literature|studies)|(?:technical |system |research )?methodology|research design)\h*(?::|$)/iu', $heading)) {
                $skip = false;
            }
            if ($skip || preg_match('/^(?:researcher(?:s|\/s)?|group|course|authors?|panel members?|approved by|submitted (?:by|to)|name and signature|signature|student (?:id|number)|email)\h*:/iu', $line)) {
                continue;
            }
            $content[] = preg_replace('/^(?:[\x{2022}\x{25CF}*-]|\d+[.)])\h+/u', '', $line);
        }

        // Line wrapping and capitalization should not distinguish PDF from DOCX.
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $this->normalize($title).' '.implode(' ', $content))));
    }
}
