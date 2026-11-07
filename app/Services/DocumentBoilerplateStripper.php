<?php

namespace App\Services;

/**
 * Removes page furniture (letterhead, repeated headers/footers, page numbers)
 * from extracted text so only the proposal's own content is stored and analyzed.
 *
 * Only the top and bottom of each page are ever touched, and a line is removed
 * only when the whole line matches, so body sentences that merely mention the
 * university are left alone.
 */
class DocumentBoilerplateStripper
{
    /** Non-empty lines at the top of a page treated as the header zone. */
    private const HEADER_LINES = 12;

    /** Non-empty lines at the bottom of a page treated as the footer zone. */
    private const FOOTER_LINES = 6;

    /** Whole-line patterns for the ISATU Miagao Campus letterhead. */
    private const LETTERHEAD = [
        '/^republic of the philippines$/imu',
        '/^iloilo science and technology(?:\n?\s*university)?$/imu',
        '/^miagao campus$/imu',
        '/^brgy\.? igtuba\b.*$/imu',
        '/^trunkline\b.*$/imu',
        '/^telefax\b.*$/imu',
        '/^https?:\/\/(?:www\.)?isatu\.edu\.ph\S*$/imu',
        '/^www\.isatu\.edu\.ph\S*$/imu',
        // Council name and document-type banner printed under the letterhead.
        '/^computer studies council$/imu',
        '/^concept paper$/imu',
        // Group / course / degree / section line from the concept paper form.
        '/^group\h*:.*(?:course|degree|section|specialization).*$/imu',
    ];

    private const PAGE_NUMBER = '/^(?:page\h*)?\d+(?:\h*(?:of|\/)\h*\d+)?$/iu';

    /**
     * Strip letterhead from the top of one block of text (a DOCX body or a single PDF page).
     */
    public function stripLetterhead(string $text): string
    {
        $lines = $this->lines($text);
        [$headEnd] = $this->zones($lines);

        $head = implode("\n", array_slice($lines, 0, $headEnd));
        foreach (self::LETTERHEAD as $pattern) {
            $head = preg_replace($pattern, '', $head) ?? $head;
        }

        return implode("\n", array_merge(explode("\n", $head), array_slice($lines, $headEnd)));
    }

    /**
     * Strip headers, footers and page numbers across a PDF's pages.
     *
     * @param  list<string>  $pages  raw text of each page, in order
     */
    public function stripPages(array $pages): string
    {
        $pageLines = array_map(fn (string $page) => $this->lines($page), $pages);
        $count = count($pageLines);

        // Count how many pages each header/footer line shows up on.
        $seen = [];
        foreach ($pageLines as $lines) {
            $keys = [];
            foreach ($this->zoneIndexes($lines) as $i) {
                $key = $this->key($lines[$i]);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
            foreach (array_keys($keys) as $key) {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }
        $threshold = max(2, (int) ceil($count / 2));

        $kept = [];
        foreach ($pageLines as $lines) {
            $zone = array_flip($this->zoneIndexes($lines));
            $out = [];
            foreach ($lines as $i => $line) {
                if (isset($zone[$i])) {
                    if (
                        $count >= 2 && mb_strlen($line) < 200
                        && ($seen[$this->key($line)] ?? 0) >= $threshold
                    ) {
                        continue;
                    }
                    if (preg_match(self::PAGE_NUMBER, $line)) {
                        continue;
                    }
                }
                $out[] = $line;
            }
            $kept[] = $this->stripLetterhead(implode("\n", $out));
        }

        return implode("\n\n", $kept);
    }

    /**
     * Start the text at the "Proposed Title" line, dropping anything printed
     * before it (group line, form headings, etc.). Only the first lines are
     * searched, and the text is returned unchanged when no such line exists.
     */
    public function startAtProposedTitle(string $text): string
    {
        $lines = explode("\n", $text);

        foreach (array_slice($lines, 0, 25, true) as $i => $line) {
            if (preg_match('/^\h*proposed\h+title\b/iu', $line)) {
                return implode("\n", array_slice($lines, $i));
            }
        }

        return $text;
    }

    /**
     * Drop lines that hold no letters or digits (stray "+", "|", rule lines from logos and shapes).
     */
    public function dropSymbolOnlyLines(string $text): string
    {
        $lines = array_filter(
            explode("\n", $text),
            fn (string $line) => trim($line) === '' || preg_match('/[\p{L}\p{N}]/u', $line)
        );

        return implode("\n", $lines);
    }

    /** @return list<string> trimmed lines with inner whitespace collapsed */
    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);

        return array_map(
            fn (string $line) => trim((string) preg_replace('/\h+/u', ' ', $line)),
            explode("\n", $text)
        );
    }

    /**
     * Index (exclusive) where the header zone ends, and index where the footer zone starts.
     *
     * @param  list<string>  $lines
     * @return array{0:int,1:int}
     */
    private function zones(array $lines): array
    {
        $headEnd = 0;
        $seen = 0;
        foreach ($lines as $i => $line) {
            if ($line !== '' && ++$seen > self::HEADER_LINES) {
                break;
            }
            $headEnd = $i + 1;
        }

        $footStart = count($lines);
        $seen = 0;
        for ($i = count($lines) - 1; $i >= $headEnd; $i--) {
            if ($lines[$i] !== '' && ++$seen > self::FOOTER_LINES) {
                break;
            }
            $footStart = $i;
        }

        return [$headEnd, $footStart];
    }

    /**
     * @param  list<string>  $lines
     * @return list<int> indexes of non-empty lines in the header or footer zone
     */
    private function zoneIndexes(array $lines): array
    {
        [$headEnd, $footStart] = $this->zones($lines);
        $indexes = [];
        foreach ($lines as $i => $line) {
            if ($line !== '' && ($i < $headEnd || $i >= $footStart)) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /** Page-independent form of a line, so "Page 2" and "Page 3" count as the same line. */
    private function key(string $line): string
    {
        return mb_strtolower((string) preg_replace('/\d+/u', '#', $line));
    }
}