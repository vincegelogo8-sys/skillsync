<?php

namespace App\Services;

use App\Exceptions\DocumentExtractionException;
use DOMDocument;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Reader\Word2007;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

class DocumentExtractionService
{
    public const EMPTY_PDF =
        'Unable to extract readable text from this PDF. '
        . 'The document may contain scanned pages. '
        . 'Please upload a text-based PDF or DOCX file.';

    public function extract(string $path, string $type): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DocumentExtractionException(
                'The uploaded document is unavailable. Please upload the document again.'
            );
        }

        if (filesize($path) > 10 * 1024 * 1024) {
            throw new DocumentExtractionException(
                'The document exceeds the 10 MB limit. Please upload a smaller file.'
            );
        }

        try {
            $text = match ($type) {
                'pdf' => $this->pdf($path),
                'docx' => $this->docx($path),
                default => throw new DocumentExtractionException(
                    'Only PDF and DOCX documents are supported.'
                ),
            };
        } catch (DocumentExtractionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new DocumentExtractionException(
                $type === 'pdf'
                    ? 'Unable to read this PDF. It may be encrypted or damaged. '
                        . 'Please upload an unencrypted text-based PDF.'
                    : 'Unable to read this DOCX file. '
                        . 'Please save it as a valid Word document and upload it again.'
            );
        }

        /*
         * IMPORTANT:
         * Both PDF and DOCX pass through exactly the same normalization.
         *
         * This reduces differences caused only by file formatting.
         */
        $text = $this->normalizeExtractedText($text);

        if (! preg_match('/[\p{L}\p{N}]/u', $text)) {
            throw new DocumentExtractionException(
                $type === 'pdf'
                    ? self::EMPTY_PDF
                    : 'Unable to extract readable text from this DOCX file. '
                        . 'Please upload a document containing text.'
            );
        }

        if (mb_strlen($text) > 1000000) {
            throw new DocumentExtractionException(
                'This document contains too much text to process. '
                . 'Please upload a shorter proposal.'
            );
        }

        return $text;
    }

    /**
     * Convert PDF/DOCX output into one consistent text representation.
     */
    private function normalizeExtractedText(string $text): string
    {
        $text = mb_convert_encoding(
            $text,
            'UTF-8',
            'UTF-8'
        );

        /*
         * Normalize line endings.
         */
        $text = str_replace(
            ["\r\n", "\r"],
            "\n",
            $text
        );

        /*
         * Convert form-feed/page breaks into normal line breaks.
         */
        $text = str_replace(
            "\f",
            "\n",
            $text
        );

        /*
         * Normalize non-breaking spaces.
         */
        $text = str_replace(
            "\u{00A0}",
            ' ',
            $text
        );

        /*
         * Remove soft hyphens.
         */
        $text = str_replace(
            "\u{00AD}",
            '',
            $text
        );

        /*
         * Repair words broken across PDF lines.
         *
         * Example:
         *
         * recommen-
         * dation
         *
         * becomes:
         *
         * recommendation
         */
        $text = preg_replace(
            '/(\p{L})-\h*\n\h*(\p{L})/u',
            '$1$2',
            $text
        );

        /*
         * Normalize Unicode dash characters.
         */
        $text = str_replace(
            ['–', '—', '−'],
            '-',
            $text
        );

        /*
         * Remove unsupported control characters.
         */
        $text = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '',
            $text
        );

        /*
         * Normalize spaces on each line.
         */
        $lines = preg_split('/\n/u', $text);

        $cleanLines = [];

        foreach ($lines as $line) {
            $line = preg_replace(
                '/[ \t]+/u',
                ' ',
                $line
            );

            $line = trim($line);

            /*
             * Ignore standalone page numbers:
             *
             * 1
             * Page 1
             * PAGE 2
             */
            if (
                preg_match(
                    '/^(?:page\s*)?\d+$/iu',
                    $line
                )
            ) {
                continue;
            }

            $cleanLines[] = $line;
        }

        $text = implode(
            "\n",
            $cleanLines
        );

        /*
         * Remove unnecessary spaces before punctuation.
         */
        $text = preg_replace(
            '/\s+([,.;:!?])/u',
            '$1',
            $text
        );

        /*
         * Collapse excessive blank lines.
         */
        $text = preg_replace(
            '/\n{3,}/u',
            "\n\n",
            $text
        );

        return trim($text);
    }

    private function pdf(string $path): string
    {
        $config = new Config;

        $config->setRetainImageContent(false);

        $config->setDecodeMemoryLimit(
            32 * 1024 * 1024
        );

        return (new Parser([], $config))
            ->parseFile($path)
            ->getText();
    }

    private function docx(string $path): string
    {
        $this->checkArchive($path);

        /*
         * Try PHPWord first.
         */
        try {
            $reader = new Word2007;

            /*
             * SKILLSYNC only needs textual content.
             */
            $reader->setImageLoading(false);

            $document = $reader->load($path);

            $sections = [];

            foreach ($document->getSections() as $section) {
                $sectionText = trim(
                    $this->elementText($section)
                );

                if ($sectionText !== '') {
                    $sections[] = $sectionText;
                }
            }

            $text = trim(
                implode("\n\n", $sections)
            );

            if ($text !== '') {
                return $text;
            }
        } catch (Throwable) {
            /*
             * If PHPWord cannot process complicated Word formatting,
             * use direct document.xml extraction instead.
             */
        }

        return $this->docxXmlText($path);
    }

    /**
     * Direct DOCX text fallback.
     *
     * Only reads word/document.xml.
     *
     * External links, images, charts, logos and other external
     * relationships are not loaded.
     */
    private function docxXmlText(string $path): string
    {
        $zip = new ZipArchive;

        if (
            $zip->open(
                $path,
                ZipArchive::RDONLY
            ) !== true
        ) {
            throw new DocumentExtractionException(
                'Unable to open this DOCX file. '
                . 'Please upload a valid Word document.'
            );
        }

        try {
            $xml = $zip->getFromName(
                'word/document.xml'
            );

            if (
                ! is_string($xml)
                || trim($xml) === ''
            ) {
                throw new DocumentExtractionException(
                    'No readable document content was found in this DOCX file.'
                );
            }
        } finally {
            $zip->close();
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML(
                $xml,
                LIBXML_NONET
                | LIBXML_NOERROR
                | LIBXML_NOWARNING
            );

            if (
                ! $loaded
                || $dom->doctype
            ) {
                throw new DocumentExtractionException(
                    'Unable to process this DOCX file.'
                );
            }

            $namespace =
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

            $paragraphs = [];

            foreach (
                $dom->getElementsByTagNameNS(
                    $namespace,
                    'p'
                ) as $paragraph
            ) {
                $parts = [];

                foreach (
                    $paragraph->getElementsByTagNameNS(
                        $namespace,
                        't'
                    ) as $textNode
                ) {
                    $parts[] = $textNode->nodeValue;
                }

                $paragraphText = trim(
                    implode('', $parts)
                );

                if ($paragraphText !== '') {
                    $paragraphs[] = $paragraphText;
                }
            }

            return trim(
                implode(
                    "\n",
                    $paragraphs
                )
            );
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previous
            );
        }
    }

    private function elementText(
        object $element,
        int $depth = 0
    ): string {
        if ($depth > 50) {
            throw new DocumentExtractionException(
                'This document has overly complex formatting. '
                . 'Please upload a simpler document.'
            );
        }

        if ($element instanceof TextBreak) {
            return "\n";
        }

        if ($element instanceof Table) {
            $rows = [];

            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cells[] = trim(
                        $this->elementText(
                            $cell,
                            $depth + 1
                        )
                    );
                }

                $rows[] = implode(
                    "\t",
                    $cells
                );
            }

            return implode(
                "\n",
                $rows
            );
        }

        if ($element instanceof AbstractContainer) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                $parts[] = $this->elementText(
                    $child,
                    $depth + 1
                );
            }

            return implode(
                $element instanceof TextRun
                    ? ''
                    : "\n",
                $parts
            );
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_object($text)) {
                return $this->elementText(
                    $text,
                    $depth + 1
                );
            }

            return is_string($text)
                ? $text
                : '';
        }

        return '';
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive;

        if (
            $zip->open(
                $path,
                ZipArchive::RDONLY
            ) !== true
        ) {
            throw new DocumentExtractionException(
                'Unable to open this DOCX file. '
                . 'Please upload a valid Word document.'
            );
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $total = 0;

            if (
                $zip->numFiles > 2000
                || $zip->locateName(
                    'word/document.xml'
                ) === false
            ) {
                throw new DocumentExtractionException(
                    'This DOCX file is invalid or too complex. '
                    . 'Please upload a valid Word document.'
                );
            }

            for (
                $i = 0;
                $i < $zip->numFiles;
                $i++
            ) {
                $stat = $zip->statIndex($i);

                if (! is_array($stat)) {
                    continue;
                }

                $total += (int) (
                    $stat['size'] ?? 0
                );

                if (
                    $total
                    > 50 * 1024 * 1024
                ) {
                    throw new DocumentExtractionException(
                        'This DOCX file expands beyond the processing limit. '
                        . 'Please upload a smaller document.'
                    );
                }

                /*
                 * Only XML and relationship files need validation.
                 */
                if (
                    ! preg_match(
                        '/\.(xml|rels)$/i',
                        $stat['name']
                    )
                ) {
                    continue;
                }

                if (
                    ($stat['size'] ?? 0)
                    > 10 * 1024 * 1024
                ) {
                    throw new DocumentExtractionException(
                        'This DOCX file contains an oversized document part. '
                        . 'Please upload a smaller document.'
                    );
                }

                $xml = $zip->getFromIndex($i);

                if (! is_string($xml)) {
                    throw new DocumentExtractionException(
                        'This DOCX file contains an unreadable document part.'
                    );
                }

                $dom = new DOMDocument;

                if (
                    ! $dom->loadXML(
                        $xml,
                        LIBXML_NONET
                        | LIBXML_NOERROR
                        | LIBXML_NOWARNING
                    )
                    || $dom->doctype
                ) {
                    throw new DocumentExtractionException(
                        'This DOCX file contains unsupported or malformed XML. '
                        . 'Please save a new Word document and upload it again.'
                    );
                }

                /*
                 * External relationships are intentionally not rejected.
                 *
                 * SKILLSYNC never fetches those resources.
                 */
                libxml_clear_errors();
            }
        } finally {
            libxml_clear_errors();

            libxml_use_internal_errors(
                $previous
            );

            $zip->close();
        }
    }
}