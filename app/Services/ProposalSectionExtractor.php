<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ProposalSectionExtractor
{
    /** Preserve boundaries until sections have been identified. */
    public function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\f", "\u{2028}", "\u{2029}"], "\n", $text);
        $text = preg_replace('/\x{00AD}\h*\n\h*/u', '', $text);
        $text = str_replace("\u{00AD}", '', $text);
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        // Preserve common meaningful hyphens; repair discretionary word breaks.
        $text = preg_replace_callback('/(\p{L}+)-\h*\n\h*(\p{Ll}+)/u', function ($match) {
            $hyphen = in_array(mb_strtolower($match[1]), ['web', 'real', 'face', 'to', 'based', 'school', 'campus'], true) ? '-' : '';

            return $match[1].$hyphen.$match[2];
        }, $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/\h+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /** The upload title is trusted when supplied; otherwise retain every candidate. */
    public function extract(string $text, ?string $storedTitle = null): array
    {
        $text = $this->normalize($text);
        $sections = ['title' => [], 'objectives' => []];
        $active = null;
        $headings = 'proposed\h+title|objectives|researcher(?:\h*/\h*s|s)?|priority\h+agenda'
            .'|significance(?:\h+of\h+the\h+study)?|(?:participants|respondents|informants|evaluators)(?:\h*/\h*(?:participants|respondents|informants|evaluators))*'
            .'|methodology(?:\h*/\h*research\h+design)?|research\h+design|expected\h+outputs?(?:\h*/\h*s)?'
            .'|scope(?:\h+and\h+limitations)?|name\h+and\h+signature\h+of\h+the\h+student\h+researchers'
            .'|group|course(?:\h*/\h*year\h*/\h*section)?|abstract|executive\h+summary|summary|introduction|background(?:\h+of\h+the\h+study)?|references|budget|timeline';

        // PDF headings can themselves wrap, especially the participants label.
        $text = preg_replace_callback('~^('.str_replace('\\h', '\\s', $headings).')\h*(?=:|$)~imu',
            fn ($match) => preg_replace('/\s+/u', ' ', $match[0]), $text);
        $preamble = [];
        $seenHeading = false;

        foreach (explode("\n", $text) as $line) {
            // Page furniture must not end a section that continues on the next page.
            if ($line === '' || preg_match('/^(?:(?:page\h*)?\d+(?:\h*(?:of|\/)\h*\d+)?|\[page\h+break\]|concept\h+paper|republic\h+of\h+the\h+philippines)$/iu', $line)) {
                continue;
            }
            if (isset($preamble[$line])) {
                continue;
            }
            if (preg_match('~^(?:(?:\d+\.?|[IVX]+\.)\h+)?('.$headings.')\h*(?::\h*(.*))?$~iu', $line, $match)) {
                $seenHeading = true;
                $name = mb_strtolower(preg_replace('/\h+/u', ' ', $match[1]));
                $active = match ($name) {
                    'proposed title' => 'title',
                    'objectives' => 'objectives',
                    default => null,
                };
                if ($active !== null && trim($match[2] ?? '') !== '') {
                    $sections[$active][] = $match[2];
                }

                continue;
            }
            if ($active !== null) {
                $sections[$active][] = $line;
            } elseif (! $seenHeading) {
                // Repeated institutional headers are excluded by exact text,
                // without discarding objective statements about a campus.
                $preamble[$line] = true;
            }
        }

        $title = trim($storedTitle ?? '') !== ''
            ? $this->joinLines(explode("\n", $this->normalize($storedTitle)))
            : $this->joinLines($sections['title']);
        $objectives = $this->joinLines($sections['objectives']);
        if ($title === '') {
            throw ValidationException::withMessages(['analysis' => 'Proposed Title section could not be identified in the uploaded proposal.']);
        }
        if ($objectives === '' || ! preg_match('/\p{L}/u', $objectives)) {
            throw ValidationException::withMessages(['analysis' => 'Objectives section could not be identified in the uploaded proposal.']);
        }

        return ['title' => $title, 'objectives' => $objectives, 'analysis_text' => $title."\n".$objectives];
    }

    private function joinLines(array $lines): string
    {
        $paragraphs = [];
        foreach ($lines as $line) {
            $newItem = preg_match('/^(?:title\h+\d+\h*:|\d+[.)]\h*|[\x{2022}\x{25CF}*-]\h+)/iu', $line);
            if ($newItem || $paragraphs === []) {
                $paragraphs[] = $line;
            } else {
                $paragraphs[array_key_last($paragraphs)] .= ' '.$line;
            }
        }

        return trim(implode("\n", $paragraphs));
    }
}
