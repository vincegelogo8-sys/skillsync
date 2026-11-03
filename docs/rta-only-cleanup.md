# RTA-only recommendation cleanup

> **Historical report:** The RTA-only workflow described below has been superseded
> by category-based expertise matching. Current analysis extracts supported
> categories from the full relevant proposal text; recommendation generation
> filters to faculty with direct or evidence-backed related expertise, stores
> match explanations, sorts by distinct matched-category count descending and
> then surname and first name, and displays no matching percentage, count, or
> rank. Optional faculty technical skills are supporting evidence only. Capacity
> remains separate from matching. The current implementation is in
> [ExpertiseMatchingService](../app/Services/ExpertiseMatchingService.php),
> [RecommendationService](../app/Services/RecommendationService.php), and
> [RecommendationController](../app/Http/Controllers/RecommendationController.php).

## Admin-managed faculty expertise

Only the system admin can add, edit or remove faculty expertise and proficiency scores through **Admin → Faculty Expertise → Manage expertise**. Faculty can view their own recorded expertise but have no editing controls. The shared form request rejects faculty create/edit/update/delete requests with HTTP 403, including direct requests to the former faculty management endpoints. Existing expertise records and the RTA formula are preserved.

## Architecture and formula

Before cleanup, `TopicAlignmentService` calculated **RTA = 0.70 × cosine similarity percentage + 0.30 × multi-expertise strength**. `RecommendationService` then calculated **0.40 × RTA + 0.30 × competency + 0.20 × preference compatibility + 0.10 × skills assessment** and persisted sequential ranks.

After cleanup, the RTA formula is unchanged. **Recommendation percentage = RTA**. `final_score` mirrors RTA on generation/refresh for storage compatibility. Each faculty percentage is independent; percentages are never normalized to sum to 100. No replacement criteria or external AI APIs were added.

Exact evidence:

- `ProposalAnalysisService` extracts computing concepts from the relevant extracted proposal text and builds the saved representation from the title, concepts, and supporting context. Recommendation generation uses that completed saved analysis without rerunning extraction.
- `SimilarityService` applies the existing local token normalization and stop words. TF is token frequency divided by document token count. IDF is `ln((1 + N) / (1 + document frequency)) + 1`, using the proposal and faculty expertise documents as the corpus. Cosine similarity is multiplied by 100.
- The saved proposal representation is built from the extracted proposal's relevant text, title, computing concepts, and supporting context. Each faculty document contains only the canonical `faculty_expertise.expertise_area` values.
- `TopicAlignmentService` uses the cosine percentage directly as Research Topic Alignment. Faculty proficiency and adviser capacity do not change RTA; capacity remains a separate availability check.
- Technology recognition is retained because expertise detection requires it. Its canonical list moved from `config/preferences.php` to `config/proposal_analysis.php`. The keyword phrase vocabulary remains to preserve keyword extraction. Project-type classification and display are removed.

## Dependency cleanup

Competency evaluation, question management, assessment taking/history/results, and faculty preferences each had dedicated controllers, requests, services, models and views. Their only scoring consumers were the removed recommendation criteria. Faculty assessment also supplied one dashboard metric. The navigation catalog and faculty profile linked to these modules. No exclusive policies, middleware or notifications required removal; role middleware, authentication and request notifications are shared and retained.

Removed all of these chains, including faculty model relationships, dashboard metric, navigation, profile links, assessment JavaScript/CSS and recommendation breakdown JavaScript/CSS. Removed the four feature-specific test files; updated shared workflow tests and added removal regression coverage.

The percentage-only recommendation cards show the faculty name/department, Research Topic Match, current student count and availability/request actions. They contain no ranking numbers, medals, weighted breakdown or removed criteria. Results sort by saved RTA descending, then faculty ID for stable ties, including across pagination. Historical rows display their saved RTA directly even when their old final score/rank differs; GET requests do not rewrite data.

