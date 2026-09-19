# Step 16: Preference Compatibility

## Inspection and changes

Step 7 stores faculty preferences by project type and technology. Step 12 saves
the proposal's identified project type and mentioned technologies using the same
canonical vocabulary. Step 16 adds a reusable scoring service using these inputs.
No database migration, package, route, or frontend change is needed.

Complete source:

- [PreferenceCompatibilityService.php](../app/Services/PreferenceCompatibilityService.php)
  (`C:\xampp\htdocs\skillsync\app\Services\PreferenceCompatibilityService.php`)
- [PreferenceCompatibilityTest.php](../tests/Feature/PreferenceCompatibilityTest.php)
  (`C:\xampp\htdocs\skillsync\tests\Feature\PreferenceCompatibilityTest.php`)

Existing vocabulary: [config/preferences.php](../config/preferences.php).
Existing preference editing: [FacultyPreferenceService.php](../app/Services/FacultyPreferenceService.php).

## Formula

```text
Project Type Match = 100 if the proposal type is among faculty preferences, else 0
Technology Match = matching proposal technologies / total proposal technologies × 100
Preference Compatibility = Project Type Match × 0.50 + Technology Match × 0.50
```

For a Web-Based System using Laravel, PHP, and MySQL, a faculty member who prefers
Web-Based System and Laravel, MySQL, React receives:

```text
Project Type Match = 100
Technology Match = 2 / 3 × 100 = 66.6666…
Preference Compatibility = 50 + 33.3333… = 83.3333… (display: 83.33%)
```

The denominator counts distinct **proposal** technologies, not faculty preferences.
Unrelated preferences neither penalize nor increase the score. Duplicate names
count once. Matches use exact canonical values; aliases are resolved by proposal
analysis upstream. Unknown or malformed values raise `InvalidArgumentException`.

No identified project type (`null`) gives a zero project-type component. No
identified technologies gives a zero technology component, avoiding division by
zero. Weights stay 50/50: a project-only match scores 50, a technology-only full
match scores 50, and no evidence or no matching preferences scores zero.

Results use a 0–100 scale and retain precision. Display with `number_format(..., 2)`.
The final recommendation's 20% preference weight is not applied here; Step 17
will apply it once.

## Interfaces and breakdown

```php
$service = app(\App\Services\PreferenceCompatibilityService::class);
$result = $service->calculate(
    'Web-Based System',
    ['Laravel', 'PHP', 'MySQL'],
    ['Web-Based System'],
    ['Laravel', 'MySQL', 'React'],
);
```

Returned fields:

| Field | Meaning |
| --- | --- |
| `project_type_match` | Boolean match indicator |
| `project_type_score` | 100 or 0 |
| `technology_score` | Matched proportion, on a 0–100 scale |
| `project_type_contribution` | Project-type score × 0.50 |
| `technology_contribution` | Technology score × 0.50 |
| `preference_compatibility_score` | Sum of contributions |
| `technology_count` | Number of distinct proposal technologies |
| `matched_technologies` | Proposal technologies preferred by the faculty |
| `unmatched_technologies` | Remaining proposal technologies |

For saved analysis and faculty profiles:

```php
$results = $service->forProposal($completedAnalysis, $facultyProfiles);
$result = $results[$facultyId];
```

The model method validates completed analysis and saved faculty profiles, then
reads current preferences for all supplied candidates in one query. It avoids
stale loaded relationships, supports generators, and collapses duplicate IDs.
Results preserve candidate order and are keyed by profile ID. Faculty without
preferences receive zero and remain present. An empty candidate list returns an
empty result after proposal-input validation.

Expertise/proficiency, competency, assessment, and advisory limits are not inputs.
The service does not extract text, rerun analysis, write scores, rank candidates,
or assign advisers. Future recommendation code will store this component.
Future controllers must authorize access before showing results; this service
does not add a public endpoint. The ranking page remains Step 18.

## Exact testing commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=PreferenceCompatibilityTest
vendor\bin\pint --test app/Services/PreferenceCompatibilityService.php tests/Feature/PreferenceCompatibilityTest.php
php artisan test --compact
php artisan tinker
```

In Tinker, run this example without modifying your database:

```php
$result = app(\App\Services\PreferenceCompatibilityService::class)->calculate('Web-Based System', ['Laravel', 'PHP', 'MySQL'], ['Web-Based System'], ['Laravel', 'MySQL', 'React']);
$result;
number_format($result['preference_compatibility_score'], 2); // "83.33"
exit;
```

Tests cover the specification example, full/partial/no matches, missing type,
zero technologies, multiple preferred types, duplicates, unrelated preferences,
invalid canonical values, completed proposal-analysis integration, fresh preference
changes, generators, duplicate candidates, pending analysis, unsaved faculty,
independence from expertise, and preservation of proposal analysis. Database tests
use isolated in-memory SQLite.

Stop after Step 16. Step 17 is Final Weighted Recommendation.

## Verification results

- Targeted tests: **19 passed, 76 assertions**.
- Full suite: **318 passed, 2,466 assertions**.
- Pint passed for both new PHP files.
- No production data or schema changes were made.
