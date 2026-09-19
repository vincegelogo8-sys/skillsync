<?php

namespace App\Services;

use App\Models\FacultyExpertise;
use App\Models\FacultyProfile;
use App\Models\ProposalAnalysis;
use InvalidArgumentException;

class SimilarityService
{
    /**
     * Calculate similarity for a saved proposal.
     *
     * No database writes occur here.
     */
    public function forProposal(
        ProposalAnalysis $analysis,
        iterable $faculties
    ): array {
        if (! $analysis->analyzed_at) {
            throw new InvalidArgumentException(
                'Complete proposal analysis before calculating similarity.'
            );
        }

        $ids = [];

        foreach ($faculties as $faculty) {
            if (
                ! $faculty instanceof FacultyProfile
                || ! $faculty->exists
            ) {
                throw new InvalidArgumentException(
                    'Supply saved faculty profiles for similarity calculation.'
                );
            }

            $ids[] =
                $faculty->getKey();
        }

        $ids = array_values(
            array_unique($ids)
        );

        $expertise = FacultyExpertise::whereIn(
            'faculty_profile_id',
            $ids
        )
            ->orderBy('expertise_area')
            ->get()
            ->groupBy('faculty_profile_id');

        $documents = [];

        foreach ($ids as $id) {
            $documents[$id] =
                ($expertise->get($id)
                    ?? collect())
                    ->pluck(
                        'expertise_area'
                    )
                    ->unique()
                    ->implode(' ');
        }

        $title = $analysis
            ->researchProposal()
            ->value('title');

        if ($title === null) {
            throw new InvalidArgumentException(
                'The saved proposal is required for similarity calculation.'
            );
        }

        $text = app(ProposalSectionExtractor::class)
            ->extract($analysis->extracted_text, $title)['analysis_text'];

        return $this->calculate(
            $text,
            $documents
        );
    }

    /**
     * One TF-IDF corpus consists of:
     *
     * - one proposal document
     * - every supplied faculty expertise document
     *
     * @param  array<int|string, string>  $facultyDocuments
     * @return array{
     *     scores: array,
     *     inverse_document_frequencies: array,
     *     proposal_vector: array,
     *     faculty_vectors: array
     * }
     */
    public function calculate(
        string $proposalText,
        array $facultyDocuments
    ): array {
        $proposalTokens =
            $this->tokenize(
                $proposalText
            );

        $facultyTokens = [];

        foreach (
            $facultyDocuments as $id => $document
        ) {
            if (! is_string($document)) {
                throw new InvalidArgumentException(
                    'Faculty documents must be strings.'
                );
            }

            $facultyTokens[$id] =
                $this->tokenize(
                    $document
                );
        }

        $documentFrequencies = [];

        $allDocuments = [
            $proposalTokens,
            ...array_values(
                $facultyTokens
            ),
        ];

        foreach (
            $allDocuments as $tokens
        ) {
            foreach (
                array_unique($tokens) as $term
            ) {
                $documentFrequencies[$term] =
                    (
                        $documentFrequencies[$term]
                        ?? 0
                    )
                    + 1;
            }
        }

        $documentCount =
            1
            + count(
                $facultyDocuments
            );

        $idf = [];

        ksort(
            $documentFrequencies
        );

        foreach (
            $documentFrequencies as $term => $frequency
        ) {
            /*
             * Smoothed IDF.
             */
            $idf[$term] =
                log(
                    ($documentCount + 1)
                    /
                    ($frequency + 1)
                )
                + 1;
        }

        $proposalVector =
            $this->vector(
                $proposalTokens,
                $idf
            );

        $facultyVectors = [];

        $scores = [];

        foreach (
            $facultyTokens as $id => $tokens
        ) {
            $facultyVectors[$id] =
                $this->vector(
                    $tokens,
                    $idf
                );

            $scores[$id] =
                $this->cosine(
                    $proposalVector,
                    $facultyVectors[$id]
                );
        }

        return [
            'scores' => $scores,

            'inverse_document_frequencies' => $idf,

            'proposal_vector' => $proposalVector,

            'faculty_vectors' => $facultyVectors,
        ];
    }

