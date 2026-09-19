# Step 17: Final Weighted Recommendation

## Inspection and scope

Steps 13–16 already provide multi-expertise, TF-IDF/cosine similarity, Research
Topic Alignment, and Preference Compatibility. Admin competency ratings and
completed skills assessments are also stored. Step 17 connects these components,
ranks faculty, and persists a transparent recommendation snapshot.

The existing project and database are retained. The only schema addition is the
`recommendations` table. There is no ranking page or new HTTP endpoint yet; the
ranking Blade UI is Step 18. Generation does not assign an adviser.

## Exact formula

```text
Final Score = Research Topic Alignment × 0.40
            + Research Advising Competency × 0.30
            + Preference Compatibility × 0.20
            + Skills Assessment × 0.10

Example: 88 × .40 + 80 × .30 + 75 × .20 + 90 × .10 = 83.20%
```

Each criterion is clamped individually to 0–100. Non-finite values are rejected.
Multi-expertise remains inside Topic Alignment's existing 70/30 calculation.
Neither the final 40% topic-alignment weight nor the 20% preference weight is
applied twice. Faculty scores are independent percentages; they are not normalized
to sum to 100 across faculty.

`calculate($rta, $rac, $pc, $sa)` returns all four bounded criteria, `final_score`,
and `contributions` keyed by criterion. Calculations retain precision. Database
score fields use eight decimal places; the future UI should display two decimals.

## Generation and ranking

`generate(ResearchProposal $proposal, User $actor)`:

1. Opens a transaction and locks the proposal row, serializing generations for the
   same proposal. It reloads the actor and permits only Admin or the Student owner.
2. Requires completed saved proposal analysis. It never extracts text or reruns
   analysis. Pending analysis produces a validation error.
3. Selects all profiles belonging to current Faculty users. Faculty with no
   expertise, preferences, evaluation, or assessment remain eligible to be scored.
   Advisory limits do not filter or penalize candidates.
4. Calculates Topic Alignment for the full shared faculty corpus and Preference
   Compatibility from current preferences. Competency comes from the existing
   Admin rubric normalization service.
5. Uses the latest **completed** assessment, ordered by completion time then ID.
   A newer unfinished attempt is ignored. This uses the latest completed result,
   not the highest historical mark.
6. Gives missing competency or assessment a zero contribution without redistributing
   weights. Explicit missing-data flags distinguish absence from a recorded zero.
7. Sorts by descending final score at the same eight-decimal precision used for
   storage. Equal scores use ascending faculty profile ID, yielding deterministic
   consecutive ranks. Displayed two-decimal scores can appear tied while their
   stored values differ.
8. Updates or creates one row per proposal/faculty pair and removes obsolete rows
   for that proposal only. The entire refresh rolls back if any save fails.

An empty candidate set returns an empty collection and clears obsolete results
for that proposal. A repeated explicit generation refreshes existing rows and
their timestamps without duplicate rows. Reading the recommendation relationship
does not regenerate anything. Changes to faculty input data are reflected on the
next explicit generation, preserving saved results until then.

The assessment selection, missing-data treatment, and tie-break rules above are
implementation choices for cases not specified in the original formula.

## Stored evidence and relationships

The table has the required proposal/faculty foreign keys, seven component/final
score columns, rank, timestamps, a unique proposal/faculty constraint, and a
proposal/rank index. Foreign keys cascade when the proposal or faculty profile is
deleted. After deletion, regenerate surviving results to refresh the corpus and
consecutive ranks.

The `details` JSON field preserves weights, final contributions, Topic Alignment
and Preference Compatibility breakdowns, competency/assessment missing flags,
source competency and assessment IDs, and analysis ID/time. This supports explaining
the stored scores after source values change. Source IDs inside JSON are historical
references and may no longer resolve after source deletion.

`Recommendation` belongs to ResearchProposal and FacultyProfile; both have
`recommendations()` relationships. `FacultyProfile::latestCompletedAssessment()`
provides a queryable latest completed result without loading all attempts.
`SkillsAssessmentService::score()` returns the completed percentage or `null`.

Advisory availability, requests, and final assignments remain later steps. Normal
recommendation computation runs locally with no external APIs.

## Complete source files

- [RecommendationService.php](../app/Services/RecommendationService.php)
- [Recommendation.php](../app/Models/Recommendation.php)
- [Recommendations migration](../database/migrations/2026_09_12_070000_create_recommendations_table.php)
- [ResearchProposal.php](../app/Models/ResearchProposal.php)
- [FacultyProfile.php](../app/Models/FacultyProfile.php)
- [SkillsAssessmentService.php](../app/Services/SkillsAssessmentService.php)
- [RecommendationTest.php](../tests/Feature/RecommendationTest.php)

All paths above are relative to `C:\xampp\htdocs\skillsync\docs`; complete source
is available through each link.

## Exact commands and manual verification

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan test --compact --filter=RecommendationTest
vendor\bin\pint --test app/Services/RecommendationService.php app/Services/SkillsAssessmentService.php app/Models/Recommendation.php app/Models/ResearchProposal.php app/Models/FacultyProfile.php database/migrations/2026_09_12_070000_create_recommendations_table.php tests/Feature/RecommendationTest.php
php artisan test --compact
php artisan migrate --force
php artisan migrate:status
php artisan tinker
```

The migration is additive. Do not run `migrate:fresh`, reset the database, or seed
sample accounts. Automated database tests use in-memory SQLite.

Read-only formula check in Tinker:

```php
$service = app(\App\Services\RecommendationService::class);
$result = $service->calculate(88, 80, 75, 90);
number_format($result['final_score'], 2); // "83.20"
```

For an optional manual integration check, first complete proposal analysis through
the existing Student/Admin proposal page. Then explicitly generate a stored ranking
using a chosen proposal ID and your existing Admin account (this writes recommendation
rows, but does not change accounts or assign faculty):

```php
$proposal = \App\Models\ResearchProposal::findOrFail(1); // replace 1 with the intended proposal ID
$admin = \App\Models\User::where('role', 'admin')->firstOrFail();
$rows = $service->generate($proposal, $admin);
$rows->map(fn ($row) => ['faculty_id' => $row->faculty_profile_id, 'rank' => $row->rank, 'score' => number_format((float) $row->final_score, 2), 'missing_competency' => $row->details['missing_competency'], 'missing_assessment' => $row->details['missing_assessment']]);
$proposal->recommendations()->orderBy('rank')->get(); // reads saved results only
exit;
```

Tests verify exact weights and specification examples, component clamping,
non-finite rejection, actual stored pipeline results, latest completed assessment
selection (including completion-time ties), missing flags, stable ranking,
repeat generation, permissions, role changes, empty candidates, rollback after a
partial refresh, cascade cleanup, and unchanged proposal analysis. Capacity does
not affect final scores.

Stop after Step 17. Step 18 builds the Recommendation Ranking Blade UI.

## Verification results

- Targeted tests: **16 passed, 48 assertions**.
- Full suite: **334 passed, 2,514 assertions**.
- Pint passed for all changed PHP files.
- Recommendations migration applied successfully to the existing database.
- Production counts remain three users, one faculty profile, one proposal, and
  one analysis; the original proposal file remains present.
- The latest-completed-assessment relationship query also succeeded on the local
  production database.
- No production recommendation generation was run. The new table is empty until
  an explicit generation; the Step 18 UI will provide that workflow.
