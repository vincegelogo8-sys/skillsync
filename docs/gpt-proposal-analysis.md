# GPT proposal analysis and UI implementation report

Update: [API credit/quota failure handling](proposal-api-failures.md) now adds persisted Pending/Failed/Completed analysis status, safe diagnostics and independently saved extraction on refresh. That report supersedes the original failure/refresh behavior below. An OpenAI key was subsequently configured locally; live provider availability has not been tested.

Implemented October 4, 2026 in the existing Laravel 12 / PHP application. Existing uncommitted RTA cleanup changes were preserved. No project rebuild, authentication redesign, Gmail change, restored assessment module, or new dependency was introduced.

## Setup on this workspace

The migration has already been applied to the local database, the frontend assets built, and Laravel caches cleared. **A real OpenAI API key is not configured**, so a live provider call has not been tested.

Add this line to **`C:\xampp\htdocs\skillsync\.env`**, replacing the placeholder locally:

```dotenv
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_PROPOSAL_MODEL=gpt-4.1-mini-2025-04-14
OPENAI_PROPOSAL_TIMEOUT=60
OPENAI_PROPOSAL_MAX_CHARACTERS=120000
```

Only the key is required; the other settings have those defaults. Do not put a real key in `.env.example`, a Blade template, JavaScript, source control, or this report. The example file contains a blank key.

After adding the key, run:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan config:clear
```

Then open an existing proposal and choose **Analyze Proposal** or **Reanalyze Proposal**. Legacy analyses require this explicit step before new recommendations or adviser requests; page views never call OpenAI. Existing assignments and requests remain intact.

For another checkout/deployment with existing project dependencies installed:

```powershell
php artisan migrate --no-interaction
php artisan optimize:clear
npm.cmd run build
php artisan test --compact
```

No new Composer or npm package needs installation. Existing Laravel HTTP, PDFParser and PHPWord packages are reused. Ensure outbound HTTPS access to `api.openai.com`, a valid API project/key with model access, and sufficient provider quota. The API timeout is capped by the remaining PHP execution time with five seconds reserved for saving/error handling. Hosting proxy timeouts may need configuration for longer documents.

## Integration and responsibilities

`OpenAIProposalAnalysisService` uses Laravel's HTTP client to POST **`https://api.openai.com/v1/responses`**. It sends only the normalized proposal text, its research title, the extraction instructions, and the JSON schema. It does not send files, faculty lists, workload, account records, passwords, sessions, IDs or recommendation scores.

The request sets `store: false`, `max_output_tokens: 2000`, and `text.format` to `json_schema`, with `strict: true` and `additionalProperties: false`. No SDK, embeddings API, tools, faculty ranking prompt, automatic paid retry, or raw-response storage was added. HTTP redirects are disabled. The default model is pinned to **`gpt-4.1-mini-2025-04-14`**; it is configurable rather than described as the latest model.

