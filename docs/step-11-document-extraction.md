# Step 11: Local PDF and DOCX Text Extraction

## Inspection and scope

Step 10 already provided private uploads and protected downloads. Eleven migrations
were applied, with one existing uploaded proposal and no analysis table. The required
PDF and Word readers were not installed.

Installed dependencies are `smalot/pdfparser` 2.12.5, `phpoffice/phpword` 1.4.0, and
its dependency `phpoffice/math` 0.3.0. Composer locked these versions without updating
existing packages and reported no security advisories during installation. Internet
was used only to install dependencies. Document extraction runs locally.

## Behavior

New uploads automatically attempt text extraction after the original document has
been saved. Existing uploaded proposals display **Extract Document Text**. The
Student owner or Admin can use that action; other users cannot access the result
or trigger extraction.

Successful extraction creates one `proposal_analyses` row with `extracted_text`
and updates the proposal status to `extracted`. A unique foreign key enforces one
analysis per proposal. Reloading the page reads the stored text; it never reruns
the reader. Reposting an extraction request also reuses the saved result. A row
lock serializes requests for the same proposal.

This step creates only the analysis fields needed for extraction: `id`, unique
`research_proposal_id`, `extracted_text`, and timestamps. Later analysis steps can
add their own fields. This step does not identify keywords, technologies, project
types, or expertise areas and does not calculate recommendations.

Failed extraction preserves the original document, sets `extraction_failed`, and
stores a safe message in the proposal's new `extraction_error` column. No empty
analysis record is created. The detail page offers **Retry Text Extraction**, a
download link, and a Student link to upload a corrected document. Retries clear
the error when extraction succeeds. Failed pages do not retry merely because they
are viewed. A database failure rolls back text/status changes and displays a
generic retry message while retaining the uploaded document.

The saved text is escaped in a scrollable preview visible only to the owner or
Admin. The original document remains private and downloadable through the existing
protected route. Deleting the proposal through an account cascade also deletes
its analysis record.

## PDF and DOCX handling

PDF uses Smalot's parser with image-content retention disabled. It preserves readable
text and technical tokens such as C++, C#, .NET, and Node.js. For a PDF without
readable text, the exact message is:

> Unable to extract readable text from this PDF. The document may contain scanned pages. Please upload a text-based PDF or DOCX file.

OCR is not implemented. Encrypted or damaged PDFs receive a separate clear failure
message. Reading order in PDFs depends on the document's internal layout.

DOCX uses PHPWord's Word2007 reader with image loading disabled. It recursively reads
headings, paragraphs, styled text runs, lists, and tables. Adjacent fragments within
a paragraph remain together; paragraphs and table rows use line breaks, and table
cells use tabs. Unicode text is retained. Empty DOCX files receive an actionable
error. Legacy `.doc` files are not supported.

Untrusted parsers run in a separate local PHP process with a 30-second timeout and
a 256 MB memory limit. This needs the local PHP CLI and PHP process execution; it
does not need a queue worker, scheduled task, or network service. The existing PHP
executable is discovered for both CLI-server and XAMPP setups. Commands use separate
arguments rather than shell-built file paths. Parser diagnostics and internal paths
are not shown to the web user.

Additional bounds are a 10 MB input file, one million extracted characters, a PDF
stream decoding limit of 32 MB, and DOCX limits of 2,000 entries, 50 MB expanded
content, 10 MB per XML part, and 50 nested text containers. DOCX package XML is
checked without network access, DTD-bearing XML is rejected, and external content
relationships are rejected except for ordinary hyperlinks. Hyperlink display text
can be read without visiting its target. Archives are not unpacked into public storage.

## Complete source files

Paths are relative to `C:\xampp\htdocs\skillsync`. Links open complete source files.

