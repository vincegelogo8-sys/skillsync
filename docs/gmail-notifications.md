# Gmail adviser request notifications

SKILLSYNC uses Laravel mail notifications and the existing `users.email` field for both login and notifications. User passwords remain SKILLSYNC passwords. Only the dedicated sender needs a Google App Password.

New-request emails include the requesting Student's name and email and set Reply-To to that Student's `users.email`. The From address remains the dedicated system Gmail for every Student. Faculty can click Reply to contact the requesting Student. Logging in as a different Student does not switch the SMTP account or the Gmail account open in another browser tab. To switch SKILLSYNC Students, log out of SKILLSYNC and log in using the other Student's SKILLSYNC credentials.

## Existing workflow

- A Student creates a `pending` request in `AdviserRequestService::submit`; the requested `facultyProfile.user` receives `AdviserRequestReceivedNotification`.
- The requested Faculty adviser uses **Accept Request** in `AdviserAssignmentService::approve`; the request becomes `approved` and its `studentProfile.user` receives `AdviserRequestAcceptedNotification`. Assignment creation and cancellation of competing pending requests remain in the same transaction.
- The requested Faculty member declines through `AdviserRequestService::close`; the request becomes `declined` and its `studentProfile.user` receives `AdviserRequestDeclinedNotification`.
- Student cancellations and automatically cancelled competing requests send no email.

Notifications run after the outermost database transaction commits. Rollbacks discard callbacks. Delivery failures are caught and logged with request ID, notification class, and exception type only. They cannot reverse committed requests, statuses, or assignments. A failed attempt is not retried automatically; replaying the action does not resend mail. No queue worker is required. SMTP attempts use a configurable five-second socket timeout; a failed delivery can briefly delay the response.

## Local configuration

Set these values in your local `.env` only. Replace the two sender placeholders with the same dedicated Gmail address and the password placeholder with that sender's Google App Password:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=SKILLSYNC_SYSTEM_GMAIL
MAIL_PASSWORD=GOOGLE_APP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_TIMEOUT=5
MAIL_FROM_ADDRESS=SKILLSYNC_SYSTEM_GMAIL
MAIL_FROM_NAME="SKILLSYNC"
```

Set `APP_URL` to your actual SKILLSYNC base URL, reachable from the device opening the email. Leave `MAIL_URL` unset so it does not override these settings. Laravel 12 uses Symfony SMTP with STARTTLS; `MAIL_ENCRYPTION=tls` requires TLS in this application's mail configuration.

Google documents [SMTP port 587 with TLS](https://support.google.com/mail/answer/7104828?hl=en) and [App Password setup](https://support.google.com/accounts/answer/185833?hl=en). Enable 2-Step Verification for the dedicated sender before creating its App Password. Never enter a Student or Faculty Gmail password into SKILLSYNC.

From the project directory:

```powershell
php artisan optimize:clear
php artisan test --filter='AdviserRequestNotificationTest|AdviserRequestTest|AdviserAssignmentTest|Recommendation|ProposalExtractionTest|AdminAccountTest|ProfileTest|RoleAuthorizationTest|EndToEndWorkflowTest'
```

Automated tests use fake notifications or the existing array mail transport. They do not send Gmail messages.

Validation on 2026-09-19: all 13 new notification tests passed. The full `php artisan test` run had 408 passed and 3 failures in unchanged `ProposalExtractionTest` cases, also reproduced independently: PDF/DOCX whitespace expectations at line 63 and the unsafe-document rejection expectation at line 168. Extraction services and tests were left unchanged as required by this feature's scope. All adviser request, assignment, recommendation, account, authorization, and end-to-end workflow tests passed.

## One manual real-email workflow

1. Configure the dedicated sender locally and run `php artisan optimize:clear`.
2. Use actual Gmail addresses for the Student and requested Faculty accounts. Admin currently has List/Create Account actions, but no Edit Account feature. Existing users can sign in with their old email, update Email at `/profile`, then use the new Gmail address for login. Existing accounts are not automatically changed or deleted.
3. Log in as the Student. Open an analyzed proposal, generate recommendations if necessary, and request an eligible Faculty member with available capacity. Use a proposal with no active assignment and no active request for that Faculty member.
4. Check the Faculty Gmail inbox (and Spam) for **New Research Adviser Request - SKILLSYNC**. Its button opens the existing Faculty requests page after login.
5. Log in as the requested Faculty adviser and choose **Accept Request** or **Decline Request**. Admin can view requests and assignments but cannot accept or decline.
6. Check the Student Gmail inbox for **Adviser Request Accepted - SKILLSYNC** or **Adviser Request Update - SKILLSYNC**, respectively.
7. If no email arrives, verify the workflow status was saved, inspect the sanitized warning in the Laravel log, and check the sender configuration. Refreshing the page will not resend the message.

Recommendation algorithms, weights, ranking, extraction, analysis, and advisory threshold calculations are unchanged. Gmail validation applies only to Student/Faculty account creation and profile updates; login does not require internet or revalidate existing accounts against Gmail.