The implementation follows the official [Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs) and the documented [GPT-4.1 mini model/snapshot](https://developers.openai.com/api/docs/models/gpt-4.1-mini).

The prompt is maintained in `config/proposal_analysis.php`. It treats proposal content as untrusted data, covers the whole relevant proposal, distinguishes the student's proposed work from cited studies, prefers supported multi-word concepts, and explicitly prohibits recommending/ranking faculty or calculating RTA/percentages. Retired offline keyword/technology scoring dictionaries were removed from this configuration.

## Flow and storage

1. Existing protected upload validation and `DocumentExtractionRunner` / `DocumentExtractionService` extract a PDF or DOCX. Existing file protections and parser limits remain in place.
2. `ProposalTextNormalizer` repairs whitespace, Unicode spaces/dashes, soft hyphens, discretionary line wrapping and common formatting artifacts.
3. Its relevant-content pass removes recognizable administrative sections, page numbers, dotted table-of-contents entries, repeated institutional headers and bibliography/reference sections. Research sections, including methodology and related literature, remain available for contextual analysis. An Objectives heading is no longer required.
4. OpenAI extracts a primary area, research concepts and technical concepts.
5. Laravel validates the complete response, canonicalizes concepts and builds a saved research representation.
6. A short database transaction saves the new analysis and recalculates existing recommendations. The API call finishes before database locks are acquired.
7. `SimilarityService` compares that saved representation with actual registered faculty expertise using TF-IDF and cosine similarity. The student chooses which adviser to request.

Existing `proposal_analyses.keywords` stores research concepts; `technologies` stores technical concepts; `analyzed_at` records the latest successful explicit analysis. The added migration **`2026_10_04_000000_add_gpt_proposal_analysis.php`** adds only:

- `primary_research_area`: nullable string, maximum 120 characters.
- `research_representation`: nullable text.
- `analysis_version`: nullable string, set to `gpt-concepts-v1` after success.

It also makes the existing `recommendations.multi_expertise_score` nullable. New recommendations set it to null; no proficiency contribution is computed. Historical data is retained, no tables are added, and no existing columns are dropped on upgrade. Rollback drops the three added analysis fields and intentionally leaves the relaxed recommendation constraint nullable.

Legacy `abstract` and `project_type` values are untouched. `identified_expertise_areas` is cleared on successful new analysis because its old rules do not represent GPT evidence. The old section-extractor and multi-expertise utilities remain available for legacy code, but the active proposal matching pipeline no longer uses them.

## Exact structured output

The response object has exactly these three required fields:

```json
{
  "primary_research_area": "Information Systems",
  "research_concepts": [
    "crowdsourcing",
    "infrastructure maintenance",
    "real-time issue reporting",
    "maintenance management",
    "incident monitoring"
  ],
  "technical_concepts": [
    "web-based information system",
    "real-time monitoring"
  ]
}
```

There are 1–12 research concepts and 0–12 technical concepts. Each label is 1–120 characters. The prompt targets 5–12 research concepts when supported, rather than inventing enough to meet a minimum of five.

Laravel separately checks JSON decoding, exact keys, list/scalar types, list counts, label lengths before and after normalization, empty labels, allowed plain-label characters, and a nonempty meaningful research list. It rejects extra fields, HTML/code delimiters, malformed JSON, generic-only research lists, missing/empty outputs, refusals and incomplete responses. Labels are always Blade-escaped. Structural validation does not prove that a concept is semantically supported; that remains a model-quality limitation requiring review of real proposals.

Normalization lowercases labels, collapses spaces, canonicalizes ordinary word hyphens and deduplicates aliases/case variants. It expands AI, ML, NLP, IoT, GIS and DBMS; maps recommender system/recommendation engine to recommendation system; and normalizes NodeJS/dotnet. It removes generic standalone words such as system, study, student and data while retaining phrases containing them. Technical concepts already present in research concepts are not stored twice. C++, C#, .NET and Node.js retain meaningful punctuation. Concepts remain whole strings in storage and the UI.

## Matching and RTA

The saved representation includes each distinct normalized **primary area + research concept + technical concept once**, on separate lines. This includes more evidence than the displayed research concepts alone. Raw proposal prose/RRL is not repeated into the vector, to avoid cited studies and long prose overwhelming the student's extracted topic. No artificial phrase repetition or boost is used.

Both this representation and registered faculty expertise pass through the same alias normalization and existing word-token TF-IDF preprocessing. Multi-word labels remain intact as concepts in storage/display; the mathematical vector uses word terms with the existing stop-word rules, preserving special technical tokens. No expertise is invented or inferred for a faculty member.

The corpus is the proposal plus all eligible faculty expertise documents:

```text
TF(term, document) = occurrences / total retained terms
IDF(term) = log((document count + 1) / (document frequency + 1)) + 1
RTA = 100 × cosine(proposal TF-IDF vector, faculty TF-IDF vector)
```

Empty vectors yield zero; results are clamped to 0–100. Floating-point intermediate values are not rounded. Existing database percentages retain eight decimal places; display uses two. Each faculty's percentage is independent, not normalized to a top adviser or distributed across faculty. The shared IDF corpus means changes to the candidate/expertise corpus can change percentages on explicit regeneration.

**GPT does not calculate RTA, percentages, faculty rankings or adviser selection. TF-IDF plus cosine similarity calculate RTA, and RTA is the only recommendation criterion.** The old 70/30 cosine/proficiency blend is removed. Proficiency records and advisory capacity do not enter the score. Capacity still controls whether a request can be accepted/sent through the existing threshold and authorization logic. Faculty are displayed by RTA without ranking numbers.

## Reanalysis and failure handling

**Reanalyze Proposal** uses saved extracted text. **Refresh / Reanalyze** first reads the existing uploaded document again. Both deliberately call GPT again, regardless of `analyzed_at`, and update the successful timestamp. Recommendation generation alone uses saved concepts and never calls GPT.

Validated analysis, replacement text and existing recommendation updates commit together. Existing recommendation IDs are retained. If provider validation or saving fails, successful previous analysis/text/recommendations remain unchanged. First uploads preserve the uploaded file and extracted text even if GPT fails. No re-upload is required to retry. Concurrent changes to the analysis/title are detected before save, so a slow API response cannot overwrite newer work.

Timeouts, connection errors, 401/429/5xx responses, missing configuration, malformed output and refusals produce: **“Proposal analysis is temporarily unavailable. Please try again.”** Raw provider responses, API keys and complete proposal text are not logged by the new analysis integration or displayed as diagnostics.

## UI changes

- Shared: removed redundant page descriptions and repeated system eyebrow; retained meaningful helper text, errors and success states. Improved wrapping, button touch size, metadata readability, chip styling and profile-link sizing. Existing navigation, keyboard behavior, components and form security remain.
- Student: proposal details show primary area, research/technical chips and analysis date; obsolete title/objectives-only explanation is removed. Actions appear after the content. Upload explains that proposal text is sent to OpenAI. Recommendation cards show faculty expertise, RTA, Students Handled, availability and request actions. Dashboard, proposals, profile, requests, assignments and account settings have shorter copy/empty states.
- Faculty: shorter dashboard/profile/expertise/assignment copy; retained Admin-only expertise editing and existing field validation. Accept/Decline actions have confirmations, with a short explanation of acceptance consequences.
- Admin: streamlined account management, expertise, advisory-limit, proposal, request and assignment views. Kept advisory-limit consequences and account handoff guidance. No Assessment/Competency links or modules were restored.

## Validation and remaining limitations

- Baseline before edits: 339 tests passed, 2,758 assertions.
- Final results and the exact changed-file inventory appear below.
- Build, PHP/Blade compilation, route registration, formatting and diff whitespace checks were performed. Local database migration succeeded.
- Browser checks use test-only data, isolated Edge profiles and blocked external HTTP(S), with current-page manifests so removed-module snapshots cannot contaminate results. The browser requires execution outside the workspace sandbox to render.
- No live OpenAI request was made because there is no configured API key. Automated provider tests fake HTTP responses and prevent stray network calls. Real-world concept accuracy should be checked after configuration with representative proposals.
- Scanned/image-only PDFs still require OCR outside the existing extractor. Filtering is conservative and cannot perfectly recognize every institutional template, name, header, inline bibliography or citation. PDF/DOCX equivalence is tested for meaningful extraction/formatting, not guaranteed for arbitrary files.
- Proposals above the configurable 120,000-character cleaned-input limit fail intact, rather than silently analyzing only the beginning. There is no chunking or background queue for GPT analysis. Large documents may need a higher limit/hosting timeout or later queued processing.
- Repeated GPT calls can produce different valid concepts. Fixed saved concepts and unchanged faculty/corpus produce deterministic RTA; a pinned model does not guarantee identical fresh model output.
- Semantic synonyms beyond the explicit conservative alias map may still match weakly. No fabricated faculty expertise or score inflation was added.

## Final verification results

- Full suite: **339 passed, 2,775 assertions** (including API validation/failure, ownership, reanalysis, transaction rollback, real PDF/DOCX extraction, TF-IDF/cosine math, request/assignment, Gmail notification regression and all-role page tests).
- PHP syntax: **32 changed/new PHP files passed**; Laravel Pint passed after formatting.
- Vite production build: **passed**; Blade view compilation and route registration: **passed**.
- Browser layout: **148/148 passed** ? 37 current pages at 1440, 768, 390 and 320 pixels; includes overflow, one page heading, active navigation, mobile sidebar, cancellation and duplicate-submit behavior.
- Keyboard/navigation checks: **9/9 passed**, including account-menu keyboard use, Escape dismissal and mobile focus restoration.
- JavaScript syntax and git diff whitespace checks: **passed**.
- Local migration: applied as batch 16; no database reset or proposal deletion.

Screenshots: [mobile proposal](../storage/app/testing/ui/student-proposal-details-390.png), [desktop recommendations](../storage/app/testing/ui/student-recommendations-1440.png), [mobile faculty requests](../storage/app/testing/ui/faculty-requests-390.png). Browser reports: [layout](../storage/app/testing/ui/browser-report.json) and [keyboard](../storage/app/testing/e2e/browser-report.json). Test output: [full suite](../storage/logs/gpt-full-tests.txt).

To repeat browser checks in PowerShell after building assets:

```powershell
$env:SKILLSYNC_E2E_CAPTURE='1'
php artisan test --compact --filter='UiPresentationTest|EndToEndWorkflowTest'
$env:SKILLSYNC_UI_WIDTHS='1440,768,390,320'
node tests/Support/verify-ui.mjs
node tests/Support/verify-browser.mjs
```

## Files created (7)

- `app/Services/OpenAIProposalAnalysisService.php`
- `app/Services/ProposalTextNormalizer.php`
- `app/Services/ResearchConceptNormalizer.php`
- `tests/Feature/OpenAIProposalAnalysisTest.php`
- `tests/Support/ProposalAnalysisResponse.php`
- `database/migrations/2026_10_04_000000_add_gpt_proposal_analysis.php`
- `docs/gpt-proposal-analysis.md`

## Files modified (52)

This list covers this task, excluding changes that were already present when work began and content-equivalent line-ending normalization.

- `app/Http/Controllers/RecommendationController.php`
- `app/Http/Controllers/ResearchProposalController.php`
- `app/Models/ProposalAnalysis.php`
- `app/Services/AdviserRequestService.php`
- `app/Services/DocumentExtractionService.php`
- `app/Services/ProposalAnalysisService.php`
- `app/Services/ProposalExtractionService.php`
- `app/Services/ProposalSectionExtractor.php`
- `app/Services/RecommendationService.php`
- `app/Services/SimilarityService.php`
- `app/Services/TopicAlignmentService.php`
- `config/proposal_analysis.php`
- `resources/css/app.css`
- `resources/views/admin/accounts/create.blade.php`
- `resources/views/admin/accounts/index.blade.php`
- `resources/views/admin/advisory-limits/edit.blade.php`
- `resources/views/admin/advisory-limits/index.blade.php`
- `resources/views/admin/expertise/index.blade.php`
- `resources/views/assignments/index.blade.php`
- `resources/views/components/page-header.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/faculty/expertise/edit.blade.php`
- `resources/views/faculty/expertise/index.blade.php`
- `resources/views/faculty/profile/edit.blade.php`
- `resources/views/profile/edit.blade.php`
- `resources/views/profile/partials/delete-user-form.blade.php`
- `resources/views/profile/partials/update-profile-information-form.blade.php`
- `resources/views/requests/index.blade.php`
- `resources/views/student/profile/edit.blade.php`
- `resources/views/student/proposal/analysis.blade.php`
- `resources/views/student/proposal/create.blade.php`
- `resources/views/student/proposal/index.blade.php`
- `resources/views/student/proposal/show.blade.php`
- `resources/views/student/recommendations/card.blade.php`
- `resources/views/student/recommendations/index.blade.php`
- `resources/views/welcome.blade.php`
- `tests/Feature/AdviserAssignmentTest.php`
- `tests/Feature/AdviserRequestTest.php`
- `tests/Feature/AdvisoryThresholdTest.php`
- `tests/Feature/FacultyExpertiseTest.php`
- `tests/Feature/MultiExpertiseTest.php`
- `tests/Feature/ProposalAnalysisTest.php`
- `tests/Feature/ProposalSectionsTest.php`
- `tests/Feature/RecommendationPageTest.php`
- `tests/Feature/ResearchProposalTest.php`
- `tests/Feature/SimilarityTest.php`
- `tests/Feature/TopicAlignmentTest.php`
- `tests/Feature/UiPresentationTest.php`
- `tests/Support/DocumentFixtures.php`
- `tests/Support/verify-ui.mjs`
- `tests/TestCase.php`
- `.env.example`

## Files removed

None by this task. Previously removed assessment/competency/preference files remain removed.

The generated Vite manifest/assets under `public/build` were rebuilt. Test-only screenshots, current-page manifest, HTML captures and reports were generated under `storage/app/testing`; diagnostic test outputs and change inventories are under `storage`. These are runtime/build artifacts, not additional application modules.