Advisory limits remain independent of scoring. FULL faculty remain visible with unchanged percentages and disabled requests. Already-requested faculty likewise remain visible. Students see only the current load (for example `3`); administrators retain load/limit controls. Faculty expertise, account/profile management, proposal extraction/refresh, requests, approval/decline and assignments remain active.

## Removed routes

| Methods | URI | Former route names |
| --- | --- | --- |
| GET | `/admin/competencies` | `admin.competencies.index` |
| GET, PATCH | `/admin/faculty/{facultyProfile}/competency` | `admin.competencies.edit`, `.update` |
| GET, POST | `/admin/assessment-questions` | `admin.assessment-questions.index`, `.store` |
| GET | `/admin/assessment-questions/create` | `admin.assessment-questions.create` |
| GET | `/admin/assessment-questions/{assessmentQuestion}/edit` | `admin.assessment-questions.edit` |
| PUT/PATCH, DELETE | `/admin/assessment-questions/{assessmentQuestion}` | `admin.assessment-questions.update`, `.destroy` |
| GET | `/admin/assessment-results` | `admin.assessment-results.index` |
| GET, POST | `/faculty/assessment` | `faculty.assessment.index`, `.start` |
| GET, POST | `/faculty/assessment/{attempt}` | `faculty.assessment.show`, `.submit` |
| GET, PATCH | `/faculty/preferences` | `faculty.preferences.index`, `.update` |

## Database preservation

No historical migration was edited, no tables/columns were dropped, and no destructive database command was run. Forward migrations make obsolete recommendation fields nullable and make the legacy `faculty_expertise.proficiency_score` column nullable. Existing expertise rows and score values are preserved. New expertise entries need only an area and leave the legacy score null. Existing obsolete recommendation values are retained on refresh; new calculation details contain only topic alignment and analysis provenance.

Intentionally retained for a separate future archival/schema cleanup:

- `faculty_competencies`, `faculty_preferences`.
- `skills_assessment_questions`, `skills_assessment_attempts`, `skills_assessment_answers`.
- `recommendations.advising_competency_score`, `.preference_compatibility_score`, `.skills_assessment_score`, `.rank`, and the legacy proposal/rank index.
- Historical JSON details and historical weighted `final_score` values until explicit recommendation refresh. All active display/order code uses RTA; newly generated `final_score` equals RTA.
- `proposal_analyses.project_type` and previously retained `abstract` values remain unused and preserved. Technology/expertise evidence remains active.

The migration's rollback deliberately leaves the nullable constraints in place: restoring NOT NULL would require inventing values for new RTA-only records. Application data is not rewritten by this migration.

Earlier step-by-step documents in `docs/` describe historical implementation stages. This report supersedes their multi-criterion, preference, assessment and ranking instructions.

## Validation

The initial suite exposed an outdated DOCX test expecting external images to be rejected. Existing extraction deliberately ignores external image relationships while disallowing XML entities. The test now verifies unchanged extracted text with external images and continued entity rejection; extraction implementation was not changed.

Final verification:

- `php artisan test --compact`: **339 passed, 2,747 assertions** using isolated SQLite memory databases. Includes real PDF/DOCX extraction, title/objectives isolation, independent percentages, historical score/rank display, FULL/requested advisers, student load display, active page rendering, removed endpoint 404 responses, authentication, expertise, requests, approval/decline, assignments and notification tests. Mail/notifications use test fakes; no live Gmail delivery was attempted.
- `npm.cmd run build`: passed. PowerShell blocks the `npm.ps1` shim; the standard Windows `.cmd` entry point succeeded.
- `php artisan route:list --except-vendor --json`: passed, 71 active routes, zero competency/assessment/preference routes.
- `php artisan view:cache`: passed.
- Headless Edge visual checks were attempted twice but timed out at DevTools `Network.enable` before page inspection. Browser layout/interaction verification is therefore unconfirmed. The actual Laravel-rendered recommendation snapshot was checked separately: topic percentage and current student count present, removed criteria/rank text absent. All active page templates passed HTTP rendering tests.
- Pint checks on the changed recommendation/core schema files: passed after correcting a test import. `git diff --check`: passed.
- Applied only `2026_09_20_000000_allow_unused_recommendation_fields.php` to the existing database. Before/after row counts and SHA-256 hashes matched for all 14 application tables, including legacy data. No stored user/proposal/request/assignment/recommendation data changed during migration.

