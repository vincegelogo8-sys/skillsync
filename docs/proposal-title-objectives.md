# Proposal analysis: title and objectives only

## Inspection

PDF uses local Smalot PDFParser inside the bounded DocumentExtractionRunner worker.
DOCX uses local PHPWord, falling back to word/document.xml. Both use the same
normalization and section extraction. Full text remains in
`proposal_analyses.extracted_text` for the existing document preview. Abstract
extraction remains a display-only operation on full text.

Previously keywords, technology detection, project type, and expertise used the
stored title plus full extracted text. SimilarityService and TopicAlignmentService
each independently appended the full document for TF-IDF. RecommendationService
uses topic alignment plus the stored detected requirements and preferences.

`research_proposals.title` is the required user-entered proposal title. There is
no separate selected/final-title field and no objectives field. The saved title is
treated as authoritative. With no supplied stored title, every document title
candidate is preserved. No candidate is arbitrarily selected.

## Extraction and input

`ProposalSectionExtractor::extract(text, storedTitle)` returns `title`,
`objectives`, and `analysis_text`. The latter is title + newline + objectives.
No migration or duplicate storage columns are needed; the sections are derived
deterministically from the retained full text.

- Normalize CRLF/CR, page breaks and Unicode line separators to newlines; normalize
  horizontal whitespace, NBSP, Unicode dashes, control characters and blank lines.
- Remove soft hyphens and repair discretionary word splits; common compounds such
  as web-based and real-time retain their hyphen. Arbitrary hard-hyphen word breaks
  remain inherently ambiguous without document layout information.
- Recognize headings case-insensitively, with optional colons, inline content,
  numbering and wrapped heading labels. DOCX paragraphs, explicit breaks and table
  cells retain boundaries, including in the XML fallback.
- Collect the Proposed Title section until another recognized heading. Wrapped
  title lines are joined, and Title 1/2/3 remain separate candidates when there is
  no supplied stored title.
- Collect Objectives until the next recognized section (researchers, priority
  agenda, significance, participants/respondents/informants/evaluators,
  methodology/research design, expected outputs, scope, signatures, and other
  recognized document headings). Continuation lines join the preceding item;
  numbering such as `1.` and `1)` starts another item.
- Page breaks do not terminate collection. Standalone page numbers, concept-paper
  labels, the Republic heading and exact repetitions of preamble/header lines are
  excluded. Unknown or unusual footer/layout text can still require document review;
  plain extracted text does not encode every visual header/footer boundary.
- Missing/empty objectives raise the existing `analysis` validation error:
  “Objectives section could not be identified in the uploaded proposal.” Missing
  title raises a corresponding error unless a trusted stored title is available.
  Full-document fallback is never used.

The existing local detection algorithm receives the title and objectives as its
two inputs, retaining its original title weighting. Both TF-IDF entry points
receive exactly `analysis_text`. All mathematical formulas, final weights,
ranking, thresholding, adviser requests and notification behavior are unchanged.

Explicit analysis rebuilds derived evidence, saving only if it changed.
Recommendation generation also rebuilds evidence before computing scores, so old
whole-document technology/expertise detections cannot enter newly generated rankings.
GET requests remain read-only. Existing saved proposals should use **Refresh
Extraction** to update the displayed analysis and any existing recommendations.

Refresh re-reads the original file, resets derived fields, analyzes the new
sections, and recalculates existing rankings through RecommendationService in one
transaction. Analysis and recommendation row IDs are retained. Section/analysis
failure rolls back the refresh and reports the error; previous results and the
uploaded file remain available. No production data migration or bulk rewrite runs.

## Debug example (actual local service output)

The input also contained Python/OpenCV under Significance, TensorFlow under
Participants, machine learning under Methodology, and GIS under Expected Outputs.

```text
TITLE
CampusSkill Web-Based System

OBJECTIVES
1. To develop a web application using Laravel.
2. To provide a real-time dashboard.

CANONICAL ANALYSIS TEXT
CampusSkill Web-Based System
1. To develop a web application using Laravel.
2. To provide a real-time dashboard.

EXTRACTED KEYWORDS
web based, web application, laravel

TECHNOLOGIES: Laravel
PROJECT TYPE: Web-Based System
EXPERTISE: Web Development
```

## Files and verification

Created: `app/Services/ProposalSectionExtractor.php`,
`tests/Feature/ProposalSectionsTest.php`, and this document.

Modified runtime files: DocumentExtractionService, ProposalAnalysisService,
ProposalExtractionService, SimilarityService, TopicAlignmentService,
RecommendationService, ResearchProposalController,
`resources/views/student/proposal/analysis.blade.php`, and
`resources/views/student/recommendations/index.blade.php` (analysis errors).
Updated `docs/step-12-local-proposal-analysis.md` and
`docs/step-14-tfidf-cosine-similarity.md`.

Modified existing test files (fixtures now include Objectives):

- `tests/Support/DocumentFixtures.php`
- `tests/Feature/ProposalAnalysisTest.php`
- `tests/Feature/ProposalExtractionTest.php`
- `tests/Feature/SimilarityTest.php`
- `tests/Feature/TopicAlignmentTest.php`
- `tests/Feature/MultiExpertiseTest.php`
- `tests/Feature/PreferenceCompatibilityTest.php`
- `tests/Feature/RecommendationTest.php`
- `tests/Feature/RecommendationPageTest.php`
- `tests/Feature/AdviserAssignmentTest.php`
- `tests/Feature/AdviserRequestTest.php`
- `tests/Feature/AdviserRequestNotificationTest.php`
- `tests/Feature/AdvisoryThresholdTest.php`
- `tests/Feature/EndToEndWorkflowTest.php`

The dedicated regression suite covers all seven requested cases, real PDF/DOCX
equivalence, wrapped headings, repeated institutional headers, saved-title
precedence, missing title, actual proposal vectors and topic alignment, legacy
detection replacement, refresh rollback and preservation of recommendation IDs.
Tests use the configured in-memory SQLite database, not the application's MySQL data.

An existing unrelated test, `test_xml_entities_and_external_document_content_are_rejected`,
expects rejection of external DOCX relationships. The reader intentionally ignores
those relationships. This failure was reproduced using the original HEAD service;
this feature does not change that behavior.

Verification results:

- Full suite: 433 tests, 3,523 assertions; 432 passed, with only the pre-existing
  external-relationship rejection failure above.
- After the final recommendation-page error regression was added, targeted
  ProposalSectionsTest, ProposalAnalysisTest, RecommendationPageTest and
  AdviserRequestNotificationTest: **54 passed, 333 assertions**. This includes
  all **22** new section/refresh regression cases.
- Laravel Pint and `git diff --check` passed.
