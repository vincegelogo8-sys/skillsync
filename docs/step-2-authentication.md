# Step 2: Blade authentication

The existing Laravel 12 project now uses Laravel Breeze 2.4.2 with Blade and
Tailwind 3 (Breeze's generated version). Registration, login, logout, password
confirmation, password reset, and basic account settings are installed.
Student/Faculty/Admin roles and their dashboards belong to Step 3 and later.

## Existing setup preserved

- MySQL connection to the existing `skillsync` database and its credentials.
- The three existing migrations; no migration or seed was run in Step 2.
- The existing User model, including its password hash cast.
- The existing SQLite file.

## Commands used

Run commands from `C:\xampp\htdocs\skillsync` in PowerShell.

```powershell
composer require laravel/breeze --dev --no-interaction
php artisan breeze:install blade --no-interaction
npm.cmd install --no-fund
npm.cmd run build
php artisan config:clear
php artisan route:list --except-vendor
php artisan migrate:status
php artisan view:cache
php vendor/bin/pint --test app routes tests
php artisan test
```

The scaffold's automatic npm phase was interrupted when network access stalled.
After removing Alpine.js and the unused Tailwind 4 Vite plugin, npm installation
and the build were completed separately. Do not rerun `breeze:install` on the
finished files: it replaces customized scaffold files.

## Source code

The complete implementation is in these project files, rather than pseudocode:

| Files (relative to project root) | Purpose |
| --- | --- |
| `routes/web.php`, `routes/auth.php` | Guest/authenticated routes and account settings |
| `app/Http/Controllers/Auth/*.php` | Registration, sessions, passwords, and verification |
| `app/Http/Requests/Auth/LoginRequest.php` | Login validation and throttling |
| `app/Http/Controllers/ProfileController.php` | Basic account settings |
| `app/Http/Requests/ProfileUpdateRequest.php` | Account validation |
| `app/View/Components/*.php` | Blade layout components |
| `resources/views/auth/*.blade.php` | Authentication forms |
| `resources/views/layouts/*.blade.php` | Local layouts and responsive navigation |
| `resources/views/components/*.blade.php` | Reusable Blade controls, native menu and dialog |
| `resources/views/profile/**/*.blade.php` | Account details/password/deletion forms |
| `resources/views/dashboard.blade.php` | Protected starter dashboard |
| `resources/js/app.js` | Minimal vanilla JavaScript for native dialogs |
| `resources/css/app.css`, `tailwind.config.js`, `postcss.config.js`, `vite.config.js` | Local asset build |
| `composer.json`, `composer.lock`, `package.json`, `package-lock.json` | Installed dependencies |
| `tests/Feature/Auth/*.php`, `tests/Feature/ProfileTest.php` | Authentication/account tests |
| `tests/TestCase.php`, `.gitignore` | Separate test Blade cache to avoid Windows file locks |

`.env` sets `APP_NAME=SKILLSYNC` and `APP_URL=http://127.0.0.1:8000`.
Its existing database credentials and local log mailer are preserved.

## Local behavior

CSS and JavaScript are compiled into `public/build`; no Vite server or internet
connection is needed after building. Authentication pages do not load remote
fonts. Native HTML details provides the account menu, and a native dialog handles
account deletion confirmation. Passwords use `Hash::make()`. Logout uses a CSRF
protected POST form. Login attempts are throttled after five failures.

Email verification is not required for local dashboard access. Password-reset
messages use `MAIL_MAILER=log`, so no email is sent: for local development, open
`storage/logs/laravel.log` privately and use the generated recovery link. Do not
share logs or reset tokens. Email delivery is not configured in this step.

## Browser verification

1. Keep XAMPP MySQL running. If needed, start Laravel with
   `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`.
2. Open `http://127.0.0.1:8000/register` and register with a unique email and
   matching passwords of at least eight characters. Expect the dashboard.
3. Open the account menu beside your name and select Log Out.
4. Open `/dashboard` while logged out; expect a redirect to `/login`.
5. Try an incorrect password, then log in with the correct password.
6. Open Profile, update your name, and verify the saved message. Open the delete
   dialog and cancel it to check interaction without deleting your account.
7. Try the account menu at a narrow browser width.
8. Disconnect internet while keeping MySQL and Laravel running; repeat login and
   logout. Browser interaction and disconnected operation still need user review.

## Automated verification results

- 29 tests passed, 91 assertions, using temporary in-memory SQLite test storage.
- npm production build and Pint checks passed.
- Login, registration, recovery, and welcome pages returned HTTP 200.
- Guest dashboard/profile requests redirected to login.
- Compiled CSS and JavaScript returned HTTP 200; no remote page asset tags found.
- A live login POST without a CSRF token returned HTTP 419.
- MySQL remained at zero users and three recorded migrations after testing.

Step 2 stops here. Wait for confirmation before implementing Step 3 roles.