| File | Responsibility |
| --- | --- |
| [Composer configuration](../composer.json) and [lockfile](../composer.lock) | Required local parsing libraries and exact dependency versions |
| [Migration](../database/migrations/2026_09_12_050000_add_proposal_text_extraction.php) | Analysis text table and safe extraction-error column |
| [ProposalAnalysis](../app/Models/ProposalAnalysis.php) | Saved text and proposal relationship |
| [ResearchProposal](../app/Models/ResearchProposal.php) | Adds the analysis relationship |
| [Extraction exception](../app/Exceptions/DocumentExtractionException.php) | Safe document-processing failures |
| [DocumentExtractionService](../app/Services/DocumentExtractionService.php) | PDF/DOCX to readable plain text |
| [DocumentExtractionRunner](../app/Services/DocumentExtractionRunner.php) | Bounded local PHP execution and safe result handling |
| [Reader command](../app/Console/Commands/ExtractProposalText.php) | Internal local worker returning text or a safe error |
| [ProposalExtractionService](../app/Services/ProposalExtractionService.php) | Extract once, save status/text/error in a transaction |
| [Proposal controller](../app/Http/Controllers/ResearchProposalController.php) | Automatic upload extraction and authorized retry action |
| [Routes](../routes/web.php) | Student and Admin extraction POST routes |
| [Proposal detail view](../resources/views/student/proposal/show.blade.php) | Saved-text preview and failure/retry UI |
| [Document fixtures](../tests/Support/DocumentFixtures.php) | Real PDF and DOCX documents generated for isolated tests |
| [Extraction tests](../tests/Feature/ProposalExtractionTest.php) | Real readers, persistence, errors, retries, access and XML limits |
| [Upload tests](../tests/Feature/ResearchProposalTest.php) | Isolates upload/storage validation from the separately tested extraction stage |

## Exact commands

```powershell
Set-Location C:\xampp\htdocs\skillsync
$env:COMPOSER_CACHE_DIR = Join-Path $env:TEMP 'skillsync-composer-cache'
composer require smalot/pdfparser phpoffice/phpword --no-interaction --prefer-dist --no-progress
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=ProposalExtractionTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=extract
php vendor/bin/pint --test app/Exceptions/DocumentExtractionException.php app/Models/ProposalAnalysis.php app/Models/ResearchProposal.php app/Services/DocumentExtractionService.php app/Services/DocumentExtractionRunner.php app/Services/ProposalExtractionService.php app/Console/Commands/ExtractProposalText.php app/Http/Controllers/ResearchProposalController.php routes/web.php database/migrations/2026_09_12_050000_add_proposal_text_extraction.php tests/Support/DocumentFixtures.php tests/Feature/ProposalExtractionTest.php tests/Feature/ResearchProposalTest.php
```

Dependencies are already installed; do not repeat `composer require` for normal use.
Tests use in-memory SQLite and an isolated fake proposal disk. The child reader only
reads the test file passed to it; it does not query the database. Do not run database
reset commands. The migration adds storage without replacing existing proposals.

## Manual testing

Completed verification: **242 tests passed (2,142 assertions)**, including 13
extraction tests. The production asset build and Pint formatting check passed.
The migration was applied successfully; all twelve migrations are applied.
The existing proposal and its private document remain present. No live analysis
was generated during testing; the saved-text table starts empty.

1. Log in as Student and open **Research Proposals**.
2. Open an existing uploaded proposal and click **Extract Document Text**. Wait for
   the result; expect **Extracted** and a readable text preview for a text document.
3. Reload the page. The saved text should remain, without another extraction.
4. Upload a new text-based PDF. After upload, its text should appear automatically.
5. Upload a DOCX containing a heading, paragraphs, and a table. Confirm those contents
   appear with line breaks and separated cells.
6. Upload a scanned/image-only PDF. Expect the scanned-document message while the
   original file remains available for download. No OCR or fabricated text appears.
7. Upload an empty or damaged document that passes upload-format validation. Expect
   an extraction error rather than an empty successful result or a stack trace.
8. Log in as Admin, open **Research Proposals**, and confirm Admin can read saved text
   and extract a not-yet-processed proposal.
9. Log in as another Student and attempt the original proposal's URL; expect 404.
   Faculty cannot access Student/Admin extraction routes.

The preexisting live proposal is preserved and is not automatically processed by
the migration. Use its extraction action when ready. Step 12 (local Proposal
Analysis) is outside this implementation.
