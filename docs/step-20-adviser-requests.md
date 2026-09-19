# Step 20: Adviser Requests

## Inspection and scope

The ranking page and live capacity checks already exist. Step 20 adds request
submission, pending-request cancellation, pending-request decline, and scoped
request lists for Student, Faculty, and Admin. It introduces one additive table,
`adviser_requests`. Approval and final assignment remain Step 21.

## Workflow

- Student: open a proposal's recommendations and choose **Request Adviser** for
  an available recommended faculty member. Successful submission redirects to
  **Adviser Requests** with a Pending record. **Cancel Request** closes an owned
  pending request.
- Faculty: open **Adviser Requests** in navigation to see requests addressed to
  their profile. **Decline Request** closes a pending request addressed to them.
- Admin: open **Adviser Requests** to see all requests and decline pending requests.
  There is no approval button in this step.

All lists show proposal title, faculty, status, request time, and response time
when present. Faculty/Admin also see the Student name and student number. Student
and Admin can return to recommendations. Faculty receive request metadata; the
existing private proposal-download access rules remain unchanged.

On recommendation cards, an active request displays a disabled **Already Requested**
button. Full faculty remain ranked with their unchanged scores and disabled request
buttons. An already assigned proposal cannot submit another request.

## Submission and state rules

The service reloads and locks the proposal, then locks the target faculty profile.
It verifies the current actor is the Student owner, the target still has a Faculty
role, analysis is completed, and the target is in saved recommendations. It rejects
active assignments, duplicate pending/approved requests for the same proposal and
faculty, and current FULL capacity. This recheck handles stale browser pages and
manual POST attempts; the disabled button is not the only enforcement.

All ownership, status, and timestamps are assigned server-side. Submitted fields
cannot spoof ownership or request approval. Requests do not reserve a slot, alter
the advisory limit, change recommendation scores, or create assignments. Several
proposals may request the same available faculty; Step 21 must recheck capacity
at final approval.

The original specification does not restrict a proposal to one pending target.
This implementation prevents duplicate active requests per proposal/faculty pair;
a Student may request different recommended faculty for the same proposal.
Step 21 must resolve competing pending requests when a final adviser is assigned.

Only pending requests can be cancelled or declined. Cancellation belongs to the
Student owner; decline belongs to the recipient Faculty or Admin. Closing a
request locks the proposal and request row, sets responded_at, and preserves the
record. Repeating a terminal action gives validation feedback. The `approved`
status is defined for Step 21 but no Step 20 endpoint sets it.

Faculty decline and Admin decline are the review actions provided in this step;
only Admin's later approved assignment action will create an active assignment.

## Storage and duplicate protection

`adviser_requests` stores Student, Faculty, and proposal foreign keys; status;
requested_at/responded_at; timestamps; and a nullable `active_slot` guard.

Pending/approved records use active_slot = 1. Declined/cancelled records use null.
A unique index on proposal, faculty, active_slot prevents duplicate active pairs
while allowing multiple terminal history rows. MySQL and SQLite allow those
multiple null-key rows. The proposal lock also serializes submissions/closures
for the same proposal. Future approval code must retain active_slot = 1 for an
approved record and use the same locking order.

Foreign keys cascade when the owning Student profile, Faculty profile, or proposal
is deleted. The model guards mass assignment. No recommendations or assignment
rows are created as a side effect of requests.

## Routes

| Role | Method | Path |
| --- | --- | --- |
| Student | GET | `/student/requests` |
| Student | POST | `/student/proposals/{proposal}/requests/{facultyProfile}` |
| Student | PATCH | `/student/requests/{adviserRequest}/cancel` |
| Faculty | GET | `/faculty/requests` |
| Faculty | PATCH | `/faculty/requests/{adviserRequest}/decline` |
| Admin | GET | `/admin/requests` |
| Admin | PATCH | `/admin/requests/{adviserRequest}/decline` |

All routes use authentication and role middleware. Ownership/recipient checks
also run inside the service. Forms include CSRF tokens. Unauthorized object
access returns 404; the wrong role route group returns 403. Request lists are
paginated at 15 records, newest first, and render user text escaped.

## Complete source

- [Request-table migration](../database/migrations/2026_09_12_090000_create_adviser_requests_table.php)
- [AdviserRequest model](../app/Models/AdviserRequest.php)
- [AdviserRequestService](../app/Services/AdviserRequestService.php)
- [AdviserRequestController](../app/Http/Controllers/AdviserRequestController.php)
- [RecommendationController request state](../app/Http/Controllers/RecommendationController.php)
- [Routes](../routes/web.php)
- [Request list and actions](../resources/views/requests/index.blade.php)
- [Ranking-page feedback](../resources/views/student/recommendations/index.blade.php)
- [Ranking-card request button](../resources/views/student/recommendations/card.blade.php)
- [Navigation](../resources/views/layouts/navigation.blade.php)
- [Regression tests](../tests/Feature/AdviserRequestTest.php)

All paths resolve under `C:\xampp\htdocs\skillsync`. Built CSS and the Vite manifest
are refreshed for the added Blade content.

## Exact commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan test --compact --filter=AdviserRequestTest
vendor\bin\pint --test app/Services/AdviserRequestService.php app/Models/AdviserRequest.php app/Http/Controllers/AdviserRequestController.php app/Http/Controllers/RecommendationController.php routes/web.php database/migrations/2026_09_12_090000_create_adviser_requests_table.php tests/Feature/AdviserRequestTest.php
php artisan test --compact
npm.cmd run build
php artisan migrate --force
php artisan view:cache
php artisan route:list --path=requests
```

The migration creates an empty table. Do not run reset/fresh commands or seed
fake production requests. Automated database tests use isolated in-memory SQLite.

## Manual verification

1. Sign in as the Student owner, open recommendations, and request an available
   faculty. Confirm Pending on the request list and Already Requested on the card.
2. Repost the same request: it should be rejected without adding another active row.
3. Cancel the pending request and submit again. Both historical and new records
   should appear, with only the new request Pending.
4. Sign in as the recipient Faculty or Admin and decline a pending request. Check
   that the Student sees Declined and can submit a new request later.
5. Set a faculty limit to zero as Admin, then try submitting from a previously
   opened available card. Server-side capacity validation must reject it.
6. Verify another Student/Faculty cannot see or close a request that is not theirs.
7. Check that requests and responses leave assignment count, load, ranks, and
   recommendation scores unchanged.

Stop after Step 20. Step 21 is Final Adviser Assignment.

## Verification results

- Targeted tests: **10 passed, 78 assertions**.
- Full suite: **369 passed, 2,780 assertions**.
- Pint, Vite build, and Blade compilation passed.
- Request-table migration applied successfully and all seven routes were verified.
- No production requests or assignments were created during implementation.
- Browser visual inspection was not automated; manual workflow checks are listed above.
