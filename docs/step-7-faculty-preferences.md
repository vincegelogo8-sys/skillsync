# Step 7: Faculty Preferences

## Inspection and scope

Steps 1–6 were already implemented, including Faculty Profile and Faculty Expertise.
Seven migrations were applied. The existing `skillsync` database contained 3 users,
1 Student Profile, 1 Faculty Profile, and 1 expertise entry. No preference table,
model, controller, service, config, or view existed in the application.

This step adds a Faculty Preferences page at `/faculty/preferences`. Faculty select
multiple preferred project types and technologies, then save both lists together.
Unchecking options removes those preferences on save. Empty lists are allowed;
saving without selections clears that faculty member's preferences.

The only stored preference types are `project_type` and `technology`. Preferences
remain separate from expertise, proficiency, competency, and advisory limits.
Compatibility scoring belongs to Step 16; this step stores preferences only.

## Storage and access

`faculty_preferences` contains `id`, `faculty_profile_id`, `preference_type`,
`preference_value`, and timestamps. Each selection is one row. A unique constraint
prevents duplicate type/value combinations per faculty. The foreign key links to
the existing Faculty Profile, with cascade deletion when that profile is deleted.

Only authenticated Faculty can read or save their own preferences. Ownership comes
from the logged-in account, never submitted IDs. Student and Admin access to these
self-service routes returns 403; guests are redirected to login. A faculty member
must complete their basic profile first. Opening the page creates no blank records.

The 18 project types from the original plan are defined in `config/preferences.php`.
The same config contains an initial list of 35 canonical technologies, including
Laravel, PHP, MySQL, Python, OpenCV, C++, C#, .NET, and Node.js. Update this central
catalog to support additional technologies. Future proposal analysis should reuse
these canonical names and map aliases to them. Technology names shown as preference
choices do not add those frameworks or services as application dependencies.

Validation rejects unknown values, duplicate selections, malformed lists, and values
submitted under the wrong category. Failed validation preserves saved records and
redisplays the submitted selections, including an unchecked group. Both lists save
in a database transaction with a lock on the faculty profile. A failed write rolls
back the entire change. Repeated identical saves preserve existing row IDs.

The page uses Blade, locally built Tailwind CSS, and native checkboxes. No new
JavaScript or internet-dependent services are required.

## Complete source code

Paths are relative to `C:\xampp\htdocs\skillsync`. Each link opens the full file.

| File | Responsibility |
| --- | --- |
| [Preference config](../config/preferences.php) | Canonical project types and technologies |
| [Migration](../database/migrations/2026_09_12_010000_create_faculty_preferences_table.php) | Preference rows, allowed types, foreign key, unique constraint |
| [FacultyPreference](../app/Models/FacultyPreference.php) | Editable preference fields and profile relationship |
| [FacultyProfile](../app/Models/FacultyProfile.php) | Adds the preferences relationship |
| [Request](../app/Http/Requests/FacultyPreferenceRequest.php) | Faculty authorization and selection validation |
| [Service](../app/Services/FacultyPreferenceService.php) | Transactional synchronization of both lists |
| [Controller](../app/Http/Controllers/FacultyPreferenceController.php) | Loads selections, requires profile, delegates saving |
| [Routes](../routes/web.php) | GET/PATCH routes under authentication and Faculty role middleware |
| [Preferences page](../resources/views/faculty/preferences/index.blade.php) | Responsive checkbox lists, validation and save feedback |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Faculty Preferences navigation link |
| [Dashboard](../resources/views/dashboard.blade.php) | Faculty dashboard shortcut |
| [Faculty Profile page](../resources/views/faculty/profile/edit.blade.php) | Preferences link and profile completion notice |
| [Preference tests](../tests/Feature/FacultyPreferenceTest.php) | Persistence, clearing, validation, ownership, rollback and isolation |

## Exact commands

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=FacultyPreferenceTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=preferences
```

The migration adds only `faculty_preferences`. Do not use `migrate:fresh`,
`migrate:reset`, or `db:wipe` on the existing database. Automated tests use in-memory
SQLite configured in `phpunit.xml`, not the MySQL application records.

Formatting check:

```powershell
php vendor/bin/pint --test app/Models/FacultyPreference.php app/Models/FacultyProfile.php app/Http/Requests/FacultyPreferenceRequest.php app/Http/Controllers/FacultyPreferenceController.php app/Services/FacultyPreferenceService.php config/preferences.php routes/web.php database/migrations/2026_09_12_010000_create_faculty_preferences_table.php tests/Feature/FacultyPreferenceTest.php
```

## Manual testing

Completed checks: **153 tests passed (1,076 assertions)**, including 30 preference
tests. The production asset build and Pint formatting check passed. The migration
was applied successfully; all eight migrations are now applied. MySQL still has
3 accounts, 1 Student Profile, 1 Faculty Profile, and 1 expertise entry. The new
preferences table is empty; no demonstration data was added to the application.

1. Log in at `http://127.0.0.1:8000/login` using an Admin-created Faculty account.
2. Complete **Faculty Profile** first if the account has no saved profile.
3. Open **Preferences** in the navigation or **Manage Faculty Preferences** on the dashboard.
4. Select **Web-Based System** and **Recommendation System** under project types.
5. Select **Laravel**, **PHP**, and **MySQL** under technologies, then click **Save Preferences**.
6. Confirm the success message. Reload and verify those choices remain checked.
7. Uncheck PHP, select Python, and save. Reload to verify the new selections.
8. Uncheck every project type and save. Technologies should remain selected.
9. Uncheck all remaining technologies and save. Reload to confirm both groups are empty.
10. Log in as another Faculty account. It must have its own independent selections.
11. Log in as a Student or Admin and visit `/faculty/preferences`; expect HTTP 403.
    Log out and visit that URL; expect a login redirect.
12. Confirm existing expertise and proficiency scores remain unchanged.

Use demonstration selections only on an appropriate test account, or restore the
faculty member's real preferences afterward. Step 8, Admin Research Advising
Competency evaluation, is not part of this implementation.
