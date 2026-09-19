# Step 6: Faculty Expertise

## Inspection and scope

The project already had authentication, three roles, Student and Faculty Profile,
Admin-only account creation, and the landing page. Six migrations were applied.
The existing database had 3 accounts, 1 Student Profile, and 1 Faculty Profile.
There was no existing expertise implementation or conflicting table in `skillsync`.

`php artisan db:show --counts` could not read an optional XAMPP performance-schema
table. Read-only queries through the application's database connection verified
the tables and counts instead. The application database connection works.

Faculty can now manage their own research expertise. Admin can select a faculty
member and manage the same data, as required by the Admin expertise module.
Both interfaces share validation, services, and forms.

Faculty must save their basic profile first. Visiting the expertise page does not
create blank profiles. Admin sees a profile-completion notice for accounts without
a Faculty Profile. No accounts, profiles, or initial proficiency ratings are seeded.

## Fields and behavior

The new `faculty_expertise` table stores `id`, `faculty_profile_id`,
`expertise_area`, `proficiency_score`, and timestamps. One faculty member may have
multiple rows. Each area may appear once per faculty profile, enforced by a unique
database constraint as well as validation. Concurrent duplicate submissions produce
a validation message. Scores are validated as whole numbers from 0 to 100.

The 14 canonical area names are defined in `config/expertise.php` and used by both
the select field and backend validation. Unrecognized names and aliases are rejected
by the form; later proposal-analysis work can map aliases to this same vocabulary.
Faculty may list more than three areas; the future three-area limit applies to
proposal analysis, not faculty expertise.

Faculty ownership is derived from the authenticated account. Posted owner IDs are
ignored. Faculty cannot read or change another faculty member's entry. Admin URLs
also verify that each expertise entry belongs to the selected Faculty Profile.
Student access is forbidden. Guests are redirected to login. All mutation forms
use CSRF protection. Removal requires expanding a confirmation and submitting it.

These entries will later support Research Topic Alignment. Step 6 does not calculate
recommendations, implement preferences or competency evaluation, change advisory
limits, or assign advisers. All functionality uses local Laravel/Blade assets.

## Complete source code

All paths are relative to `C:\xampp\htdocs\skillsync`; the links open complete files.

| File | Responsibility |
| --- | --- |
| [Expertise config](../config/expertise.php) | Canonical expertise areas |
| [Migration](../database/migrations/2026_09_12_000000_create_faculty_expertise_table.php) | New table, foreign key, unique area per faculty |
| [FacultyExpertise](../app/Models/FacultyExpertise.php) | Expertise fields, score cast, profile relationship |
| [FacultyProfile](../app/Models/FacultyProfile.php) | Adds the expertise relationship |
| [Request](../app/Http/Requests/FacultyExpertiseRequest.php) | Ownership checks for all actions and write validation |
| [Service](../app/Services/FacultyExpertiseService.php) | Save/delete operations, duplicate handling |
| [Controller](../app/Http/Controllers/FacultyExpertiseController.php) | Faculty and Admin pages, delegates writes |
| [Routes](../routes/web.php) | Faculty/Admin role-protected endpoints and model bindings |
| [Expertise index](../resources/views/faculty/expertise/index.blade.php) | List, add, and removal confirmation |
| [Edit page](../resources/views/faculty/expertise/edit.blade.php) | Edit one entry |
| [Shared form](../resources/views/faculty/expertise/form.blade.php) | Controlled select, score input, validation errors |
| [Admin faculty list](../resources/views/admin/expertise/index.blade.php) | Paginated faculty selection and incomplete-profile notice |
| [Faculty Profile page](../resources/views/faculty/profile/edit.blade.php) | Completion notice and expertise link |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Role-appropriate expertise links |
| [Dashboard](../resources/views/dashboard.blade.php) | Faculty and Admin shortcuts |
| [Tests](../tests/Feature/FacultyExpertiseTest.php) | CRUD, validation, duplicate handling, access and ownership checks |

## Exact commands

Run from the project directory. The migration creates only the new expertise table;
do not run `migrate:fresh`, `migrate:reset`, or `db:wipe` against the existing database.

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=FacultyExpertiseTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=expertise
```

The automated tests use in-memory SQLite through `phpunit.xml`, not the local
MySQL data. Formatting is checked with:

```powershell
php vendor/bin/pint --test app/Models/FacultyExpertise.php app/Models/FacultyProfile.php app/Http/Requests/FacultyExpertiseRequest.php app/Http/Controllers/FacultyExpertiseController.php app/Services/FacultyExpertiseService.php config/expertise.php routes/web.php database/migrations/2026_09_12_000000_create_faculty_expertise_table.php tests/Feature/FacultyExpertiseTest.php
```

## Manual testing

Completed verification: **123 tests passed (853 assertions)**, including 20 expertise
tests. The production asset build and Pint formatting check passed. The migration
was applied successfully to `skillsync`; all seven migrations are now applied.
After migration, counts remained 3 accounts, 1 Student Profile, and 1 Faculty Profile,
with 0 expertise entries. No test data was added to MySQL.

1. Open `http://127.0.0.1:8000/login`. Sign in using an Admin-created Faculty account.
2. Save **Faculty Profile** with full name and department if not already completed.
3. Open **Research Expertise** from navigation or the Faculty Dashboard.
4. Add **Web Development** with score **90**, then **Database Systems** with **80**.
5. Confirm both entries appear. Refresh and verify they remain saved.
6. Edit Web Development to **95**, then confirm the updated score.
7. Try adding Web Development again. Expect a duplicate-area validation message.
8. Try scores **-1**, **101**, or **70.5**. The browser or server must reject them.
9. Expand **Remove Database Systems**, then **Confirm removal**. Only that entry is removed.
10. Sign in as `systemadmin@gmail.com` using the existing password. Open
    **Faculty Expertise**, select the faculty member, and verify the same saved data.
    Admin can add, edit, and remove entries there.
11. Sign in as a Student and visit `/faculty/expertise` or `/admin/expertise`.
    Expect HTTP 403. Log out and visit either URL; expect a redirect to login.

Use test expertise values only on an account intended for demonstration, or restore
the faculty member's correct values afterward. Step 7 (Faculty Preferences) remains
outside this implementation.
