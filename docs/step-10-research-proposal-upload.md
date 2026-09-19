# Step 10: Research Proposal Upload

Historical Step 10 behavior is documented below. [Step 11](step-11-document-extraction.md)
now adds automatic text extraction after new uploads and an extraction action for existing proposals.

## Inspection and scope

Steps 1–9 were implemented, with ten applied migrations. No proposal model, table,
controller, service, request, rule, or views existed. The database had 3 users,
1 Student Profile, 1 Faculty Profile, 2 expertise entries, 2 preferences, and no
assessment attempts. PHP's upload and POST limits were both 40 MB, sufficient for
the required 10 MB application limit. Fileinfo, ZIP, and DOM support were available.

Students can now upload a research title and a PDF or DOCX document, see their
proposal list, open a proposal's details, and download the original document.
Admin can review and download all student proposals through separate protected
routes. Faculty cannot access the upload or review modules in this step.

The student must first save their academic profile. Opening a page does not create
a blank profile or proposal. Every successful upload creates a separate proposal;
even identical original filenames do not overwrite previously uploaded documents.

## Storage, validation, and authorization

The `research_proposals` table contains `id`, `student_profile_id`, `title`,
`file_path`, `original_filename`, `file_type`, `status`, and timestamps. The initial
status is `uploaded`. Ownership is set from the logged-in student profile. The
generated file path is unique and hidden from model serialization and page output.

Files use a dedicated local `proposals` disk rooted at
`storage/app/private/proposals`. Generated UUID filenames preserve only the validated
extension. The original filename is stored as metadata with directory components
and control characters removed. There is no public storage link or direct serving
route for this disk.

Validation requires:

- A nonempty title of at most 255 characters.
- A successfully uploaded file no larger than 10 × 1024 × 1024 bytes (10 MB).
- A `.pdf` or `.docx` filename extension, case-insensitive, matching file contents.
- For PDF: server-detected `application/pdf` MIME and a `%PDF-` header.
- For DOCX: a Word document or ZIP MIME, required Word package members, and a
  Word document content-type declaration. Ordinary renamed ZIPs, missing document
  parts, macro-enabled main types, and DTD-bearing content-type XML are rejected.
- Original filenames of no more than 255 characters.

The DOCX format check reads only bounded package metadata (up to 64 KB), does not
extract the archive to disk, and disables network access when parsing that metadata.
It does not perform proposal text extraction. Readable-text validation, including
scanned PDF handling, belongs to Step 11.

All uploads use CSRF protection. Guests are redirected to login. Role middleware
restricts Student and Admin routes, and detail/download actions check ownership.
Another student's proposal returns 404. Admin uses Admin review/download routes.
Downloads are attachments with `nosniff` and private/no-store caching headers;
uploaded documents are not rendered inline by the application.

A storage or database failure produces a generic upload error, preserves no proposal
record, and attempts to remove any newly written file. Detailed failures are logged
server-side. Deleting a Student account through Account Settings removes its proposal
rows through database cascades and deletes its files after the account deletion.
If that filesystem cleanup fails, the error is logged for follow-up. Direct manual
SQL deletion does not invoke application file cleanup.

## Complete source code

Paths are relative to `C:\xampp\htdocs\skillsync`; links open complete files.

| File | Responsibility |
| --- | --- |
| [Migration](../database/migrations/2026_09_12_040000_create_research_proposals_table.php) | Proposal metadata, owner foreign key, unique stored path |
| [Filesystems config](../config/filesystems.php) | Dedicated private proposal disk |
| [ResearchProposal](../app/Models/ResearchProposal.php) | Owner relationship and hidden stored path |
| [StudentProfile](../app/Models/StudentProfile.php) | Research proposals relationship |
| [Document validation rule](../app/Rules/ProposalDocument.php) | Server MIME, extension, PDF header, DOCX package checks |
| [Upload request](../app/Http/Requests/ResearchProposalRequest.php) | Student authorization, title and 10 MB validation |
| [Upload service](../app/Services/ResearchProposalService.php) | Generated storage paths, metadata persistence, failure cleanup |
| [Proposal controller](../app/Http/Controllers/ResearchProposalController.php) | Lists, upload, detail, ownership checks and downloads |
| [Account controller](../app/Http/Controllers/ProfileController.php) | File cleanup after account deletion |
| [Routes](../routes/web.php) | Student upload and Admin review routes |
| [Proposal list](../resources/views/student/proposal/index.blade.php) | Paginated Student/Admin lists |
| [Upload form](../resources/views/student/proposal/create.blade.php) | Multipart form, errors and file requirements |
| [Proposal detail](../resources/views/student/proposal/show.blade.php) | Metadata and protected download link |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Student/Admin proposal links |
| [Dashboard](../resources/views/dashboard.blade.php) | Proposal shortcuts |
| [Student Profile page](../resources/views/student/profile/edit.blade.php) | Profile-completion notice |
| [Proposal tests](../tests/Feature/ResearchProposalTest.php) | Real-content upload validation, downloads, authorization and cleanup |

## Exact commands

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=ResearchProposalTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=proposals
php vendor/bin/pint --test app/Models/ResearchProposal.php app/Models/StudentProfile.php app/Rules/ProposalDocument.php app/Http/Requests/ResearchProposalRequest.php app/Services/ResearchProposalService.php app/Http/Controllers/ResearchProposalController.php app/Http/Controllers/ProfileController.php routes/web.php config/filesystems.php database/migrations/2026_09_12_040000_create_research_proposals_table.php tests/Feature/ResearchProposalTest.php
```

No new Composer or npm dependencies are required. Do not run `storage:link` for
proposal documents. Do not use `migrate:fresh`, `migrate:reset`, or `db:wipe` on the
existing database. Tests use in-memory SQLite and an isolated fake proposal disk.

## Manual testing

Completed verification: **229 tests passed (2,027 assertions)**, including 25 proposal
tests. The production asset build and Pint formatting check passed. The migration
was applied successfully; all eleven migrations are applied. Existing counts remain
3 users, 1 Student Profile, 1 Faculty Profile, 2 expertise entries, 2 preferences,
and 0 assessment attempts. The proposal table is empty; no test uploads were stored
in the application database or production proposal disk.

1. Log in at `http://127.0.0.1:8000/login` using an Admin-created Student account.
2. Save **Student Profile** first if the academic information is incomplete.
3. Open **Research Proposals**, then **Upload Proposal**.
4. Enter a title, choose a real PDF under 10 MB, and click **Upload Proposal**.
5. Confirm the success message, original filename, file type, upload date, and
   **Uploaded** status. Click **Download Document** and verify the original file.
6. Upload a real DOCX document. Verify both proposals appear in the list.
7. Try a `.doc`, a text file renamed `.pdf`, a ZIP renamed `.docx`, or a file larger
   than 10 MB. Expect validation errors and no new proposal. Choose the file again
   when correcting a failed form, as browsers do not restore file-input selections.
8. Upload two documents with the same filename. Confirm both records and their
   respective downloads remain available.
9. Sign in as another Student and open the first student's detail/download URL.
   Expect 404. That student's list must not include the first student's proposal.
10. Sign in as `systemadmin@gmail.com`, open **Research Proposals**, and verify Admin
    can review and download the submitted documents.
11. As Faculty, visit `/student/proposals` or `/admin/proposals`; expect 403. As a
    guest, visit either URL; expect a login redirect.

Use disposable accounts when testing account deletion. Automated tests already
verify that deleting an account removes only its own proposal files and records.
Document extraction and proposal analysis have not been implemented in Step 10.
