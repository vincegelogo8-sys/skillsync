# Step 15: Research Topic Alignment

## Inspection and scope

Step 13 provides multi-expertise strength; Step 14 provides shared-corpus TF-IDF
and cosine similarity. Step 15 adds a service that combines these existing
calculations using the required fixed weights. It needs no migration, new package,
route, or frontend changes. Existing data remains unchanged.

Complete source:

- [TopicAlignmentService.php](../app/Services/TopicAlignmentService.php)
  (`C:\xampp\htdocs\skillsync\app\Services\TopicAlignmentService.php`)
- [TopicAlignmentTest.php](../tests/Feature/TopicAlignmentTest.php)
  (`C:\xampp\htdocs\skillsync\tests\Feature\TopicAlignmentTest.php`)

Existing dependencies, unchanged:

- [SimilarityService.php](../app/Services/SimilarityService.php)
- [MultiExpertiseService.php](../app/Services/MultiExpertiseService.php)

## Formula and result

```text
Research Topic Alignment = (Cosine Similarity × 0.70)
                         + (Multi-Expertise Strength × 0.30)

Example: (80 × 0.70) + (90 × 0.30) = 56 + 27 = 83%
```

Both inputs use a 0–100 scale. Finite inputs are clamped individually to that range
before weighting; NaN and infinity raise `InvalidArgumentException`. Full precision
is retained. Display percentages using `number_format($score, 2)`.

The final recommendation's 40% Research Topic Alignment weight is **not applied
here**. Step 17 will apply it once. Multi-expertise is an internal topic-alignment
component, not an additional final criterion.

`calculate($cosineSimilarity, $multiExpertiseStrength)` returns:

| Field | Example value |
| --- | ---: |
| `cosine_similarity_score` | 80 |
| `multi_expertise_score` | 90 |
| `cosine_contribution` | 56 |
| `multi_expertise_contribution` | 27 |
| `topic_alignment_score` | 83 |

Weights remain fixed when a component is zero. For example, 100 cosine and zero
multi-expertise produce 70, while zero cosine and 100 multi-expertise produce 30.
No evidence produces zero; missing evidence does not redistribute weight.

## Saved-proposal integration

```php
$service = app(\App\Services\TopicAlignmentService::class);
$results = $service->forProposal($completedAnalysis, $facultyProfiles);
$result = $results[$facultyId];
```

Supply the full candidate collection together. The service:

1. Validates completed analysis, canonical required expertise, and saved faculty
   profiles. Duplicate faculty IDs count once; generators are supported.
2. Reads the authoritative saved title and queries current expertise for all
   supplied faculty in one query. Both component calculations use those same
   expertise rows, without relying on stale loaded relationships.
3. Calls the existing similarity calculation once with title plus extracted text
   and all faculty expertise documents, preserving one shared TF-IDF corpus.
4. Calls the existing multi-expertise calculation for each faculty's proficiency
   map and combines both component scores with 70/30 weights.

Results are keyed by faculty profile ID in supplied order. Each result includes
the five fields above, plus `required_count` and `expertise_breakdown` (required
area, proficiency score, missing flag). Faculty without expertise remain present
with zero scores. An empty candidate list returns an empty result. Completed
analysis with no required areas retains zero multi-expertise and its empty
breakdown, without suppressing a supported cosine score.

The method does not extract text, rerun analysis, write scores, rank candidates,
check advisory capacity, or assign advisers. Preferences, competency, and skills
assessment remain separate components. Future recommendation code will store the
component results. Future controllers must authorize access before displaying
them; this internal service adds no public endpoint.

## Exact testing commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=TopicAlignmentTest
vendor\bin\pint --test app/Services/TopicAlignmentService.php tests/Feature/TopicAlignmentTest.php
php artisan test --compact
php artisan tinker
```

For a manual, read-only calculation, enter in Tinker:

```php
$result = app(\App\Services\TopicAlignmentService::class)->calculate(80, 90);
$result;
number_format($result['topic_alignment_score'], 2); // "83.00"
exit;
```

Tests cover both specification examples, zero/maximum scores, clamping before
weighting, fixed weights with missing components, retained precision, non-finite
inputs, actual saved proposal analysis, agreement with both prior services,
duplicate candidates, generators, current proficiency changes, empty candidates,
invalid requirements/profiles, and preservation of original analysis records.
Database tests use isolated in-memory SQLite.

There is no new page or button in Step 15. Ranking UI remains Step 18. Stop after
this step; Step 16 is Preference Compatibility.

## Verification results

- Targeted tests: **20 passed, 63 assertions**.
- Full application suite: **299 passed, 2,390 assertions**.
- Pint passed for both new PHP files.
- No production data or schema changes were made.
