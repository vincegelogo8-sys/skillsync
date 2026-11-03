# Live MySQL schema repair — 5 October 2026

The missing field belonged to `skillsync.proposal_analyses`, not `research_proposals`. `ProposalAnalysis` supplies `analysis_status = pending` as a default attribute, so `ProposalExtractionService` includes it even when initially saving only extracted text. `ProposalAnalysisService` also needs the status and attempt UUID to track analysis and prevent an older attempt from overwriting a newer one.

The existing migration `2026_10_04_010000_add_proposal_analysis_status` was pending in live MySQL. The test suite uses freshly migrated, in-memory SQLite (`phpunit.xml`), so passing tests did not establish that the separate live database had been migrated.

## Applied migration

Ran `php artisan migrate --no-interaction`, followed by `php artisan optimize:clear`. The existing migration was applied in batch 17; no replacement migration was needed.

| Added column | MySQL type | Null/default |
| --- | --- | --- |
| `analysis_status` | `varchar(20)` | NOT NULL, default `pending` |
| `analysis_attempt_id` | `char(36)` | nullable, default NULL |

The migration backfills `completed` only for previously analyzed `gpt-concepts-v1` rows with a nonempty representation. Legacy rows without a version remain pending until explicitly reanalyzed. Current local analyses save `local-concepts-v1` and `completed` through the service.

Compared model/service fields and migrations against `SHOW COLUMNS FROM proposal_analyses`. The other required fields, including `primary_research_area`, `research_representation`, `analysis_version`, `analyzed_at`, `keywords`, and `extracted_text`, already existed. No other NLP schema mismatch was found.

## Separate web-process failure

After migration, retrying proposal 6 through the student UI still produced `python_failure` (exit 1). Temporary private stderr inspection established two Windows environment failures:

1. Missing `SystemRoot` caused Winsock initialization to fail with `[WinError 10106] The requested service provider could not be loaded or initialized`. A minimal Python socket constructor reproduced this when SystemRoot was removed and succeeded when restored.
2. Missing profile paths caused NLTK's import-time directory initialization to fail with `Could not find a default download directory`. This did not indicate a model download or an installation problem.

Symfony filters inherited environment variables against `$_SERVER`; the web process had the required values in `getenv()` but not in `$_SERVER`. `LocalNlpService` now explicitly passes the Windows root and a small allowlist of profile/temp paths. Offline flags and removal of API tokens remain unchanged.

Real requests also reached PHP's 60-second execution limit during slow model startup. The service now reserves a request budget covering its configured Python timeout plus 20 seconds. The subprocess remains bounded by the configured timeout (120 seconds here); if the PHP timer cannot be reset, the process uses the remaining request budget with 15 seconds reserved for shutdown/error handling. No global PHP setting changed. The machine was observed with approximately 129 MB free RAM out of 4 GB during testing.

No Python packages or models were reinstalled. KeyBERT, MiniLM, concept selection, RTA text construction, TF-IDF, cosine similarity, expertise matching, and recommendation formulas were not changed by this repair.

## Preservation and validation

Immediately after migration, all original columns in all eight audited tables matched their pre-migration hashes. Subsequent intentional reanalysis updates the relevant analysis/recommendation values. Original row IDs and uploaded-file SHA-256 hashes are checked separately after UI validation. No reset, reseed, fresh migration, or bulk deletion was used.

- `php artisan migrate:status`: every migration applied; status migration is `[17] Ran`.
- `php artisan test --compact`: **360 passed, 1 skipped, 2,915 assertions** (60.07 seconds). The skipped test is the opt-in real-model integration test; the actual live UI runs below exercised the real model separately.
- Pint: both changed PHP files passed.
- `nlp/.venv/Scripts/python.exe nlp/verify_offline.py --quality`: all three domain checks, production/debug contracts, STDIN handling, and invalid-input checks passed. Empty Hugging Face cache; **zero network attempts**. Internal relevance scores and concept selection unchanged. Model/import initialization took 40.332 seconds in this final run.

Actual student UI validation used headless Edge against the running local Laravel application and live MySQL, with the real Python entry point (no NLP fakes):

| Case | Proposal | Outcome |
| --- | --- | --- |
| Retry older analysis error | 6 | Completed; 10 concept labels; RTA results rendered; 58.476 seconds for analysis |
| Retry previously stuck Uploaded proposal | 7 | Now `extracted` / analysis `completed`; 10 concepts; RTA rendered; 67.803 seconds |
| New DOCX upload | 8 | 6,974 extracted characters saved; 10 concepts; representation saved; RTA rendered |
| DOCX refresh through confirmation dialog | 8 | Completed in 65.329 seconds; concepts and RTA regenerated; uploaded file hash unchanged |
| New PDF upload | 9 | 1,452 extracted characters saved; 10 concepts; representation saved; RTA rendered; 86.560 seconds |

All exercised analyses have version `local-concepts-v1`. Concept badges contain strings without NLP scores. Each generated two adviser results; the current live faculty data produced `0.00%` for both advisers. No artificial positive match or scoring adjustment was introduced. The isolated real-model integration documented in `local-nlp-integration.md` separately exercised a positive matching faculty fixture.

The new validation proposals 8 and 9 remain in the database. Original proposals 1, 2, 6, and 7 and all original recommendation IDs remain present. Original users (5), student profiles (2), faculty profiles (2), adviser requests (3), and adviser assignments (1) match their pre-migration hashes exactly. All four original upload hashes match; proposal 8's refreshed file also matches its uploaded DOCX source. Reanalysis intentionally updated proposal 6's analysis/recommendations; retry updated proposal 7's status and created its missing analysis/recommendations. Untouched legacy proposals 1 and 2 remain pending analysis rather than falsely marked current.

Private validation artifacts are under `storage/app/testing/schema-final-audit.json` and `schema-ui-{old,refresh,pdf,stuck}.json`; the pre-migration baseline is `storage/app/schema-repair-baseline.json`. Temporary stderr/environment diagnostics were removed and the test login session revoked.
