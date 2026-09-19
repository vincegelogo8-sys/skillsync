# Step 23: Test Coverage Audit and Additional Regression Tests

## Inspection and scope

The project already had 384 passing tests from Steps 2–22. This step audits them
against section 53 of the original requirements and fills meaningful gaps. It
changes tests only, plus this guide. Application behavior, database schema,
production data, frontend assets, and dependencies are unchanged.

## Added coverage

Seven regression tests were added:

1. Admin approval rejects missing/incorrect CSRF tokens; a valid token permits
   approval. No assignment is created by a rejected request.
2. Approval rechecks the Admin's persisted role even when the authentication
   object still carries the previous Admin role.
3. A failure after the competing-request cancellation SQL rolls back the new
   assignment, selected request approval, and competing-request cancellations.
   A successful retry preserves unrelated proposals' pending requests.
4. The database unique constraint independently rejects a second assignment for
   one proposal.
5. Student request submission and cancellation enforce CSRF and preserve state
   on token failure.
6. Multiple cancelled/declined history rows survive repeated resubmission while
   only the latest pending request keeps the active duplicate guard.
7. A completed assessment scoring zero remains a recorded result, with
   `missing_assessment = false`; a newer unfinished attempt does not replace it.

Laravel normally bypasses CSRF during feature tests. The test-only
`EnforceCsrfTokens` middleware disables that bypass for the two CSRF tests, keeping
the framework's actual token validation. It is bound only in those tests and is
not installed in application middleware.

## Requirements-to-test mapping

| Requirement | Existing regression coverage |
| --- | --- |
| Login/logout, password flows, disabled public registration | `Auth/*Test.php`, `AdminAccountTest.php` |
| Exactly three roles and dashboard access | `RoleAuthorizationTest.php`, `DashboardTest.php` |
| Student/Faculty profiles and protected fields | `StudentProfileTest.php`, `FacultyProfileTest.php`, `ProfileTest.php` |
| Faculty expertise CRUD and proficiency validation | `FacultyExpertiseTest.php` |
| Controlled project-type/technology preferences | `FacultyPreferenceTest.php` |
| Admin competency rubric and normalization | `FacultyCompetencyTest.php` |
| Ten-question assessment, grading, completed-result integrity | `SkillsAssessmentTest.php` |
| Private upload, size/type validation, authorized download | `ResearchProposalTest.php` |
| Real PDF/DOCX readers, empty/scanned/corrupt documents, retry/cache | `ProposalExtractionTest.php` |
| Keywords, abstract, project type, mentioned technologies, up to three areas | `ProposalAnalysisTest.php` |
| Multi-expertise full/partial/missing matches and proficiency | `MultiExpertiseTest.php` |
| TF-IDF, shared-corpus cosine, empty inputs, technical tokens | `SimilarityTest.php` |
| Topic alignment's 70/30 weights | `TopicAlignmentTest.php` |
| Preference matching and zero-technology handling | `PreferenceCompatibilityTest.php` |
| Final 40/30/20/10 weighting, latest assessment, missing data, ranking/storage | `RecommendationTest.php` |
| Ranking-page access, saved reads, pagination, escaped/private data | `RecommendationPageTest.php` |
| Active assignment counts, limit editing, full faculty visibility | `AdvisoryThresholdTest.php` |
| Request ownership, duplicates, state transitions, full-capacity checks | `AdviserRequestTest.php` |
| Admin assignment, capacity, replay, competing requests, rollback | `AdviserAssignmentTest.php` |

All paths in the table are under `tests/Feature`. This is a functional requirements
coverage map, not an instrumented line-coverage percentage.

## Changed source files

- [CSRF test middleware](../tests/Support/EnforceCsrfTokens.php)
- [Assignment tests](../tests/Feature/AdviserAssignmentTest.php)
- [Request tests](../tests/Feature/AdviserRequestTest.php)
- [Recommendation tests](../tests/Feature/RecommendationTest.php)

Complete paths resolve under `C:\xampp\htdocs\skillsync`. No application service
needed modification to pass the new cases.

## Exact commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter='AdviserAssignmentTest|AdviserRequestTest|RecommendationTest'
vendor\bin\pint --test tests/Support/EnforceCsrfTokens.php tests/Feature/AdviserAssignmentTest.php tests/Feature/AdviserRequestTest.php tests/Feature/RecommendationTest.php
php artisan test --compact --order-by=random --random-order-seed=23
```

The focused run includes all existing and added tests in the three affected
suites. The full suite uses a reproducible randomized order to check for shared
state and middleware/event-listener leakage. To repeat the ordinary default-order
suite later, run `php artisan test --compact`.

`phpunit.xml` uses in-memory SQLite, an array session/cache/mail driver, and an
empty DB_URL for tests. Uploaded documents use isolated fixtures and fake storage.
No database reset, production seed, migration, package install, or asset rebuild
is needed for this step.

## Remaining verification boundary

These tests cover application HTTP behavior and actual local PDF/DOCX extraction,
but SQLite does not exercise MySQL row-lock scheduling under simultaneous workers.
They also do not replace real-browser visual and keyboard checks. Step 24 remains
the final end-to-end verification stage; this step does not claim that work is
already complete.

Stop after Step 23. One planned step remains: Step 24.

## Verification results

- Focused suites: **41 passed, 241 assertions**.
- Full randomized suite: **391 passed, 2,961 assertions**, seed **23**.
- Pint passed for all four changed test files.
- No application fixes, production mutations, or schema changes were required.
