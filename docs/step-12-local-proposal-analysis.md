# Step 12: Local Proposal Analysis

## Inspection and scope

Step 11 already saved private documents and extracted text in one analysis row per
proposal. Before this change, the database contained three users, one proposal,
and one extracted-text row. This step extends that row without replacing existing
data. No packages or online services are needed for analysis.

## Behavior

New uploads automatically run local analysis after successful extraction. The
existing **Extract Document Text** and retry actions also analyze successful text.
An already extracted proposal offers **Analyze Proposal** to its Student owner
and to Admin. Faculty and other students cannot trigger or view its analysis.

The existing local NLP process receives the title and complete relevant proposal
text, with references and administrative sections excluded. Analysis stores
directly identified supported expertise categories in `keywords`, related
categories in `identified_expertise_areas` only when configured evidence is
present, and local extracted concepts in `technologies`. The saved
`research_representation` retains the title and proposal context. The analysis
page distinguishes direct and related categories; it does not display project
type or a matching percentage. Abstract/Summary is not extracted or displayed.
Its legacy database column and existing values are preserved, including during
refresh.

`analyzed_at` distinguishes a completed analysis with no matches from text that
has not been analyzed. Proposal `status` continues to describe extraction;
the results section separately shows **Analysis complete**. Repeated POSTs reuse
saved results; GET requests never extract or analyze. A transaction and proposal
row lock serialize analysis writes. A failed analysis save preserves the original
document and extracted text and allows retry.

## Transparent rules and limitations

- Matching is limited to the nine categories in `config/expertise_matching.php`.
  Unsupported legacy areas are preserved in the database but never qualify a
  faculty member.
- Direct categories require supported terminology in the title, relevant text, or
  local NLP concepts. Related categories are emitted only when a configured
  source-category rule and its specific evidence both match.
- Faculty-specific skills are shown as supporting evidence only when the skill is
  found in relevant proposal text or extracted concepts. A category match remains
  the eligibility criterion.
- Category extraction is not a percentage or confidence score. Recommendation
  display is not ranked and does not show RTA or other percentages.
- `keywords` contains the supported direct expertise categories, not a ranked list
  of generic proposal terms. `identified_expertise_areas` contains only
  evidence-backed related categories.
- Abstract and summary headings remain section boundaries so their content cannot
  become title/objective evidence. Original extracted source text remains available.
- Explicit analysis and recommendation generation use the saved, current analysis.
  Refresh Extraction re-reads the file and recalculates matching recommendations
  atomically. Historical analyses require reanalysis under the current version.

The analysis and matching path is local; it does not call an online AI API.
Adviser capacity remains independent of matching, and existing request and
assignment workflows are preserved.

## Complete source

- [Analysis service](../app/Services/ProposalAnalysisService.php)
- [Category rules and aliases](../config/expertise_matching.php)
- [Expertise matcher](../app/Services/ExpertiseMatchingService.php)
- [Proposal text normalizer](../app/Services/ProposalTextNormalizer.php)
- [Analysis model and casts](../app/Models/ProposalAnalysis.php)
- [Additive database migration](../database/migrations/2026_09_12_060000_add_local_proposal_analysis_fields.php)
- [Controller and automatic pipeline](../app/Http/Controllers/ResearchProposalController.php)
- [Protected routes](../routes/web.php)
- [Proposal detail page](../resources/views/student/proposal/show.blade.php)
- [Analysis result view](../resources/views/student/proposal/analysis.blade.php)
- [Analysis regression tests](../tests/Feature/ProposalAnalysisTest.php)
- [Extraction/upload integration tests](../tests/Feature/ProposalExtractionTest.php)

## Commands (PowerShell, project directory)

```powershell
cd C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan test --compact
vendor\bin\pint --test app/Services/ProposalAnalysisService.php app/Models/ProposalAnalysis.php app/Http/Controllers/ResearchProposalController.php config/proposal_analysis.php config/preferences.php routes/web.php database/migrations/2026_09_12_060000_add_local_proposal_analysis_fields.php tests/Feature/ProposalAnalysisTest.php tests/Feature/ProposalExtractionTest.php
npm.cmd run build
php artisan migrate --force
php artisan view:cache
php artisan migrate:status
```

Use `npm.cmd` because PowerShell's policy blocks the npm `.ps1` wrapper on this
machine. The migration only adds nullable fields; do not run `migrate:fresh` or
reset the database. Tests use the isolated in-memory SQLite database.

## Manual verification

1. Sign in as the Student owner, open **Research Proposals**, and view the existing
   proposal. Select **Analyze Proposal** if it already has extracted text.
2. Check the research title, directly identified skills, evidence-backed related
   skills, and analysis date. Project type, percentage scores, and Abstract /
   Summary must not appear. The original title and download must still work.
3. GET/reload should not rerun analysis. Explicit reanalysis or extraction refresh
   updates the analysis and matching recommendations using the current proposal.
4. Upload a text-based PDF or DOCX containing a supported area such as web
   development, along with relevant implementation or data-storage evidence.
   Analysis should classify direct categories and infer only related categories
   supported by the configured evidence rules.
5. View the proposal using your existing Admin account. Existing pending analyses
   can also be run by Admin.
6. A different Student and Faculty must not gain access to the proposal or analysis.
7. A scanned PDF still shows the extraction error and retry/upload guidance. It
   must not produce analysis from missing text.

## Verification results

- The original Step 12 verification below records an earlier version of this
  workflow. Current regression results and migrations are verified in the
  implementation handoff for the expertise-matching update.
