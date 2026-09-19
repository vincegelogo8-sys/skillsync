# Step 5: Faculty Profile

## Inspection and scope

Before this step, authentication, role restrictions, and Student Profile were
implemented. The existing `skillsync` MySQL database had two Student accounts,
one Student Profile, and five completed migrations. There was no Faculty Profile
table, model, service, controller, or page, and no conflicting faculty identity.

Faculty members can now open `/faculty/profile`, save their full name and
department, and update them later. The first valid save creates a profile;
opening the page does not create blank records. Only users with the `faculty`
role can access these self-service routes.

## Fields and storage

| Field | Storage | Rule |
| --- | --- | --- |
| Full Name | Existing `users.name` | Required text, maximum 255 characters |
| Department | `faculty_profiles.department` | Required text, maximum 150 characters |
| Advisory Limit | `faculty_profiles.advisory_limit` | Initial default 5; excluded from Faculty edits |

The new table has only `id`, `user_id`, `department`, `advisory_limit`, and
timestamps. A unique foreign key on `user_id` enforces one profile per account.
Faculty identity remains `users` plus `faculty_profiles`. The name is not copied
into the new table: changes made here or in Account Settings use the same name.

Name and department save in a database transaction. If saving the profile fails,
the database name change rolls back as well. Ownership comes from the authenticated
user, never from an input ID. A profile save preserves an existing advisory limit
and never automatically increases it.

Faculty cannot edit advisory limits through this form or by posting a forged
field. The Admin interface for threshold changes will be built in its scheduled
step. There is no stored advisee count. Expertise, preferences, competency,
assessments, assignments, and their relationships will be added in later steps
when their models exist.

## Complete source code

Paths are relative to `C:\xampp\htdocs\skillsync`. Each link opens a complete
implementation file.

| File | Responsibility |
| --- | --- |
| [Migration](../database/migrations/2026_09_11_160000_create_faculty_profiles_table.php) | Creates Faculty Profile storage and constraints |
| [FacultyProfile](../app/Models/FacultyProfile.php) | Department, guarded owner/limit, integer limit cast, `belongsTo(User)` |
| [User](../app/Models/User.php) | Adds `hasOne(FacultyProfile)` |
| [FacultyProfileRequest](../app/Http/Requests/FacultyProfileRequest.php) | Faculty authorization and input validation |
| [FacultyProfileService](../app/Services/FacultyProfileService.php) | Transactional account-name/profile save |
| [FacultyProfileController](../app/Http/Controllers/FacultyProfileController.php) | Renders the form and delegates saving |
| [Web routes](../routes/web.php) | GET/PATCH `/faculty/profile` under `auth` and `role:faculty` |
| [Faculty Profile Blade view](../resources/views/faculty/profile/edit.blade.php) | Two-field form, validation errors, saved values, and success message |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Faculty-only profile link |
| [Dashboard](../resources/views/dashboard.blade.php) | Faculty-only link to complete/update the profile |
| [FacultyProfileTest](../tests/Feature/FacultyProfileTest.php) | Persistence, ownership, validation, limits, transaction rollback, and access tests |

## Exact commands used

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php vendor/bin/pint --test app routes tests database/migrations
php artisan test
php artisan route:list --path=faculty -vv
php artisan migrate --no-interaction
php artisan migrate:status
npm.cmd run build
```

The migration is already applied. It created only the new table; it did not
reset the database, modify credentials, or change account roles. Both existing
users and the saved Student Profile remain. After verification there were zero
Faculty Profiles and six recorded migrations.

## Set up a Faculty login for manual testing

Only Admin can create Faculty accounts. See the
[current account creation instructions](admin-account-creation.md). Existing
Student accounts keep their role and still receive 403 at `/faculty/profile`.

1. Admin opens **Accounts > Create Account** while signed in.
2. Admin enters the faculty member's name, unique email, and initial password,
   then selects **Faculty** and clicks **Create Account**.
3. Admin gives the faculty member the email and initial password.
4. The faculty member logs in at `http://127.0.0.1:8000/login`, then opens
   **Faculty Profile** to save their department.

## Browser verification

1. Keep MySQL and Laravel running. If needed, start Laravel with:
   `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`.
2. Log in using the Faculty test account's email and password. `/dashboard`
   should lead to the Faculty Dashboard.
3. Select **Faculty Profile**, or open `http://127.0.0.1:8000/faculty/profile`.
4. Verify the name is prefilled from the account and the department is initially
   empty. Enter both fields and click **Save Faculty Profile**.
5. Expect **Faculty profile saved successfully.** Refresh and confirm the values
   remain. Edit the department and save again; the same profile is updated.
6. Change the full name and verify the account menu and Account Settings show it.
   Changing the name in Account Settings must also appear in Faculty Profile.
7. Leave a required field blank to check validation. The page has no editable
   advisory limit, competency, expertise, or preference fields.
8. Check the profile link and form at a narrow browser width.
9. Log out and visit `/faculty/profile`; expect the login page. With a Student or
   Admin login, the same URL must return 403.

## Verification results

- **81 tests passed, 385 assertions**, using temporary in-memory test storage.
- Full existing authentication, role, and Student Profile tests still pass.
- Faculty tests cover creation, repeated saves, relationships, and name syncing.
- Forged owner/profile IDs, role, email, password, and advisory limit are ignored.
- Profile updates preserve a previously changed advisory limit.
- Name and department validation covers required fields, lengths, and array input.
- Invalid input pages render safely; displayed text is HTML-escaped.
- A simulated profile write failure rolls back the account name in the database.
- Database uniqueness prevents a second Faculty Profile for the same account.
- Existing account deletion also deletes its Faculty Profile through the foreign key.
- Pint and the production asset build passed. The local server redirected guest
  Faculty Profile requests to login, and compiled CSS/JavaScript returned HTTP 200.

No new JavaScript or internet API was added. Real Faculty details and visual
browser review are left to the user.

Step 5 ends here. Step 6 (Faculty Expertise) waits for user confirmation.