    /**
     * Convert text to canonical tokens.
     *
     * PDF and DOCX therefore go through the same
     * TF-IDF preprocessing.
     */
    public function tokenize(
        string $text
    ): array {
        /*
         * Normalize line endings.
         */
        $text = str_replace(
            ["\r\n", "\r"],
            "\n",
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
         * Repair PDF line-wrap hyphenation.
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
         * Normalize case.
         */
        $text = mb_strtolower(
            $text
        );

        /*
         * Normalize Unicode spaces/dashes.
         */
        $text = str_replace(
            [
                "\u{00A0}",
                '–',
                '—',
                '−',
            ],
            [
                ' ',
                '-',
                '-',
                '-',
            ],
            $text
        );

        /*
         * Convert normal word hyphens to spaces.
         *
         * web-based
         * web based
         *
         * therefore produce the same tokens.
         */
        $text = preg_replace(
            '/(?<=\p{L})-(?=\p{L})/u',
            ' ',
            $text
        );

        /*
         * Remove standalone PDF page-number lines.
         */
        $text = preg_replace(
            '/^\s*(?:page\s*)?\d+\s*$/imu',
            ' ',
            $text
        );

        /*
         * Normalize remaining whitespace.
         */
        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        /*
         * Preserve important punctuation in:
         *
         * C++
         * C#
         * .NET
         * Node.js
         *
         * while extracting normal Unicode words.
         */
        preg_match_all(
            '/(?<![\p{L}\p{N}_])(?:c\+\+|c#|\.net|node\.js)(?![\p{L}\p{N}_])|[\p{L}][\p{L}\p{N}]*/u',
            $text,
            $matches
        );

        $configuredStopWords =
            config(
                'proposal_analysis.stop_words',
                []
            );

        /*
         * Normalize configured stop words too.
         */
        $stopWords = [];

        foreach (
            $configuredStopWords as $word
        ) {
            $stopWords[
                mb_strtolower(
                    trim($word)
                )
            ] = true;
        }

        $tokens = [];

        foreach (
            $matches[0] as $term
        ) {
            $term = trim(
                mb_strtolower($term)
            );

            /*
             * Normalize common spellings.
             */
            $term = [
                'nodejs' => 'node.js',
                'dotnet' => '.net',
            ][$term] ?? $term;

            if ($term === '') {
                continue;
            }

            if (
                isset(
                    $stopWords[$term]
                )
            ) {
                continue;
            }

            $tokens[] = $term;
        }

        return $tokens;
    }

    private function vector(
        array $tokens,
        array $idf
    ): array {
        /*
         * Explicitly handle an empty document.
         */
        if ($tokens === []) {
            return [];
        }

        $vector = [];

        $total = count(
            $tokens
        );

        foreach (
            array_count_values($tokens) as $term => $frequency
        ) {
            if (
                ! isset(
                    $idf[$term]
                )
            ) {
                continue;
            }

            $vector[$term] =
                (
                    $frequency
                    / $total
                )
                * $idf[$term];
        }

        ksort(
            $vector
        );

        return $vector;
    }

    /**
     * Sparse cosine similarity on a 0-100 scale.
     */
    private function cosine(
        array $left,
        array $right
    ): float {
        if (
            $left === []
            || $right === []
        ) {
            return 0.0;
        }

        $dot = 0.0;

        $leftSquared = 0.0;

        $rightSquared = 0.0;

        foreach (
            $left as $term => $weight
        ) {
            $dot +=
                $weight
                * (
                    $right[$term]
                    ?? 0
                );

            $leftSquared +=
                $weight
                * $weight;
        }

        foreach (
            $right as $weight
        ) {
            $rightSquared +=
                $weight
                * $weight;
        }

        if (
            $leftSquared === 0.0
            || $rightSquared === 0.0
        ) {
            return 0.0;
        }

        $score =
            $dot
            /
            (
                sqrt($leftSquared)
                * sqrt($rightSquared)
            )
            * 100;

        return max(
            0.0,
            min(
                100.0,
                $score
            )
        );
    }
}
