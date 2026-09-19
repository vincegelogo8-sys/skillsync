# Step 21: Final Adviser Assignment

## Inspection and scope

Step 19 already created the assignment table and live advisory-load calculation.
Step 20 added pending requests and role-scoped review lists. This step adds the
approved Admin action that creates the final active assignment, assignment lists
for all roles, and the assigned adviser on proposal details. No migration or new
dependency is needed.

## Admin workflow

1. Open **Adviser Requests** as Admin.
2. Review the Student, proposal, requested Faculty, and linked recommendations.
3. Select **Approve & Assign** on the chosen pending request. The nearby explanation
   states that this creates the final assignment and cancels competing requests.
4. Successful approval opens **Adviser Assignments**, showing the Student, adviser,
   approver, assignment time, and Active status.

Student owners can see their final adviser under **Adviser Assignments** and on
the proposal detail page. Faculty see their assigned proposals and Students under
**Assigned Students**. Admin sees all assignments. Lists are paginated at 15 rows,
newest assignment first. Faculty receive assignment metadata; the existing private
proposal-download policy is unchanged.

## Atomic approval rules

`AdviserAssignmentService::approve($request, $admin)`:

- Reloads the actor and permits only the current Admin role.
- Locks the proposal, faculty profile, and request. All approval operations for
  the same faculty serialize through the faculty row; request operations for a
  proposal serialize through its proposal row.
- Requires a pending request with the correct Student owner and a current Faculty
  recipient. A proposal with any existing assignment record is not overwritten.
- Rechecks live active assignment count against the current advisory limit. This
  uses a locking read, so it sees committed assignments even after waiting for a
  different proposal's approval under MySQL's repeatable-read isolation.
- Creates one Active assignment with the request's proposal/faculty, the actual
  Admin ID, and the server's current assignment time. Submitted IDs cannot override
  those fields.
- Marks the selected request Approved with active_slot = 1 and responded_at.
- Marks every other pending request for that proposal Cancelled, clears each
  active_slot guard, and sets its response time. Other proposals are unaffected.
- Commits all changes together. Any failure rolls back assignment and request
  changes; unexpected errors show a safe retry message instead of raw diagnostics.

The proposal's unique assignment foreign key is an additional duplicate safeguard.
A repeated approval of the same already-approved request returns its existing
active assignment without changing approver/time or increasing load. Approving a
cancelled/declined request fails. A full faculty leaves the request Pending, so
Admin may increase the limit and retry if appropriate.

Approval derives load from actual active assignment records; no counter is manually
incremented. Recommendation scores and ranks stay unchanged. Requests, ranking
generation, and Faculty actions cannot create assignments.

The request-submission capacity read was also changed to a locking read, matching
the approval workflow's fresh-capacity behavior after lock waits.

## Boundaries

This step implements final assignment through a pending request. It does not
silently reassign a proposal or reopen a completed/cancelled assignment. An existing
assignment record requires a separately designed lifecycle action if reassignment
is needed later. Completion/cancellation/reassignment editing is not introduced
here. Existing deletion rules and active-only load calculations remain intact.

No production requests are approved by the coding agent. Admin makes the actual
assignment decision through the application.

## Routes and access

| Role | Method | Path | Purpose |
| --- | --- | --- | --- |
| Admin | POST | `/admin/requests/{adviserRequest}/approve` | Approve and assign |
| Admin | GET | `/admin/assignments` | View all assignments |
| Student | GET | `/student/assignments` | View own proposal assignments |
| Faculty | GET | `/faculty/assignments` | View assigned Students/proposals |

Authentication and role middleware protect each route. The service independently
checks Admin authority. POST uses CSRF protection. GET routes only read scoped
assignments and do not change load. Missing profiles return empty lists rather
than exposing other users' records. User-provided display text is escaped, and
private proposal file paths are not shown. If an approver account is deleted,
the assignment remains and displays **Former Admin account**.

## Complete source

- [AdviserAssignmentService](../app/Services/AdviserAssignmentService.php)
- [AdviserAssignmentController](../app/Http/Controllers/AdviserAssignmentController.php)
- [Request-service capacity read](../app/Services/AdviserRequestService.php)
- [Proposal-controller assignment load](../app/Http/Controllers/ResearchProposalController.php)
- [Routes](../routes/web.php)
- [Admin approval action](../resources/views/requests/index.blade.php)
- [Assignment lists](../resources/views/assignments/index.blade.php)
- [Assigned adviser on proposal](../resources/views/student/proposal/show.blade.php)
- [Role navigation](../resources/views/layouts/navigation.blade.php)
- [Assignment regression tests](../tests/Feature/AdviserAssignmentTest.php)

All source paths resolve under `C:\xampp\htdocs\skillsync`.

## Exact commands

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=AdviserAssignmentTest
vendor\bin\pint --test app/Services/AdviserAssignmentService.php app/Services/AdviserRequestService.php app/Http/Controllers/AdviserAssignmentController.php app/Http/Controllers/ResearchProposalController.php routes/web.php tests/Feature/AdviserAssignmentTest.php
php artisan test --compact
npm.cmd run build
php artisan view:cache
php artisan route:list --path=assignments
php artisan route:list --path=approve
```

No migration, database reset, or production fixture insertion is required. All
automated database tests use isolated in-memory SQLite.

## Manual verification

1. As Student, submit a request for an available recommended faculty.
2. As Admin, open the request list and choose **Approve & Assign**. Confirm one
   Active assignment, one Approved request, and an increase of one in advisory load.
3. Check the Student's proposal and assignments page and the Faculty's Assigned
   Students page. Each should show only the appropriate assignment.
4. If the proposal had other pending requests, verify they now show Cancelled.
5. Repeat the original approval POST: it must not duplicate the assignment or change
   its original approver/time. The request list no longer offers approval for it.
6. With a full faculty and another pending request, approval must fail while the
   request remains Pending. Increase the limit as Admin and retry if desired.
7. Confirm Student/Faculty cannot call the Admin approval route and that saved
   recommendation ranks and scores stay unchanged after approval.

Automated tests cover the above behaviors, changed roles, preserving an existing
assignment, scoped lists, escaped text, deleted approvers, and rollback when saving
the approved request fails after assignment insertion. The last-slot test exercises
sequential competing approvals; simultaneous MySQL transactions are not simulated
by the SQLite suite.

Stop after Step 21. Step 22 is dashboard and navigation cleanup.

## Verification results

- Targeted tests: **8 passed, 80 assertions**.
- Full suite: **377 passed, 2,860 assertions**.
- Pint, production Vite build, and Blade compilation passed.
- All three assignment-list routes and the Admin approval route were verified.
- Existing production data remains three users, one proposal, one recommendation,
  zero requests, and zero assignments. The original proposal file is present.
- No production approval was performed. Browser visual checks were not automated.
