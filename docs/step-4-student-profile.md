# Step 4: Student Profile

Accounts are now created by Admin. Ask your Admin for a Student account and use
the email/password they provide. See [account creation instructions](admin-account-creation.md).

## What existed

The project already had Breeze account settings and three protected role route
groups. The existing MySQL `skillsync` database contained one Student account,
four completed migrations, and no `student_profiles` table. No conflicting
Student Profile implementation was found.

## What changed

Students can open `/student/profile` from the navigation or Student Dashboard,
enter their academic information, save it, and return later to update it. A
profile is created only on the first successful save. Merely opening the page
does not write a profile or invent student information.

| Field | Validation |
| --- | --- |
| Student Number | Required text, maximum 50 characters, unique across profiles |
| Course | Required text, maximum 150 characters |
| Year Level | Required integer, 1 through 6 |
| Section | Required text, maximum 50 characters |

Year levels 1–6 are the current implementation choice. Course and section remain
free text because no institutional list was supplied. Student numbers stay text
so leading zeros and separators can be preserved.

The new table contains only `id`, `user_id`, `student_number`, `course`,
`year_level`, `section`, and timestamps. A unique `user_id` enforces one profile
per account. Its foreign key references `users`; using the existing account
deletion feature also deletes that account's profile.

Name, email, and password remain in Breeze's existing account settings. The menu
and account page now say **Account Settings** to distinguish them from academic
profile details. The Student Profile page links there.

## Complete source code and file paths

Paths are relative to `C:\xampp\htdocs\skillsync`. Each link opens the complete
implementation file.

| File | Responsibility |
| --- | --- |
| [Migration](../database/migrations/2026_09_11_150000_create_student_profiles_table.php) | Creates only the new Student Profile table and its constraints |
| [StudentProfile model](../app/Models/StudentProfile.php) | Academic fields, integer year cast, `belongsTo(User)` |
| [User model](../app/Models/User.php) | Adds `hasOne(StudentProfile)` |
| [StudentProfileRequest](../app/Http/Requests/StudentProfileRequest.php) | Student authorization and input validation |
| [StudentProfileService](../app/Services/StudentProfileService.php) | Creates or updates the authenticated student's profile |
| [StudentProfileController](../app/Http/Controllers/StudentProfileController.php) | Renders the page and delegates saving to the service |
| [Web routes](../routes/web.php) | GET and PATCH `/student/profile` with `auth` and `role:student` |
| [Student Profile Blade view](../resources/views/student/profile/edit.blade.php) | Local form, errors, saved values, and success message |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Student-only profile link and Account Settings label |
| [Dashboard](../resources/views/dashboard.blade.php) | Student-only link to complete/update the profile |
| [Account settings view](../resources/views/profile/edit.blade.php) | Clarifies the existing page title |
| [StudentProfileTest](../tests/Feature/StudentProfileTest.php) | Creation, updates, validation, ownership, roles, escaping, and deletion behavior |

The ResearchProposals relationship will be added when its model is implemented
in the proposal step; this step does not introduce a reference to a missing model.

## Authorization and data safety

Both Student Profile routes enforce authentication and the Student role on the
backend. Faculty/Admin receive 403; guests redirect to login. There is no editable
owner ID and no profile-ID URL. Ownership comes from the authenticated user, and
only validated academic fields reach the service. Forged `user_id`, profile ID,
name, or role fields cannot change another account or grant privileges.

The migration was previewed and then applied to the existing MySQL database.
Afterward, the existing user count remained one, the profile count was zero, and
five migrations were recorded. No sample academic data or staff accounts were
inserted. No database reset, existing data deletion, or credential change ran.

## Exact commands used

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php vendor/bin/pint --test app routes tests database/migrations
php artisan test
npm.cmd run build
php artisan migrate --no-interaction
php artisan migrate:status
php artisan route:list --path=student -vv
```

The migration is already applied. No need to rerun setup or install dependencies.

## Browser test instructions

1. Keep MySQL running. If necessary, start Laravel:
   `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`.
2. Log in with the Student email/password you registered earlier.
3. Open `http://127.0.0.1:8000/student/profile`, or select **Student Profile** in
   the navigation. An account without saved academic details shows a blank form.
4. Enter your student number, course, year level, and section; select
   **Save Student Profile**. Expect **Student profile saved successfully.**
5. Refresh the page. The saved values should still appear.
6. Change the section or year and save again. The same profile is updated.
7. Leave a required field empty to check browser validation. Server validation
   also rejects missing fields, out-of-range years, and duplicate student numbers.
8. Use **Account Settings** to change name/email/password; those fields remain
   separate from academic details.
9. Log out and visit `/student/profile`; expect the login page. If you have
   assigned a Faculty or Admin test account as documented in Step 3, logging in
   with it and visiting this URL must return 403.

## Verification results

- Full suite: **63 tests passed, 273 assertions**, using temporary in-memory test
  storage, not the existing MySQL database.
- Formatting checks and the local production asset build passed.
- Tests cover first save, persisted values, updates without duplicate profiles,
  duplicate student numbers, required fields, limits, and year validation.
- Ownership/role forgery and unauthorized GET/PATCH access were tested.
- Rendered profile text is escaped, and account deletion removes its profile.
- Local guest access redirected to login; compiled CSS and JavaScript returned
  HTTP 200.

All processing and assets are local. No new JavaScript or internet API was added.
Visual browser review and entering real academic details are left to the user.

Step 4 ends here. Step 5 (Faculty Profile) waits for user confirmation.
