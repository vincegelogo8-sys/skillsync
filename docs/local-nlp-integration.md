# Local proposal analysis integration

## File inventory

Created:

- `app/Services/LocalNlpService.php`
- `app/Services/ProposalRtaTextBuilder.php`
- `config/local_nlp.php`
- `tests/Feature/LocalNlpServiceTest.php`
- `tests/Feature/LocalNlpIntegrationTest.php`
- `docs/local-nlp-integration.md`

Modified for this integration:

- `.env.example`
- `app/Models/ProposalAnalysis.php`
- `app/Services/ProposalAnalysisService.php`
- `app/Services/ProposalExtractionService.php` (comments only)
- `app/Services/ProposalTextNormalizer.php`
- `config/proposal_analysis.php` (legacy-settings comment only)
- `resources/views/student/proposal/analysis.blade.php`
- `resources/views/student/proposal/create.blade.php`
- `tests/TestCase.php`
- `tests/Feature/ProposalAnalysisTest.php`
- `tests/Feature/ProposalSectionsTest.php`
- `tests/Feature/ProposalQuotaFailureTest.php`
- `tests/Feature/OpenAIProposalAnalysisTest.php` (isolate the legacy service test)
- `nlp/concept_extractor.py`
- `nlp/verify_offline.py`
- `nlp/test_concepts.py`
- `nlp/README.md`
- `nlp/CONCEPT_EXTRACTION.md`
- `nlp/quality_results.json` (regenerated development measurements)

Generated test artifact: `storage/app/testing/local-nlp-integration.json`.
Existing unrelated workspace edits were preserved. No package installation,
environment recreation, model/cache deletion, database migration, or live-record
modification was needed.

Validation: full Laravel run 359 passed / 1 opt-in skip (2,910 assertions); the
real-model integration separately passed 87 assertions. After an additional
numeric-object schema rejection case was added, the focused service suite passed
17 tests / 46 assertions. Python quality, unchanged selection/internal-score
regressions, production/debug schema, and new STDIN checks all passed under the
offline verifier with zero network attempts.

## Workflow

Laravel still extracts PDF/DOCX text using `DocumentExtractionService` through
the existing bounded `DocumentExtractionRunner`. It normalizes extraction
artifacts, then invokes the local CPU NLP process once per analysis. Upload,
explicit analysis, extraction retry, and refresh all use `ProposalAnalysisService`.
Refresh reads the original file again, saves replacement text, regenerates
concepts, and recalculates already-existing recommendations. It does not delete
the uploaded file. Recommendation generation itself never invokes Python.

## Process contract and configuration

`config/local_nlp.php` defaults to enabled, a 120-second timeout, and these
project-relative assets (resolved through `base_path()`):

```text
nlp/.venv/Scripts/python.exe nlp/concept_extractor.py --stdin
```

On non-Windows systems the default interpreter is `nlp/.venv/bin/python`.
`LOCAL_NLP_PYTHON` can override the interpreter with a full executable path;
an empty setting uses the default. It is an executable path, not a shell command.
`LOCAL_NLP_ENABLED=false` disables analysis with a controlled configuration error;
it never activates a fallback. `LOCAL_NLP_TIMEOUT` controls the process timeout.
The process also respects the remaining web PHP execution time with five seconds
reserved for handling the result. Web-server/request limits must accommodate cold
CPU initialization; otherwise users receive a retryable local-analysis error.

Laravel passes an argument array to its Process facility, not an interpolated
shell command. Proposal content is UTF-8 JSON on STDIN:

```json
{"title":"Plant Disease Detection","text":"The complete extracted proposal text..."}
```

The Python `--stdin` mode is production-only; diagnostics are forbidden with it.
It identifies sections, applies its existing section cleaning, joins wrapped
lines within sections, and supplies the saved proposal title as the title section.
Input is bounded at 120,000 characters by Laravel (rejected intact, never silently
truncated); Python also caps STDIN at 4 MB. The ordinary file/debug CLI remains
available for developers.

Only this response shape is accepted:

```json
{"keywords":["machine learning","image classification"]}
```

`LocalNlpService` requires a JSON object with exactly one `keywords` array, at most
12 string values, and no additional fields or scored objects. It trims/collapses
whitespace, lowercases, and removes exact duplicates. It rejects blank/invalid
labels, markup, control characters, percentages, oversized output, empty results,
and nonzero exits. Both stdout and stderr count toward a 64 KiB output limit,
checked while streaming and again on completion. Technical logs contain only a
fixed failure category and optional exit status: never raw proposal text, stderr,
filesystem paths, or Python tracebacks. Students see:

> Local proposal analysis could not be completed. Please verify the local NLP installation and try again.

No network fallback exists. The old OpenAI service and its isolated legacy tests
remain in the repository but are no longer dependencies of the proposal workflow.
Obsolete OpenAI settings (including a populated credential) were removed from
`.env.example`; existing private `.env` credentials were not read or changed.

## Persistence and workflow safeguards

There are **no new tables, migrations, or live-data migrations**. Existing JSON
`proposal_analyses.keywords` stores the concept strings, e.g.:

```json
["machine learning","image classification"]
```

`research_representation` stores the deterministic TF-IDF input. Legacy generated
primary-area/technology classifications are cleared on successful local reanalysis
because the local extractor returns a single unified concept list. The old
abstract field remains untouched. The UI now displays only Research Concepts and
the analysis timestamp; it does not expose internal NLP values.

