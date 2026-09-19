# Step 19: Advisory Thresholding

## Inspection and scope

Faculty profiles already have an Admin-managed unsigned-small-integer advisory
limit, default 5. The recommendation page already reads saved scores. No assignment
table or capacity-management page existed before this step.

This step adds Admin limit editing and live capacity on recommendation cards.
Because the specification requires a real assignment count, it also introduces
the assignment table/model as the data foundation. It creates no assignments and
provides no assignment approval action; that workflow remains Step 21.

## Behavior

```text
load = count of adviser_assignments for the faculty with status = active
FULL if load >= advisory_limit; otherwise AVAILABLE
remaining = max(0, advisory_limit - load)
```

Completed and cancelled assignments do not contribute to load. Recommendations,
preferences, and later pending requests do not contribute. No manually maintained
advisee-count field is stored. Limits never increase automatically.

Admin can open **Advisory Limits** from navigation or **Manage Advisory Limits**
from the dashboard. Each faculty entry shows name, department, derived load,
limit, and status. **Edit limit** opens a CSRF-protected form. Admin also gets an
edit link beside capacity on each recommendation card.

Limits accept whole numbers from 0 to 65,535, matching the existing database type.
Zero closes capacity. Reducing the limit below existing load is allowed: existing
assignments remain, and the faculty is FULL until capacity becomes available.
Only Admin can access these pages or update limits. The service rechecks the
actor's current role, validates the value, and locks the faculty row while saving.
Extra submitted fields cannot change department, ownership, or assignment load.

The ranking page reads current limits and active counts in a batch for the visible
faculty. A full faculty remains at the same saved rank with the same saved score.
Changing a limit updates displayed availability on the next page load without
regenerating recommendations. Admin-only edit links lead back to the limit form.

For Student viewers, full faculty show a disabled **Request Adviser** button and
an explanatory message. Available faculty have their availability shown, but no
working request submission action yet. Step 20 will add requests and enforce
capacity server-side; no request endpoint is introduced in this step.

## Assignment foundation

`adviser_assignments` contains proposal/faculty foreign keys, approver, assignment
time, status (`active`, `completed`, `cancelled`), and timestamps. One unique
proposal foreign key permits one current assignment record per proposal. A future
reassignment can update that record; assignment history is not implemented here.
An index on faculty/status supports derived load queries.

Proposal/faculty deletion cascades their assignment rows. Deleting an approver
sets its foreign key to null while preserving the approved assignment and its
load. `assigned_at` remains present; an approver deletion does not free capacity.

The model guards mass assignment and exposes proposal, faculty, and approver
relationships. FacultyProfile provides `assignments()` and `activeAssignments()`;
ResearchProposal provides `assignment()`. There is no public creation route, and
recommendation generation never creates or activates assignments.

Step 21 must implement the approved Admin action and lock the same faculty row
when checking/consuming capacity. A disabled button alone is not an authorization
or capacity guarantee for those future write operations.

## Complete source

- [AdvisoryThresholdService.php](../app/Services/AdvisoryThresholdService.php)
- [AdvisoryThresholdController.php](../app/Http/Controllers/AdvisoryThresholdController.php)
- [AdvisoryLimitRequest.php](../app/Http/Requests/AdvisoryLimitRequest.php)
- [AdviserAssignment.php](../app/Models/AdviserAssignment.php)
- [Assignment-table migration](../database/migrations/2026_09_12_080000_create_adviser_assignments_table.php)
- [FacultyProfile relationships](../app/Models/FacultyProfile.php)
- [ResearchProposal relationship](../app/Models/ResearchProposal.php)
- [RecommendationController capacity reads](../app/Http/Controllers/RecommendationController.php)
- [Admin limit list](../resources/views/admin/advisory-limits/index.blade.php)
- [Admin limit editor](../resources/views/admin/advisory-limits/edit.blade.php)
- [Recommendation card availability](../resources/views/student/recommendations/card.blade.php)
- [Routes](../routes/web.php)
- [Navigation](../resources/views/layouts/navigation.blade.php)
- [Dashboard link](../resources/views/dashboard.blade.php)
- [Regression tests](../tests/Feature/AdvisoryThresholdTest.php)

All paths resolve under `C:\xampp\htdocs\skillsync`. Compiled frontend assets and
the Vite manifest are rebuilt for new Blade classes.

## Exact commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan test --compact --filter=AdvisoryThresholdTest
vendor\bin\pint --test app/Services/AdvisoryThresholdService.php app/Models/AdviserAssignment.php app/Models/FacultyProfile.php app/Models/ResearchProposal.php app/Http/Controllers/AdvisoryThresholdController.php app/Http/Controllers/RecommendationController.php app/Http/Requests/AdvisoryLimitRequest.php routes/web.php database/migrations/2026_09_12_080000_create_adviser_assignments_table.php tests/Feature/AdvisoryThresholdTest.php
php artisan test --compact
npm.cmd run build
php artisan migrate --force
php artisan view:cache
php artisan route:list --path=advisory
```

The migration adds one empty table and leaves existing limits unchanged. Do not
run database reset/fresh commands or create fake production assignments. Automated
database tests use isolated in-memory SQLite.

## Manual testing

1. Log in as Admin. Open **Advisory Limits**, select the faculty, and inspect load.
   With no assignments, the default display is `0 / 5 AVAILABLE`.
2. Temporarily set the limit to 0. Open an existing recommendation as its Student
   owner: the faculty remains ranked, now `0 / 0 FULL`, with a disabled request
   button. Saved component scores, final score, and rank remain unchanged.
3. Restore the intended limit (for example 5). Reload the ranking page without
   refreshing recommendations: availability returns to AVAILABLE.
4. Attempt a negative, fractional, missing, or oversized value. Validation should
   reject it without changing the saved limit.
5. Try Admin capacity URLs as Student/Faculty: access must be denied. Faculty's
   own profile editor cannot change advisory limits.

The automated suite creates test-only assignments to verify `5 / 5 FULL` becoming
`5 / 6 AVAILABLE`, reduction below current load, active-only counts, status changes,
deletions, approver deletion, zero/max limits, role checks, and preserved rankings.
No production assignment fixtures are inserted.

Stop after Step 19. Step 20 is Adviser Requests; final assignment remains Step 21.

## Verification results

- Targeted tests: **17 passed, 92 assertions**.
- Full suite: **359 passed, 2,702 assertions**.
- Pint, Vite production build, and Blade compilation passed.
- Three Admin advisory-limit routes were verified.
- The assignment-table migration applied successfully. Production assignments
  remain zero; the existing faculty shows **0 / 5 AVAILABLE**.
- Three users, one proposal, one analysis, and one recommendation remain present;
  the original proposal file is intact.
- Browser visual inspection was not automated; manual layout checks remain available
  through the workflow above.
