# Proposal API failure behavior

API credit, quota, spending-limit, authentication, rate-limit, connection and malformed-response failures never become PDF/DOCX extraction failures.

The uploaded file and successfully extracted text are saved independently of GPT. A refresh also retains newly extracted text if the subsequent API request fails. Reanalyze uses that saved text; it does not upload or extract the file again.

`proposal_analyses.analysis_status` is `pending` before a request, `failed` after a failed attempt, and `completed` only when validated concepts and dependent recommendation updates commit. Initial extracted-but-not-analyzed rows default to pending. The status migration backfills successful current-version analyses as completed.

An attempt UUID prevents an older request from overwriting a newer attempt. Network calls occur outside database locks. A failed attempt preserves previous successful concepts, analysis timestamp and recommendation rows for recovery, but they are not treated as current. Pending/failed status blocks recommendation generation, similarity calculations and new adviser requests. No fallback concepts or new RTA are produced.

The proposal page displays a persistent status and this message after failure, including after flash messages expire:

> Proposal analysis is temporarily unavailable. Your proposal has been saved. Please try again later.

It provides a **Reanalyze** action. Successful retry replaces concepts, marks analysis completed, and updates existing recommendations atomically. If no recommendations exist yet, the normal Generate Recommendations action becomes available.

Provider error handling follows the documented [OpenAI API error categories](https://developers.openai.com/api/docs/guides/error-codes). Application logs contain fixed categories and numeric HTTP status only for provider failures. Codes recognized as quota/billing issues map to `quota_or_billing`; unknown codes are not copied into logs. No API keys, headers, response bodies, provider messages, exception details or proposal text are logged by this integration. Local persistence failures log only a fixed category and proposal ID.

## Changed files

- Created `database/migrations/2026_10_04_010000_add_proposal_analysis_status.php`.
- Created `tests/Feature/ProposalQuotaFailureTest.php` and this report.
- Updated `app/Models/ProposalAnalysis.php`.
- Updated `app/Http/Controllers/ResearchProposalController.php` so repeated extraction preserves completed analysis without another GPT request.
- Updated `app/Services/ProposalAnalysisService.php`, `OpenAIProposalAnalysisService.php`, and the refresh documentation in `ProposalExtractionService.php`.
- Updated `resources/views/student/proposal/analysis.blade.php` and `resources/views/components/status-badge.blade.php`.
- Updated preservation assertions in `tests/Feature/ProposalAnalysisTest.php` and `ProposalSectionsTest.php` to allow status/attempt metadata changes and independently persisted refreshed text.
- Added a fixed test-only encryption key to `phpunit.xml`, isolating tests from local secret configuration.
- Updated the earlier GPT implementation report to reference this behavior.

No dependencies were added. No live provider calls or paid requests are needed for verification. The new migration adds two columns and preserves existing files, concepts, recommendations and assignments.

## Verification

Regression tests cover real PDF and DOCX extraction with insufficient quota/spending-limit responses, persistent friendly errors, no fabricated concepts, unchanged saved RTA, blocked pending/failed matching, safe diagnostic logging and recovery without extraction/re-upload. The existing failure, ownership and matching tests remain active.

Full regression result: **343 tests passed, 2,860 assertions**. All API responses were faked; no live requests or credits were used.

For another checkout, run `php artisan migrate --no-interaction` and `php artisan view:clear`. Run `php artisan test --compact` to verify with isolated test data and fake API responses.

The new migration has not been applied to the local application database: MySQL refused the connection at `127.0.0.1:3306`. Start MySQL in XAMPP, then run `php artisan migrate --no-interaction`. Blade compilation and PHP formatting checks passed.

## Local configuration issue found during testing

The local `APP_KEY` currently contains an OpenAI API key and is invalid for Laravel encryption. This is separate from `OPENAI_API_KEY`, which is configured. The live encryption key was not rotated: restore the original Laravel `APP_KEY` from backup, or explicitly authorize generation of a new Laravel key knowing that existing sessions and previously encrypted data may become unreadable. No secret value is included in this report.
