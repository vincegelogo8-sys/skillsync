<?php

namespace App\Services;

use App\Models\ProposalAnalysis;
use App\Models\ResearchProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProposalAnalysisService
{
    public function analyze(
        ResearchProposal $proposal
    ): ProposalAnalysis {
        try {
            return DB::transaction(
                function () use ($proposal) {
                    $proposal = ResearchProposal::whereKey(
                        $proposal->id
                    )
                        ->lockForUpdate()
                        ->firstOrFail();

                    $analysis = $proposal
                        ->analysis()
                        ->first();

                    if (! $analysis) {
                        throw ValidationException::withMessages([
                            'analysis' => 'Extract readable document text before analyzing this proposal.',
                        ]);
                    }

                    foreach (
                        $this->identify(
                            $proposal->title,
                            $analysis->extracted_text
                        ) as $field => $value
                    ) {
                        $analysis->{$field} = $value;
                    }

                    if ($analysis->analyzed_at && ! $analysis->isDirty()) {
                        return $analysis;
                    }

                    $analysis->analyzed_at = now();

                    $analysis->save();

                    return $analysis;
                }
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'analysis' => 'Proposal analysis could not be saved. '
                    .'Your document and extracted text are preserved. Please retry.',
            ]);
        }
    }

    /**
     * Deterministic offline analysis.
     *
     * No recommendation scores or adviser assignments
     * are calculated here.
     */
    public function identify(
        string $title,
        string $text
    ): array {
        $sections = app(ProposalSectionExtractor::class)->extract($text, $title);
        $heading = $this->normalize($sections['title']);
        $body = $this->normalize($sections['objectives']);

        $combined = trim(
            $heading.' '.$body
        );

        $technologies = $this->technologies(
            $combined
        );

        $projectScores = $this->scores(
            config('proposal_analysis.project_types'),
            $heading,
            $body,
            $technologies
        );

        $expertiseScores = $this->scores(
            config('proposal_analysis.expertise'),
            $heading,
            $body,
            $technologies
        );

        $projectRules = config(
            'proposal_analysis.project_types',
            []
        );

        $expertiseRules = config(
            'proposal_analysis.expertise',
            []
        );

        $phrases = array_unique(
            array_merge(
                ...array_values($projectRules),
                ...array_values($expertiseRules)
            )
        );

        $keywords = [];

        foreach ($phrases as $phrase) {
            $phrase = $this->normalize(
                $phrase
            );

            if (
                $phrase !== ''
                && str_contains($phrase, ' ')
                && $this->contains(
                    $combined,
                    $phrase
                )
            ) {
                $keywords[$phrase] =
                    (
                        $this->contains(
                            $heading,
                            $phrase
                        )
                            ? 3
                            : 0
                    )
                    +
                    (
                        $this->contains(
                            $body,
                            $phrase
                        )
                            ? 1
                            : 0
                    );
            }
        }

        arsort(
            $keywords,
            SORT_NUMERIC
        );

        $selected = array_unique(
            array_merge(
                array_keys($keywords),
                array_map(
                    fn ($term) => mb_strtolower($term),
                    $technologies
                )
            )
        );

        /*
         * Repeated meaningful words are only a fallback
         * after controlled technical phrases.
         */
        preg_match_all(
            '/\p{L}{3,}/u',
            $combined,
            $tokens
        );

        $stopWords = config(
            'proposal_analysis.stop_words',
            []
        );

        $filteredTokens = array_values(
            array_filter(
                $tokens[0],
                fn ($token) => ! in_array(
                    $token,
                    $stopWords,
                    true
                )
            )
        );

        $frequencies = array_count_values(
            $filteredTokens
        );

        arsort(
            $frequencies,
            SORT_NUMERIC
        );

        foreach (
            $frequencies as $word => $count
        ) {
            if ($count < 2) {
                continue;
            }

            $alreadyCovered = collect(
                $selected
            )->contains(
                fn ($phrase) => $this->contains(
                    $phrase,
                    $word
                )
            );

            if (! $alreadyCovered) {
                $selected[] = $word;
            }
        }

        return [
            'keywords' => array_slice(
                array_values(
                    array_unique($selected)
                ),
                0,
                12
            ),

            'project_type' => array_key_first(
                $projectScores
            ),

            // Required internally by preference compatibility and topic alignment.
            'technologies' => $technologies,

            'identified_expertise_areas' => array_slice(
                array_keys(
                    $expertiseScores
                ),
                0,
                3
            ),
        ];
    }

    /**
     * Normalize text in the same predictable way regardless
     * of whether it came from PDF or DOCX.
     */
    private function normalize(
        string $text
    ): string {
        $text = mb_strtolower(
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
         * Normalize special spaces.
         */
        $text = str_replace(
            "\u{00A0}",
            ' ',
            $text
        );

        /*
         * Normalize Unicode dashes.
         */
        $text = str_replace(
            ['–', '—', '−'],
            '-',
            $text
        );

        /*
         * Treat hyphen as a word separator for rule matching.
         *
         * web-based -> web based
         *
         * This allows:
         * "web based"
         * and
         * "web-based"
         *
         * to match consistently.
         */
        $text = preg_replace(
            '/(?<=\p{L})-(?=\p{L})/u',
            ' ',
            $text
        );

        /*
         * Collapse all whitespace.
         */
        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim($text);
    }

    private function pattern(
        string $term
    ): string {
        $term = $this->normalize(
            $term
        );

        /*
         * Prevent:
         *
         * C matching C++ / C#
         * Java matching JavaScript
         */
        return '/(?<![\p{L}\p{N}_+#.])'
            .preg_quote($term, '/')
            .'(?![\p{L}\p{N}_+#])/u';
    }

    private function contains(
        string $text,
        string $term
    ): bool {
        if (
            trim($term) === ''
            || trim($text) === ''
        ) {
            return false;
        }

        return preg_match(
            $this->pattern($term),
            $text
        ) === 1;
    }

    private function technologies(
        string $text
    ): array {
        $technologyList = config(
            'preferences.technologies',
            []
        );

        $aliases = config(
            'proposal_analysis.aliases',
            []
        );

        $terms = [];

        foreach (
            $technologyList as $technology
        ) {
            foreach (
                $aliases[$technology]
                    ?? [$technology] as $alias
            ) {
                $terms[] = [
                    $technology,
                    $alias,
                ];
            }
        }

        /*
         * Process longest names first.
         *
         * React Native must not automatically become React.
         */
        usort(
            $terms,
            fn ($a, $b) => mb_strlen($b[1])
                <=>
                mb_strlen($a[1])
        );

        $found = [];

        foreach (
            $terms as [
                $technology,
                $alias,
            ]
        ) {
            if (
                $this->contains(
                    $text,
                    $alias
                )
            ) {
                $found[] =
                    $technology;

                $text = preg_replace(
                    $this->pattern($alias),
                    ' ',
                    $text
                );
            }
        }

        /*
         * Preserve the canonical technology order
         * from preferences.php.
         */
        return array_values(
            array_intersect(
                $technologyList,
                array_unique($found)
            )
        );
    }

    private function scores(
        array $rules,
        string $title,
        string $body,
        array $technologies
    ): array {
        $scores = [];

        $aliases = config(
            'proposal_analysis.aliases',
            []
        );

        $technologyList = config(
            'preferences.technologies',
            []
        );

        foreach (
            $rules as $category => $terms
        ) {
            $score = 0;

            foreach ($terms as $term) {
                $isTechnology = in_array(
                    $term,
                    $technologyList,
                    true
                );

                if (
                    $isTechnology
                    && ! in_array(
                        $term,
                        $technologies,
                        true
                    )
                ) {
                    continue;
                }

                $variants = $isTechnology
                    ? (
                        $aliases[$term]
                        ?? [$term]
                    )
                    : [$term];

                $titleMatch = false;

                $bodyMatch = false;

                foreach (
                    $variants as $variant
                ) {
                    if (
                        ! $titleMatch
                        && $this->contains(
                            $title,
                            $variant
                        )
                    ) {
                        $titleMatch = true;
                    }

                    if (
                        ! $bodyMatch
                        && $this->contains(
                            $body,
                            $variant
                        )
                    ) {
                        $bodyMatch = true;
                    }
                }

                /*
                 * Title terms are intentionally more important.
                 */
                $score +=
                    ($titleMatch ? 3 : 0)
                    +
                    ($bodyMatch ? 1 : 0);
            }

            if ($score > 0) {
                $scores[$category] =
                    $score;
            }
        }

        /*
         * Stable ties follow dictionary order.
         */
        arsort(
            $scores,
            SORT_NUMERIC
        );

        return $scores;
    }
}