The version is `local-concepts-v1`. Old GPT analyses require explicit reanalysis
before new recommendations can be generated. No automatic bulk reanalysis occurs.
Existing pending/failed status, attempt-ID, concurrent-change detection, and
transaction safeguards are preserved. A failed refresh saves newly extracted
text but preserves prior concepts/recommendations, marks analysis failed, and
blocks new matching until successful reanalysis. Saved old results are not
presented as current while analysis is failed/stale.

## Exact RTA text

`ProposalRtaTextBuilder` produces newline-separated text in this order:

```text
normalized title
normalized title
normalized title
keyword 1
keyword 2
...remaining keywords
keyword 1
keyword 2
...remaining keywords
supporting context
```

Title has three copies as the topic declaration; each concept has two copies as
validated topical evidence; context has one copy as supporting evidence. These
are fixed textual emphasis levels, never KeyBERT/MiniLM score weights.

Supporting context reuses `ProposalTextNormalizer::relevant()` (the existing
Laravel noise filter), with administrative agenda/researcher-label handling
extended for the actual uploaded template. From cleaned lowercase text, select
unique sentences in document order containing at least one whole extracted
concept; use at most 80 whitespace-delimited words, truncating the last selected
sentence at that boundary. No extra context is invented when none matches.
References, acknowledgements, approval material, author labels, TOC entries,
page numbers and repeated institutional headers are filtered conservatively.
The PHP cleaner supports context selection only; it does not generate a second
set of keywords. Python remains the authority for concept extraction.

For example, a title `Plant Vision`, concept `image classification`, and context
`Image classification detects plant disease.` result in:

```text
Plant Vision
Plant Vision
Plant Vision
image classification
image classification
image classification detects plant disease.
```

`SimilarityService` uses this text and the existing faculty expertise registry
in the same TF-IDF corpus. TF, smoothed IDF, tokenization, and cosine calculation
remain unchanged. `TopicAlignmentService` and `RecommendationService` retain their
existing RTA-only formulas. MiniLM embeddings and relevance numbers never enter
Laravel. Advisory capacity, faculty expertise, Gmail notifications, authentication,
roles, adviser requests, and percentage-only recommendation presentation are
unchanged. Different TF-IDF input can legitimately change RTA percentages; the
three/two/one emphasis is a conservative starting design, not a validated optimum.

## Validation and commands

Run from the project root:

```powershell
php artisan test --compact
& .\nlp\.venv\Scripts\python.exe .\nlp\verify_offline.py --quality

# Opt-in real model integration, using an in-memory test DB and an isolated disk:
$env:SKILLSYNC_REAL_NLP = '1'
php artisan test --compact --filter=LocalNlpIntegrationTest
Remove-Item Env:SKILLSYNC_REAL_NLP
```

The real integration test exercises upload, PHP PDF/DOCX extraction, Python,
saved concepts, rendered proposal/recommendation pages, TF-IDF RTA, and refresh.
It uses `verify_offline.py --stdin` as a test-only wrapper around the exact Python
production entry point: empty HF cache, no API-key/token environment variables,
and blocked/recorded DNS/socket connections. The wrapper's verification message
goes to stderr; stdout remains production JSON. The normal app executes
`concept_extractor.py --stdin` directly, with explicit offline environment flags.

Measured real integration on this Windows/XAMPP machine:

| Operation | End-to-end seconds |
| --- | ---: |
| Wrapped PDF fixture: upload through recommendations | 25.415 |
| Same-content DOCX fixture: upload through recommendations | 28.377 |
| Re-extract/reanalyze DOCX and refresh existing recommendations | 22.940 |
| Existing uploaded proposal, analyzed as an isolated copy | 24.151 |

Both same-content fixtures returned the identical ten keywords and identical RTA
values against the two test faculty expertise records: 50.77428616 and 0.00000000.
The uploaded file hash and recommendation IDs survived refresh. The existing
upload produced ten concepts and calculated RTA in the isolated database; its
live records and original file were not modified. These are test faculty values,
not a change to the user's actual faculty recommendations.

Detailed local results are in `storage/app/testing/local-nlp-integration.json`.
No live proposal text or personal author details are saved in that report.

Physical network adapters were not disabled; the exercised Python networking
paths were blocked and zero attempts occurred, while Laravel HTTP requests and
mail were forbidden/faked. For the final deployment check, disconnect Wi-Fi or
Ethernet, upload or refresh a proposal in the browser, and confirm concepts and
RTA appear. Gmail delivery itself naturally still needs network access; proposal
analysis does not invoke it.

## Remaining limits

The later live MySQL migration repair and actual student-browser upload/refresh
validation are recorded in [schema-repair.md](schema-repair.md), including the
Windows subprocess environment and request-timeout fixes.

Each analysis starts one fresh Python process. The measured 20–30-second cold
startup dominates latency; no persistent server was introduced. Identical text
fixtures are consistent across formats, but scanned PDFs (no OCR), genuine text
differences, reading-order differences and unfamiliar templates can change results.
Real-proposal output still includes some weak phrases (for example `campus
dashboard-a` and `staff`); integration does not claim expert-validated concept
quality. Further extraction tuning and RTA representation evaluation should use
human-labelled real proposals separately from this integration.
