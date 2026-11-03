# Step 14: Local TF-IDF and Cosine Similarity

> **Historical implementation note:** The standalone similarity service described
> here remains in the codebase, but the current recommendation workflow does not
> call it and does not display a cosine/RTA percentage. Current recommendations
> use supported direct and evidence-backed related expertise categories instead.
> See [current matching behavior](rta-only-cleanup.md).

## Inspection and scope

The application stores extracted proposal text, validated analysis results,
canonical faculty expertise, and faculty proficiency. Similarity is local and
uses the saved analysis and current faculty records; it does not call an external
API or alter recommendation data.

Complete source:

- [SimilarityService.php](../app/Services/SimilarityService.php)
  (`C:\xampp\htdocs\skillsync\app\Services\SimilarityService.php`)
- [SimilarityTest.php](../tests/Feature/SimilarityTest.php)
  (`C:\xampp\htdocs\skillsync\tests\Feature\SimilarityTest.php`)

## Inputs and normalization

`forProposal($analysis, $faculties)` requires completed proposal analysis and saved
faculty profiles. Proposal analysis extracts computing skills from the proposal's
relevant extracted text and stores a research representation containing its title,
identified concepts, and supporting proposal context. Similarity compares that
saved representation; generation does not rerun extraction or NLP.

Each faculty document contains only the current expertise area names. One database
query retrieves entries for all supplied faculty IDs, avoiding stale preloaded
relationships and one query per faculty. Duplicate faculty IDs are collapsed.
Profiles with no expertise remain in the corpus as empty documents and score zero.
Legacy proficiency values, preferences, competency, and assessment scores are not
text inputs.

Text processing lowercases Unicode words, separates punctuation and hyphens,
removes stop words using `config/proposal_analysis.php`, and counts remaining
tokens. It preserves PHP, C++, C#, .NET, Node.js, AI, ML, NLP, IoT, SQL, and API
as individual tokens. NodeJS and dotnet map to Node.js and .NET. Case is normalized
but technical names are not inferred from other technologies.

## Exact algorithm

One corpus includes the proposal and **all supplied faculty documents**. Calculate
the candidates in one call so all comparisons use the same inverse document
frequencies. Changing the candidate corpus can change the scores.

For term `t` in document `d`:

```text
TF(t, d) = occurrences of t in d / total retained tokens in d
DF(t) = number of corpus documents containing t (once per document)
N = 1 proposal + number of faculty documents
IDF(t) = ln((N + 1) / (DF(t) + 1)) + 1
TF-IDF(t, d) = TF(t, d) × IDF(t)

Cosine percentage = 100 × dot(proposal vector, faculty vector)
                         / (proposal vector length × faculty vector length)
```

`ln` is the natural logarithm. Smoothed IDF keeps terms appearing in every document
usable, including a one-term corpus. Sparse vectors store only terms present in
each document. A zero-length vector returns 0 rather than dividing by zero. Scores
are clamped to 0–100 to handle floating-point drift and retain full precision;
display with `number_format($score, 2)`.

This is lexical similarity, not semantic understanding or a calibrated probability.
Unexpanded synonyms and acronyms can differ. The displayed Research Topic Alignment
is the cosine percentage alone. Capacity checks are separate and do not enter this
calculation.

## Service interface and manual example

`calculate($proposalText, $facultyDocuments)` accepts a string and strings keyed
by faculty ID (or other unique identifier). It returns:

- `scores`: cosine percentages keyed by the same identifiers, in input order.
- `inverse_document_frequencies`: shared term weights for inspection.
- `proposal_vector`: proposal TF-IDF vector.
- `faculty_vectors`: faculty TF-IDF vectors keyed by identifier.

No result is persisted in this step. Later recommendation code can store the
cosine component alongside the other scores. The service adds no public endpoint;
future controllers must authorize access before showing results.

Exact PowerShell commands:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=SimilarityTest
vendor\bin\pint --test app/Services/SimilarityService.php tests/Feature/SimilarityTest.php
php artisan test --compact
php artisan tinker
```

In Tinker, this example performs no database writes:

```php
$result = app(\App\Services\SimilarityService::class)->calculate('PHP SQL', [1 => 'PHP SQL', 2 => 'network routing', 3 => '']);
$result['scores']; // approximately [1 => 100.0, 2 => 0.0, 3 => 0.0]
$result['inverse_document_frequencies'];
exit;
```

Later model-based use:

```php
$result = app(\App\Services\SimilarityService::class)->forProposal($completedAnalysis, $facultyProfiles);
$facultyScore = $result['scores'][$facultyId];
```

Use the full eligible faculty collection for that proposal. There is no new button
or page in this step; the ranking Blade UI is Step 18.

## Testing

Regression tests verify a manually calculated three-document corpus, document
frequency versus repeated tokens, normalized term frequency, identical/disjoint
documents, empty inputs, stop-word-only documents, Unicode and technical tokens,
uniform repetition, corpus order, invalid input, incomplete analysis, saved-model
integration, current expertise updates, duplicate faculty IDs, and preservation
of the original analysis. Preferences and proficiency changes do not alter cosine.
Database tests run in isolated in-memory SQLite.

Stop after Step 14. Step 15 is Research Topic Alignment.

Verification completed:

- Targeted similarity tests: **9 passed, 36 assertions**.
- Full suite: **279 passed, 2,327 assertions**.
- Pint passed for both new PHP files.
- No production data or database schema changes were made.
