# Step 3: Student, Faculty, and Admin roles

> This is the historical Step 3 record. Account creation is now restricted to
> Admin users. Use the [current account creation instructions](admin-account-creation.md);
> the public registration instructions below describe earlier behavior.

## Inspection and result

Before this step, Breeze authentication was working, `users` contained one
account, and there was no role column, role middleware, or role-specific route.
All three existing migrations had run. No conflicting tables were found.

An additive migration now creates `users.role` as a non-null enum containing only
`student`, `faculty`, and `admin`, with `student` as the default. The existing
account is preserved and now has the student role. No database, account, or
profile table was created. No existing user was promoted.

## Complete implementation files

All paths below are relative to `C:\xampp\htdocs\skillsync`. Links open the full
source code; these are the actual implementation files.

| File | Change |
| --- | --- |
| [Role migration](../database/migrations/2026_09_11_140000_add_role_to_users_table.php) | Adds the three-value role column without replacing the users table |
| [User model](../app/Models/User.php) | Defines the three canonical role constants and the student default; excludes role from mass assignment |
| [RoleMiddleware](../app/Http/Middleware/RoleMiddleware.php) | Returns 403 when the authenticated user's role does not match the route |
| [Application bootstrap](../bootstrap/app.php) | Registers the `role` middleware alias |
| [DashboardController](../app/Http/Controllers/DashboardController.php) | Redirects `/dashboard` to the authenticated user's role dashboard; denies unknown roles |
| [Web routes](../routes/web.php) | Adds separate `auth` + `role:student`, `role:faculty`, and `role:admin` groups |
| [Dashboard view](../resources/views/dashboard.blade.php) | Reuses the existing starter view with a Student, Faculty, or Admin heading |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Keeps the Dashboard link active on each role dashboard |
| [Role tests](../tests/Feature/RoleAuthorizationTest.php) | Verifies the access matrix and public-form privilege protection |
| [Authentication tests](../tests/Feature/Auth/AuthenticationTest.php) | Verifies the new dashboard redirect and continued local access without email verification |

The existing registration controller creates only name, email, and hashed
password. The User model supplies `student`, so a forged `role=admin` or
`role=faculty` form field has no effect. Profile updates also cannot change roles.

## Access rules

| URL | Student | Faculty | Admin | Logged out |
| --- | --- | --- | --- | --- |
| `/student/dashboard` | Allowed | 403 | 403 | Login redirect |
| `/faculty/dashboard` | 403 | Allowed | 403 | Login redirect |
| `/admin/dashboard` | 403 | 403 | Allowed | Login redirect |
| `/dashboard` | Student redirect | Faculty redirect | Admin redirect | Login redirect |

Admin does not bypass another role's route group. Basic account settings at
`/profile` remain available to all authenticated users. No role selector or
public privilege-management endpoint is exposed. No internet service is used.

Role dashboards share the existing small Blade view for now. Student/Faculty
profiles and module-specific dashboards will be built in their scheduled steps.

## Commands run

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php vendor/bin/pint --test app bootstrap/app.php routes tests database/migrations
php artisan test
php artisan route:list --path=dashboard -vv
php artisan migrate --no-interaction
php artisan migrate:status
```

The SQL preview showed only:

```sql
ALTER TABLE users ADD role ENUM('student', 'faculty', 'admin') NOT NULL DEFAULT 'student';
```

The migration has already run. Do not run a database reset. No frontend rebuild
was needed because this step adds no CSS utilities or JavaScript.

## Browser testing

1. Open `http://127.0.0.1:8000/dashboard` while logged in with the existing account.
   Expect `/student/dashboard` and the heading **Student Dashboard**.
2. Visit `/faculty/dashboard` and `/admin/dashboard`. Both should return 403.
3. Log out, then visit any dashboard URL. Expect the login page.
4. Register another account if desired. It must become a Student without offering
   a role selector.

Faculty and Admin accounts are not automatically created or granted access. For
local testing, register a separate account you control, then intentionally assign
its role through the trusted local console. Replace the example email below with
that account's email. This changes only the selected account's role.

```powershell
php artisan tinker
```

For a Faculty account, enter:

```php
$user = App\Models\User::where('email', 'your-faculty-account@example.com')->sole();
$user->role = App\Models\User::ROLE_FACULTY;
$user->save();
exit
```

For a separate Admin account, enter in a new Tinker session:

```php
$user = App\Models\User::where('email', 'your-admin-account@example.com')->sole();
$user->role = App\Models\User::ROLE_ADMIN;
$user->save();
exit
```

Log in with each account and visit `/dashboard`. Confirm its own dashboard works
and the other two return 403. To return a selected test account to Student, use
the same exact-email lookup and assign `App\Models\User::ROLE_STUDENT`.

## Verification

- 43 tests passed with 149 assertions using temporary in-memory test storage.
- All nine authenticated role-to-dashboard combinations were checked.
- Registration attempts requesting Faculty/Admin still created Students.
- Profile updates could not alter any account's role.
- Unknown roles were denied; mass assignment could not grant Admin access.
- Existing authentication and account tests continued to pass.
- Pint checks passed.
- The local server redirected guest dashboard requests to login.
- After migration, MySQL still contained one user, now Student, and four recorded
  migrations. The role column's enum values and default were verified directly.

Step 3 ends here. Step 4 (Student Profile) waits for user confirmation.