The original uncommitted student load-display changes in the recommendation card and `AdvisoryThresholdTest` were preserved.

## Files modified

- `app/Http/Controllers/RecommendationController.php`
- `app/Models/FacultyProfile.php`
- `app/Models/Recommendation.php`
- `app/Services/DashboardSummaryService.php`
- `app/Services/ProposalAnalysisService.php`
- `app/Services/ProposalExtractionService.php`
- `app/Services/RecommendationService.php`
- `app/Services/TopicAlignmentService.php`
- `config/proposal_analysis.php`
- `config/workspace_navigation.php`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/views/faculty/profile/edit.blade.php`
- `resources/views/student/proposal/analysis.blade.php`
- `resources/views/student/recommendations/card.blade.php`
- `resources/views/student/recommendations/index.blade.php`
- `resources/views/welcome.blade.php`
- `routes/web.php`
- `tests/Feature/AdvisoryThresholdTest.php`
- `tests/Feature/DashboardTest.php`
- `tests/Feature/EndToEndWorkflowTest.php`
- `tests/Feature/ProposalAnalysisTest.php`
- `tests/Feature/ProposalExtractionTest.php`
- `tests/Feature/RecommendationPageTest.php`
- `tests/Feature/RecommendationTest.php`
- `tests/Feature/SimilarityTest.php`
- `tests/Feature/TopicAlignmentTest.php`
- `tests/Feature/UiPresentationTest.php`
- `tests/Support/verify-browser.mjs`
- `tests/Support/verify-ui.mjs`

## Files added

- `database/migrations/2026_09_20_000000_allow_unused_recommendation_fields.php`
- `tests/Feature/RemovedRecommendationModulesTest.php`
- `docs/rta-only-cleanup.md`

## Files removed

- `app/Http/Controllers/Admin/AssessmentQuestionController.php`
- `app/Http/Controllers/FacultyCompetencyController.php`
- `app/Http/Controllers/FacultyPreferenceController.php`
- `app/Http/Controllers/SkillsAssessmentController.php`
- `app/Http/Requests/AssessmentQuestionRequest.php`
- `app/Http/Requests/FacultyCompetencyRequest.php`
- `app/Http/Requests/FacultyPreferenceRequest.php`
- `app/Models/FacultyCompetency.php`
- `app/Models/FacultyPreference.php`
- `app/Models/SkillsAssessmentAnswer.php`
- `app/Models/SkillsAssessmentAttempt.php`
- `app/Models/SkillsAssessmentQuestion.php`
- `app/Services/CompetencyService.php`
- `app/Services/FacultyPreferenceService.php`
- `app/Services/PreferenceCompatibilityService.php`
- `app/Services/SkillsAssessmentService.php`
- `resources/views/admin/competencies/index.blade.php`
- `resources/views/admin/competencies/edit.blade.php`
- `resources/views/admin/assessment-questions/index.blade.php`
- `resources/views/admin/assessment-questions/form.blade.php`
- `resources/views/admin/assessment-results/index.blade.php`
- `resources/views/faculty/assessment/index.blade.php`
- `resources/views/faculty/assessment/show.blade.php`
- `resources/views/faculty/preferences/index.blade.php`
- `tests/Feature/FacultyCompetencyTest.php`
- `tests/Feature/FacultyPreferenceTest.php`
- `tests/Feature/PreferenceCompatibilityTest.php`
- `tests/Feature/SkillsAssessmentTest.php`
- `config/preferences.php`
