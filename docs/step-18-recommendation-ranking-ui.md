# Step 18: Recommendation Ranking Blade UI

## Inspection and changes

Step 17 already calculates, ranks, and stores faculty recommendations, with
component scores and detailed evidence. This step adds the shared Student/Admin
Blade page, protected GET/POST routes, and a link from proposal details. It uses
the existing application layout, local Tailwind assets, and native HTML details
controls. No new package or database migration is needed.

## User workflow

1. Sign in as the Student owner or Admin and open **Research Proposals**.
2. Open a proposal and select **View Adviser Recommendations**.
3. If analysis is unfinished, return to the proposal and complete text extraction
   and analysis. The ranking page explains this prerequisite.
4. Select **Generate Recommendations**. This calls the Step 17 service and saves
   the ranking. The page redirects back with a success or empty-candidate message.
5. Review rank, faculty name and department, final percentage, four criterion
   percentages, matched expertise/proficiency, and missing competency/assessment
   notices. All percentages display two decimal places.
6. Open **View score breakdown** for final weighted contributions, cosine and
   multi-expertise components, required/missing expertise, project-type matching,
   and matched/unmatched technologies.
7. Use **Refresh Recommendations** to reflect subsequent faculty input changes.
   Reloading or paginating the page only reads the saved ranking.

Admin sees the proposal owner's name and student number for context. Each card
shows its generation timestamp. Missing-data messages describe the state at that
generation time; faculty name and department are displayed from the current
profile. Scores and breakdowns remain the saved snapshot until refreshed.

Ten faculty cards appear per page, ordered by saved rank. Pagination preserves
the stored ranks. Cards use a responsive grid for component scores, and the
contribution table can scroll horizontally. Native `<details>` controls work
with keyboard input and require no JavaScript.

No faculty are filtered by advisory limit. Availability/load and request controls
are deferred to Steps 19–20; this step does not invent an AVAILABLE/FULL status,
create requests, or assign an adviser.

## Access, errors, and safe reads

Routes:

| Role | Method | Path | Action |
| --- | --- | --- | --- |
| Student | GET | `/student/proposals/{proposal}/recommendations` | Read own saved ranking |
| Student | POST | `/student/proposals/{proposal}/recommendations` | Generate/refresh own ranking |
| Admin | GET | `/admin/proposals/{proposal}/recommendations` | Read saved ranking |
| Admin | POST | `/admin/proposals/{proposal}/recommendations` | Generate/refresh ranking |

Authentication and role middleware wrap the routes. The controller checks proposal
ownership before reading or generating. Other students receive 404, and Faculty
cannot access either route group. POST forms include CSRF tokens. The generation
service performs its own authorization as well.

GET does not generate recommendations, extract documents, or analyze text. A
missing analysis produces actionable validation feedback. Unexpected generation
failures are logged server-side and display a safe retry message. Step 17's
transaction preserves the previous ranking if a refresh fails. No raw exception
details, private file paths, faculty emails, or password hashes are rendered.

Empty states cover no saved recommendations and no available faculty profiles.
There is no fake/sample production recommendation data.

## Complete source files

- [RecommendationController.php](../app/Http/Controllers/RecommendationController.php)
- [Routes](../routes/web.php)
- [Proposal detail link](../resources/views/student/proposal/show.blade.php)
- [Ranking page](../resources/views/student/recommendations/index.blade.php)
- [Faculty card and breakdown](../resources/views/student/recommendations/card.blade.php)
- [Page regression tests](../tests/Feature/RecommendationPageTest.php)

Paths resolve under `C:\xampp\htdocs\skillsync`. The existing
[RecommendationService](../app/Services/RecommendationService.php) is reused without
changing the scoring algorithm. The Vite build updates the compiled asset manifest
and CSS in `public/build` for the new Blade classes.

## Exact commands

PowerShell:

```powershell
cd C:\xampp\htdocs\skillsync
php artisan test --compact --filter=RecommendationPageTest
vendor\bin\pint --test app/Http/Controllers/RecommendationController.php routes/web.php tests/Feature/RecommendationPageTest.php
php artisan test --compact
npm.cmd run build
php artisan view:cache
php artisan route:list --path=recommendations
```

Use `npm.cmd` because the PowerShell `.ps1` wrapper is blocked by the local execution
policy. Do not reset or migrate the database for this step. Database tests use
isolated in-memory SQLite.

## Manual verification

- Follow the Student workflow above and check the four displayed criterion scores
  and expanded contributions against the final result.
- Refresh the browser: scores and generated time must remain unchanged. Select
  **Refresh Recommendations** explicitly to recalculate.
- Open the same proposal using Admin. Confirm the Student identity, ranking,
  generation action, and back link use the Admin workflow.
- Try a different Student or Faculty account: access must be denied.
- Check a proposal with unfinished analysis, and a ranking containing faculty with
  missing competency/assessment data, for the guidance and notices.
- At narrow browser widths, check card wrapping and horizontal table scrolling.
  Use Tab/Enter to open the score breakdown. With more than ten candidates, check
  that page two starts at the saved rank rather than rank one.

Automated tests exercise actual HTTP generation and rendered Blade results,
Student/Admin access, read-only reloads, pagination, pending/empty states, safe
failure feedback, refresh reuse, escaped names, and private-field exclusion.

Stop after Step 18. Step 19 is Advisory Thresholding.

## Verification results

- Ranking page tests: **8 passed, 96 assertions**.
- Full suite: **342 passed, 2,610 assertions**.
- Pint passed for changed PHP files.
- Vite production build and Blade view compilation succeeded.
- All four Student/Admin ranking routes were verified in the route list.
- No production rankings were generated during implementation. Browser visual
  inspection was not automated; responsive layout checks are listed above.
