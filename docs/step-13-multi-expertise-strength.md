# Step 13: Multi-Expertise Strength

## Existing data and changes

Step 6 already stores canonical faculty expertise names with proficiency scores
from 0 to 100. Step 12 already saves up to three identified expertise areas in
`proposal_analyses`. Step 13 adds a reusable calculation service using those records.
No schema, account, proposal, route, or frontend changes are needed.

Complete source files:

- [MultiExpertiseService.php](../app/Services/MultiExpertiseService.php)
  (`C:\xampp\htdocs\skillsync\app\Services\MultiExpertiseService.php`)
- [MultiExpertiseTest.php](../tests/Feature/MultiExpertiseTest.php)
  (`C:\xampp\htdocs\skillsync\tests\Feature\MultiExpertiseTest.php`)

## Calculation

`Strength = sum of faculty proficiency in required areas / number of required areas`

| Required-area proficiency | Strength displayed |
| --- | ---: |
| 90, 80, 70 | 80.00% |
| 95, 95, 90 | 93.33% |
| 90, 80, missing | 56.67% |
| 90, missing, missing | 30.00% |
| 90 (one required area) | 90.00% |
| 90, 60 (two required areas) | 75.00% |
| All required expertise missing | 0.00% |

Missing faculty expertise contributes zero and stays in the denominator. Unrelated
faculty expertise does not affect the score. A recorded zero and a missing entry
both contribute zero but are distinguished in the breakdown.

An empty required-area list returns zero with `required_count = 0` and an empty
breakdown. Callers can distinguish that absence of evidence from a faculty member
with no matching expertise. The saved-model method rejects incomplete proposal
analysis rather than treating it as a completed zero-evidence result.

Duplicate requirements count once. Required names must come from `config/expertise.php`;
unknown names or more than three distinct areas raise `InvalidArgumentException`.
Matched proficiency values must be finite integers/floats and are clamped to
0–100. Invalid values raise an exception rather than silently producing a score.

The service returns a percentage on the 0–100 scale with full calculation precision.
Use `number_format($result['score'], 2)` for display. Retaining precision allows later
weighted calculations to round only their displayed result.

## Service use

```php
$service = app(\App\Services\MultiExpertiseService::class);

// Standalone calculation, without database reads or writes:
$result = $service->calculate(
    ['Web Development', 'Machine Learning / Data Analytics', 'Database Systems'],
    ['Web Development' => 90, 'Machine Learning / Data Analytics' => 80],
);

// Result:
// score: 56.666666666666664 (display as 56.67%)
// required_count: 3
// breakdown: each required expertise name, proficiency_score, and missing flag

// Later recommendation code can supply existing Eloquent models:
$result = $service->forProposal($completedAnalysis, $facultyProfile);
```

`forProposal()` reads the saved analysis fields and queries current faculty
expertise, avoiding stale preloaded expertise relationships. It does not rerun
document extraction or proposal analysis and does not write scores into either
source record. The later recommendation pipeline will store component results
alongside its other scores. Callers must enforce access before displaying results;
this internal service adds no public endpoint.

Multi-expertise remains a component of Research Topic Alignment. This step applies
neither the later 30% internal weight nor the final 40% topic-alignment weight.
Ranking and the ranking Blade page remain later steps. There is no new button
on the proposal page for Step 13.

## Exact testing commands

From PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=MultiExpertiseTest
vendor\bin\pint --test app/Services/MultiExpertiseService.php tests/Feature/MultiExpertiseTest.php
php artisan test --compact
```

For a manual calculation without modifying your database:

```powershell
php artisan tinker
```

Then enter:

```php
$result = app(\App\Services\MultiExpertiseService::class)->calculate(['Web Development', 'Machine Learning / Data Analytics', 'Database Systems'], ['Web Development' => 90, 'Machine Learning / Data Analytics' => 80]);
$result;
number_format($result['score'], 2); // "56.67"
exit;
```

Tests cover full, partial, missing and zero matches, stronger proficiency, fewer
requirements, duplicate requirements, unrelated expertise, bounds, invalid inputs,
pending analysis, actual saved Step 12 analysis, current faculty changes, and
preservation of source records. Database tests use isolated in-memory SQLite.

No migration, dependency installation, database reset, or frontend rebuild is
needed. Stop after Step 13; Step 14 is local TF-IDF and cosine similarity.

## Verification results

- Targeted tests: **18 passed, 85 assertions**.
- Full application suite: **270 passed, 2,291 assertions**.
- Pint passed for both new PHP files.
