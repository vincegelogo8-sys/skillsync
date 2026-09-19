# Step 8: Admin Research Advising Competency Evaluation

## Inspection and scope

Steps 1–7 were present, with eight applied migrations. The database had 3 users,
1 Faculty Profile, 2 expertise entries, and 2 preferences. No competency module or
conflicting `faculty_competencies` table existed.

Admin can now open **Competency Evaluation**, select a faculty member, enter all
five ratings, and save optional supporting remarks. Existing evaluations can be
reviewed and updated. The faculty list is paginated, shows scores or **Not evaluated**,
and indicates when a faculty member must first complete their basic profile.
Opening pages does not create profiles or invent ratings.

## Rubric and calculation

The five dimensions are System Analysis, Research Methodology, Research Documentation,
Data Analysis, and System Evaluation. Each requires an integer from 1 to 5:

| Rating | Meaning |
| --- | --- |
| 1 | Limited |
| 2 | Developing |
| 3 | Competent |
| 4 | Highly Competent |
| 5 | Advanced |

Research Advising Competency = total ratings / 25 × 100.
For example, 4 + 4 + 5 + 3 + 4 = 20, producing **80%**.
All 1s produce 20%; all 5s produce 100%. An absent evaluation remains absent,
represented as `null` by the scoring service and **Not evaluated** in the interface.

The score is calculated from the saved ratings, not stored in a duplicate column.
Its future 30% recommendation weight is not applied here. Skills Assessment and
final recommendation calculation belong to later steps.

## Storage and authorization

The new table contains `id`, unique `faculty_profile_id`, the five rating columns,
`evaluated_by`, nullable `remarks`, and timestamps. Remarks accept up to 5,000
characters and are escaped when displayed. Ratings have no automatic defaults.

Only Admin can access the evaluation routes. Faculty and Students receive HTTP 403,
including direct forged save requests. Guests are redirected to login. Submitted
owner IDs, evaluator IDs, scores, roles, and advisory limits are ignored. The target
profile comes from the route; evaluator identity comes from the authenticated Admin.
Only profiles belonging to Faculty accounts can be evaluated.

One current evaluation is saved per profile. Saves use a transaction and profile-row
lock; the unique constraint also prevents multiple evaluations for the same profile.
An update records the latest Admin evaluator, remarks, ratings, and save time. This
is a current-evaluation record, not a historical audit log.

Deleting an evaluator account sets `evaluated_by` to null and preserves the evaluation;
the interface shows **Deleted account**. Deleting the evaluated faculty profile
cascades to its competency record. Existing expertise, preferences, and advisory
limits are unaffected by saving an evaluation.

## Complete source files

Paths are relative to `C:\xampp\htdocs\skillsync`; links open full source files.

| File | Responsibility |
| --- | --- |
| [Migration](../database/migrations/2026_09_12_020000_create_faculty_competencies_table.php) | New table, unique profile, foreign keys |
| [FacultyCompetency](../app/Models/FacultyCompetency.php) | Rubric, dimension names, rating casts and relationships |
| [FacultyProfile](../app/Models/FacultyProfile.php) | Adds the competency relationship |
| [Request](../app/Http/Requests/FacultyCompetencyRequest.php) | Admin authorization and five-rating validation |
| [CompetencyService](../app/Services/CompetencyService.php) | Transactional save and normalized score |
| [Controller](../app/Http/Controllers/FacultyCompetencyController.php) | Faculty list, evaluation form and save action |
| [Routes](../routes/web.php) | Three Admin-protected routes |
| [Evaluation list](../resources/views/admin/competencies/index.blade.php) | Faculty selection, status, score and pagination |
| [Evaluation form](../resources/views/admin/competencies/edit.blade.php) | Five rating selections, remarks and saved result |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Admin competency link |
| [Dashboard](../resources/views/dashboard.blade.php) | Admin evaluation shortcut |
| [Tests](../tests/Feature/FacultyCompetencyTest.php) | Validation, scores, authorization, attribution and data preservation |

## Exact commands

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=FacultyCompetencyTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=competenc
php vendor/bin/pint --test app/Models/FacultyCompetency.php app/Models/FacultyProfile.php app/Http/Requests/FacultyCompetencyRequest.php app/Http/Controllers/FacultyCompetencyController.php app/Services/CompetencyService.php routes/web.php database/migrations/2026_09_12_020000_create_faculty_competencies_table.php tests/Feature/FacultyCompetencyTest.php
```

The migration adds only the competency table. Automated tests use in-memory SQLite,
not the existing MySQL data. Do not run `migrate:fresh`, `migrate:reset`, or `db:wipe`.

## Manual testing

Completed verification: **174 tests passed (1,422 assertions)**, including 21
competency tests. The asset build and formatting checks passed. The migration was
applied successfully; all nine migrations are applied. Existing counts remain
3 users, 1 Faculty Profile, 2 expertise entries, and 2 preferences. The new competency
table has no entries; no ratings were seeded into the application database.

1. Log in at `http://127.0.0.1:8000/login` as `systemadmin@gmail.com` using its existing password.
2. Open **Competency Evaluation** and select a faculty member with a completed profile.
3. Verify an unevaluated faculty member has five blank rating selections.
4. Select ratings **4, 4, 5, 3, 4**, in the displayed order, and add supporting remarks.
5. Click **Save Evaluation**. Expect **80.00%**, **20/25**, the evaluator name, and a save time.
6. Reload and verify all five saved selections and remarks remain present.
7. Change Data Analysis from **3** to **5** and save. Expect **88.00%** and **22/25**.
8. Leave a rating blank and try saving. The browser or server must reject the submission.
9. Return to the list and confirm the updated score appears.
10. Log in as Faculty or Student and visit `/admin/competencies`; expect HTTP 403.
11. Confirm the faculty member's expertise, preferences, and advisory limit are unchanged.

Use demonstration ratings only for an appropriate test faculty account; actual
faculty evaluations should reflect the Admin's supporting evidence. Step 9 (Faculty
Skills Assessment) is outside this implementation.
