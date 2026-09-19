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

Results display the research title, objectives, up to 12 extracted keywords,
project type when supported, and analysis date. Keywords use only title and
objectives. Technologies and up to three expertise areas remain internal inputs
to Preference Compatibility and Topic Alignment respectively; their cards are
removed. Abstract/Summary is no longer extracted or displayed. Its obsolete
database column and existing values are preserved, including during refresh.

`analyzed_at` distinguishes a completed analysis with no matches from text that
has not been analyzed. Proposal `status` continues to describe extraction;
the results section separately shows **Analysis complete**. Repeated POSTs reuse
saved results; GET requests never extract or analyze. A transaction and proposal
row lock serialize analysis writes. A failed analysis save preserves the original
document and extracted text and allows retry.

## Transparent rules and limitations

- Normalize case, whitespace, and hyphens. Match whole terms, preserving technical
  punctuation. Aliases such as NodeJS, postgres, sklearn, and Amazon Web Services
  map to the same canonical technology names used by faculty preferences.
- Consume longer technology names first. React Native alone does not identify
  React, C++/C# do not identify C, and MySQL does not identify SQL.
- Only technologies mentioned in the title or objectives are recorded. Python does
  not imply TensorFlow, for example. These are mention-based rules; mentions in
  alternatives or negative statements within title/objectives can still be identified.
- Each distinct configured phrase adds three points when present in the title and
  one when present in the objectives. Repeating the same phrase does not add more
  points. Highest positive project-type score wins; the top three positive
  expertise scores are selected. Ties follow configuration order. These internal
  rule scores are not adviser recommendation scores or confidence percentages.
- Keywords prioritize configured multiword phrases, then mentioned technologies,
  then repeated non-stop words (at least twice). Generic standalone terms such as
  system, study, research, project, development, information, and application are
  excluded. Meaningful phrases can contain those words.
- Abstract and summary headings remain section boundaries so their content cannot
  become title/objective evidence. Original extracted source text remains available.
- Explicit analysis and recommendation generation rebuild evidence from title and objectives.
  Refresh Extraction re-reads the file and recalculates existing recommendations atomically.
  See [Title and objectives analysis](proposal-title-objectives.md).

The shared technology list now also includes React Native, Android, AWS, Azure,
Google Cloud, Cisco, Packet Tracer, Wireshark, and SQL. Existing faculty preference
selections are preserved. Expertise names and project types use the existing lists.

Adviser ranking, component scores, recommendations, requests, and assignment are
not implemented by this step.

## Complete source

- [Analysis service](../app/Services/ProposalAnalysisService.php)
- [Controlled dictionaries, aliases, stop words](../config/proposal_analysis.php)
- [Shared preference vocabulary](../config/preferences.php)
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
2. Check the research title, objectives, extracted keywords, project type, and date.
   Technologies Mentioned, Identified Expertise, and Abstract / Summary must not
   appear. The original title and download must still work.
3. Refresh: the analyzed timestamp and results should remain unchanged.
4. Upload a text-based PDF or DOCX containing a labeled abstract, facial recognition,
   OpenCV, Python, and MySQL. Analysis should run after extraction, identify Computer
   Vision evidence, and never add TensorFlow unless it is mentioned.
5. View the proposal using your existing Admin account. Existing pending analyses
   can also be run by Admin.
6. A different Student and Faculty must not gain access to the proposal or analysis.
7. A scanned PDF still shows the extraction error and retry/upload guidance. It
   must not produce analysis from missing text.

Stop after Step 12. Multi-expertise strength and later adviser scoring are separate
steps.

## Verification results

- Full suite: **252 tests passed, 2,206 assertions**.
- Pint passed for changed PHP files; Vite production build succeeded.
- Migration applied successfully. Three users, one proposal, and one extracted-text
  row remain; the original proposal file is present. The existing analysis remains
  pending until its owner or Admin selects **Analyze Proposal**.
