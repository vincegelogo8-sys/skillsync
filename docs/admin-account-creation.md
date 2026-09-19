# Admin-created Student and Faculty accounts

Public registration is disabled. Only a signed-in Admin can create Student or
Faculty accounts. This replaces the earlier public role-selection flow.

## Using the feature

1. Sign in with an Admin account at `http://127.0.0.1:8000/login`.
2. Open **Accounts** in the navigation or from the Admin Dashboard.
3. Click **Create Account**.
4. Enter the person's full name and unique email address, choose **Student** or
   **Faculty**, and enter and confirm an initial password of at least 8 characters.
5. Click **Create Account**. You remain signed in as Admin and return to the
   account list with a success message.
6. Give the person the email and initial password you entered. The system does
   not email credentials, store a plaintext password, or display it afterward.
7. The person signs in using those credentials and reaches their role dashboard.
   They can change their password in **Account Settings** and complete their
   Student or Faculty Profile. There is no forced password-change workflow.

The list shows existing Student and Faculty accounts as well as new accounts.
Existing records were preserved. It displays names, emails, roles, and creation
dates in pages of 20; it never displays password hashes or plaintext passwords.

## Initial Admin setup

The owner-selected existing account was promoted to Admin on September 12, 2026.
Its password and saved profile data were verified unchanged. The initial Admin
can sign in with the same email and password used before promotion.

After the initial Admin is set up, all normal account creation takes place in
the Admin interface. The web form cannot create additional Admin accounts.

## Access and validation

- GET and POST `/register` are removed, and public pages contain no Register link.
- `/admin/accounts`, `/admin/accounts/create`, and POST `/admin/accounts` require
  both `auth` and `role:admin` middleware.
- The request also checks the Admin role. Guests redirect to login; Student and
  Faculty attempts receive 403.
- Only Student and Faculty role values pass account-creation validation; forged
  Admin roles, IDs, and email-verification fields cannot be assigned.
- Names, emails, password confirmation, minimum password length, and unique email
  addresses are validated. Invalid input creates no account.
- Passwords use `Hash::make()` and are excluded from validation-flashed input.
- Creation does not change the Admin's session and does not send email.
- Existing account/profile endpoints still cannot change roles.
- Academic profiles are created on their first valid profile save, not with
  fabricated academic information during account creation.

No migration or external dependency was required. The existing `users` table and
role column remain the account source of truth. No new JavaScript was added.

## Complete implementation files

All paths are relative to `C:\xampp\htdocs\skillsync`.

| File | Responsibility |
| --- | --- |
| [Admin AccountController](../app/Http/Controllers/Admin/AccountController.php) | List, form, and successful creation redirect |
| [CreateAccountRequest](../app/Http/Requests/Admin/CreateAccountRequest.php) | Admin authorization and input validation |
| [AccountProvisioningService](../app/Services/AccountProvisioningService.php) | Password hashing and explicit account creation |
| [User model](../app/Models/User.php) | Role remains excluded from ordinary mass assignment |
| [Web routes](../routes/web.php) | Protected Admin account endpoints |
| [Auth routes](../routes/auth.php) | Public registration removed |
| [Account list](../resources/views/admin/accounts/index.blade.php) | Paginated Student/Faculty account table |
| [Creation form](../resources/views/admin/accounts/create.blade.php) | Student/Faculty role selection and initial password |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Admin-only Accounts link |
| [Dashboard](../resources/views/dashboard.blade.php) | Admin account-management link |
| [Login page](../resources/views/auth/login.blade.php) | Explains that credentials come from Admin |
| [Welcome page](../resources/views/welcome.blade.php) | Public Register link removed |
| [Admin account tests](../tests/Feature/AdminAccountTest.php) | Permissions, creation, credential login, validation, pagination, and password handling |
| [Registration tests](../tests/Feature/Auth/RegistrationTest.php) | Disabled public registration for every role |
| [Role tests](../tests/Feature/RoleAuthorizationTest.php) | Login destinations and unchanged role restrictions |

The unused public registration controller and Blade form were removed.

## Commands and verification

```powershell
Set-Location C:\xampp\htdocs\skillsync
php vendor/bin/pint --test app routes tests
php artisan test
php artisan route:list --path=admin -vv
npm.cmd run build
```

Full suite: **103 tests passed, 597 assertions**. Formatting and asset-build checks
passed. Tests use temporary in-memory database storage, not the existing MySQL
database. Public login renders with Admin-account instructions; guest requests
to Admin pages redirect to login. No later capstone step was started.
