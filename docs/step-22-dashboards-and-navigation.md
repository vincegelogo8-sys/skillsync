# Step 22: Dashboard and Navigation Cleanup

## Inspection and changes

All core workflows are implemented, but dashboards still displayed the original
login message and plain link lists. Navigation accumulated every module in one row.
This step replaces those screens with role-specific summaries, recent activity,
setup guidance, and grouped workspace links. Existing workflow routes and role
permissions are retained. No database migration or dependency is needed.

## Role dashboards

| Role | Live summaries | Recent activity |
| --- | --- | --- |
| Student | Own proposals, pending requests, active assignments, proposals awaiting analysis | Own latest five requests and proposals |
| Faculty | Own pending requests, active assignments, load/limit/status, latest completed assessment | Own latest five requests and assignments |
| Admin | All proposals, pending requests, active assignments, proposals awaiting analysis, Student accounts, Faculty accounts | Latest five requests and proposals across the system |

Awaiting Analysis counts proposals without a completed analysis timestamp, including
failed or unfinished extraction. Account totals count the relevant User roles;
they do not require profiles. Faculty assessment uses the existing latest-completed
relationship and shows **Not completed** when absent. Active load uses the existing
threshold service; the dashboard cannot edit or automatically increase a limit.

Students and Faculty without profiles see **Complete Profile** guidance. Their
activity queries are scoped to no records, and simply opening a dashboard does
not create a profile. Missing assessment data is not displayed as a completed zero.

Dashboard cards link to the related existing module. Recent proposals link to their
authorized detail pages; recent Faculty assignments link to Assigned Students.
Recent requests include status and a link to the complete request list. Empty
states explain that no activity exists yet. Summaries refresh on page load.

## Navigation and workspace links

The header separates the SKILLSYNC brand, role badge, and account menu from the
work navigation. Dashboard and the main workflow links remain visible. Secondary
tools are grouped into **Profile** (Student), **Profile & Skills** (Faculty), or
**Management** (Admin), using native keyboard-operable HTML details controls.
The dashboard also exposes each secondary tool as a descriptive workspace card.

`config/workspace_navigation.php` is the shared source for labels, routes,
descriptions, and active-route patterns. A recommendation page keeps **Research
Proposals** active. Main links expose `aria-current="page"`; secondary pages mark
their matching item and highlight the group. Account Settings and POST logout
remain in the account menu. No other role's management links are added.

The layout uses wrapping navigation and responsive card grids. At narrow widths,
the secondary menu expands in normal flow instead of extending off-screen. The
existing local logo, application layout, and Tailwind style are reused.

## Safety and implementation

`/dashboard` keeps its original role redirect. Each protected role dashboard now
calls `DashboardController::show()` and the read-only DashboardSummaryService.
Queries scope Student activity by Student profile and Faculty activity by Faculty
profile. Admin queries cover all records. Recent lists have a five-record limit
and eager-load displayed relationships.

These GET requests do not extract text, analyze proposals, calculate rankings,
approve requests, create profiles, or change assignments. Existing authorization
middleware still rejects wrong-role dashboard access. User names and titles are
escaped; private file paths do not appear.

## Complete source

- [DashboardSummaryService](../app/Services/DashboardSummaryService.php)
- [DashboardController](../app/Http/Controllers/DashboardController.php)
- [Role navigation catalog](../config/workspace_navigation.php)
- [Dashboard Blade](../resources/views/dashboard.blade.php)
- [Navigation Blade](../resources/views/layouts/navigation.blade.php)
- [Routes](../routes/web.php)
- [Dashboard tests](../tests/Feature/DashboardTest.php)
- [Updated login landing assertion](../tests/Feature/Auth/AuthenticationTest.php)

All paths resolve under `C:\xampp\htdocs\skillsync`. The login test now checks
the new Student Dashboard workspace rather than the removed starter message.
The Vite build refreshes compiled CSS and its manifest for the new Blade classes.

## Exact commands

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=DashboardTest
php artisan test --compact --filter=RoleAuthorizationTest
vendor\bin\pint --test app/Services/DashboardSummaryService.php app/Http/Controllers/DashboardController.php config/workspace_navigation.php routes/web.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/DashboardTest.php
php artisan test --compact
npm.cmd run build
php artisan view:cache
php artisan route:list --path=dashboard
```

No migration, database reset, or sample-data seeding is needed. Database tests use
isolated in-memory SQLite. Existing production activity supplies live summaries.

## Manual verification

1. Sign in as each role and open Dashboard. Check that its counters and recent
   lists agree with the corresponding module pages.
2. As Student, open Research Proposals and a proposal's recommendation page.
   Research Proposals should remain the active navigation item.
3. As Faculty, open Profile & Skills. Confirm profile, expertise, preferences, and
   assessment links work and that Admin tools are absent.
4. As Admin, open Management. Confirm Accounts, Advisory Limits, Faculty Expertise,
   Competency Evaluation, Assessment Questions, and Assessment Results are reachable.
5. Use Tab and Enter to open menus and follow links. Check the header and cards at
   narrow and wide browser widths. Account Settings and Log Out must still work.
6. With an account lacking a profile, check the setup guidance and empty summaries.
   Refresh the page and confirm no profile or workflow records are created.

Automated checks cover scoped counters and recent lists, missing-profile isolation,
latest assessment display, live faculty load, Admin totals, route-catalog validity,
active navigation on recommendations, bounded recent activity, escaping, and
read-only dashboard behavior. Existing role tests cover dashboard access and login
redirects. Browser visual checks are not automated.

Stop after Step 22. Step 23 adds remaining test coverage; Step 24 performs final
end-to-end verification.

## Verification results

- Dashboard tests: **7 passed, 65 assertions**.
- Role authorization tests: **14 passed, 62 assertions**.
- Full suite: **384 passed, 2,926 assertions**.
- Pint, Vite production build, and Blade compilation passed.
- All four dashboard routes were verified.
- Read-only summary queries succeeded for all three existing production accounts.
  Production counts remain three users, one proposal, zero requests, and zero
  assignments. No data migration or workflow mutation was performed.
