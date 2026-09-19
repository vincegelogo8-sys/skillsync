# Step 9: Faculty Skills Assessment

## Inspection and scope

Steps 1–8 were present, with nine applied migrations. No assessment tables or
application files existed. The local database had 3 users, 1 Faculty Profile,
2 expertise entries, 2 preferences, and no competency evaluations.

Step 9 adds Admin question-bank management, Faculty assessment attempts, automatic
grading, Faculty result history, and an Admin completed-results list. All features
operate locally using Laravel, Blade, and built Tailwind assets.

## Assessment behavior

- Admin adds, edits, and removes questions with four distinct options (A–D), one
  correct answer, and a category from the existing controlled expertise list.
- At least 10 questions are required to start. Each attempt randomly selects exactly
  10 distinct question records from the bank. No production questions are seeded.
- Faculty must complete their basic profile first. Opening the assessment page
  creates no attempt; the **Start Assessment** POST action creates it.
- One unfinished attempt is reused for a faculty member. Repeated start requests
  resume that attempt. Faculty may start another attempt after completing it.
- Question text, options, and grading keys are snapshotted when the attempt starts.
  Later Admin edits or removals affect future attempts, preserving existing exams.
- All 10 answers must be submitted together. Unsubmitted selections are not autosaved;
  the page tells Faculty to keep it open while answering. Validation failures preserve
  selected choices in the form. Resume restores the questions, not unsaved selections.
- Grading uses the server's stored key: `score / 10 × 100`. Examples: 0 correct = 0%,
  9 correct = 90%, 10 correct = 100%. The future 10% recommendation weight is not
  applied to this percentage.
- Submission saves answers and result in one transaction. Completed attempts are
  immutable through these routes: double clicks and replayed requests return the
  existing result without regrading or adding another attempt.
- Faculty see the total result and their own history, with no answer key or per-item
  correctness feedback. Admin can view all completed results, newest first.

Retakes are allowed because the original plan does not specify a retake limit.
All completed attempts are retained. Choosing which attempt feeds the final
recommendation can be handled when that scoring integration is implemented.

## Storage and protection

`skills_assessment_questions` stores the editable bank. Question text is limited
to 5,000 characters, each option to 500 characters, with required distinct choices,
an allowed category, and one valid key from `a`, `b`, `c`, or `d`.

`skills_assessment_attempts` stores `faculty_profile_id`, score, total items,
percentage, completion time, and timestamps. Score, percentage, and completion time
remain null until a successful submission.

`skills_assessment_answers` is the normalized per-attempt answer table. Each row
references an attempt, its original question where still available, a unique question
position, a snapshot of question text/options/key, and the selected answer. Snapshot
rows are created with an empty selection at start. Deleting a bank question nulls
its original ID while preserving the snapshot. Deleting a faculty profile cascades
to its attempts and answers; the question bank remains intact.

Starting locks the faculty profile to serialize concurrent starts. Submission locks
the attempt, validates the exact 10 answer IDs and allowed choices, and calculates
the score locally. Submitted scores, ownership fields, or grading keys are ignored.
Incorrect, missing, foreign, or extra answer IDs fail validation before answers save.

Admin routes use `auth` and `role:admin`; Faculty routes use `auth` and `role:faculty`.
Ownership is checked for every attempt display and submission. Another faculty
member's attempt returns 404. Students cannot access either module. All forms use
CSRF protection. Correct-answer attributes are hidden from model serialization, and
the Faculty view query explicitly selects only public question fields. Completed
result pages receive no item records. Blade escapes question and option text.

## Complete source files

Paths are relative to `C:\xampp\htdocs\skillsync`. Links open full source files.

| File | Responsibility |
| --- | --- |
| [Migration](../database/migrations/2026_09_12_030000_create_skills_assessment_tables.php) | Three assessment tables, foreign keys, unique attempt positions |
| [Question](../app/Models/SkillsAssessmentQuestion.php) | Bank fields and hidden key |
| [Attempt](../app/Models/SkillsAssessmentAttempt.php) | Result casts, owner and answer relationships |
| [Answer](../app/Models/SkillsAssessmentAnswer.php) | Snapshot/selection row and hidden key |
| [FacultyProfile](../app/Models/FacultyProfile.php) | Assessment attempts relationship |
| [Question request](../app/Http/Requests/AssessmentQuestionRequest.php) | Admin question validation |
| [Assessment service](../app/Services/SkillsAssessmentService.php) | Start/resume, snapshots, answer validation and transactional grading |
| [Faculty controller](../app/Http/Controllers/SkillsAssessmentController.php) | Faculty history, start, ownership checks, safe question display, submission |
| [Admin controller](../app/Http/Controllers/Admin/AssessmentQuestionController.php) | Question management and result list |
| [Routes](../routes/web.php) | Role-protected assessment routes |
| [Question list](../resources/views/admin/assessment-questions/index.blade.php) | Bank readiness, editing and confirmed removal |
| [Question form](../resources/views/admin/assessment-questions/form.blade.php) | Admin question/options/key/category inputs |
| [Admin results](../resources/views/admin/assessment-results/index.blade.php) | Paginated completed results |
| [Faculty assessment index](../resources/views/faculty/assessment/index.blade.php) | Readiness, start/resume and history |
| [Faculty attempt/result](../resources/views/faculty/assessment/show.blade.php) | Ten radio-answer groups or total result |
| [Navigation](../resources/views/layouts/navigation.blade.php) | Role-appropriate links |
| [Dashboard](../resources/views/dashboard.blade.php) | Assessment shortcuts |
| [Faculty Profile](../resources/views/faculty/profile/edit.blade.php) | Profile-completion notice |
| [Tests](../tests/Feature/SkillsAssessmentTest.php) | Bank CRUD, grading, validation, ownership, key privacy, snapshots and replay protection |

## Exact commands

```powershell
Set-Location C:\xampp\htdocs\skillsync
php artisan migrate:status
php artisan migrate --pretend
php artisan test --filter=SkillsAssessmentTest
php artisan test
npm.cmd run build
php artisan migrate
php artisan migrate:status
php artisan route:list --path=assessment
php vendor/bin/pint --test app/Models/SkillsAssessmentQuestion.php app/Models/SkillsAssessmentAttempt.php app/Models/SkillsAssessmentAnswer.php app/Models/FacultyProfile.php app/Http/Requests/AssessmentQuestionRequest.php app/Services/SkillsAssessmentService.php app/Http/Controllers/SkillsAssessmentController.php app/Http/Controllers/Admin/AssessmentQuestionController.php routes/web.php database/migrations/2026_09_12_030000_create_skills_assessment_tables.php tests/Feature/SkillsAssessmentTest.php
```

The migration adds only assessment tables. Do not run `migrate:fresh`, `migrate:reset`,
or `db:wipe` on the application database. Automated tests use in-memory SQLite.

## Manual testing

Completed verification: **204 tests passed (1,757 assertions)**, including 30
assessment tests. The production asset build and Pint formatting check passed.
The migration was applied successfully; all ten migrations are applied. Existing
counts remained 3 users, 1 Faculty Profile, 2 expertise entries, 2 preferences,
and 0 competency evaluations. All three new assessment tables are empty.

1. Log in as `systemadmin@gmail.com` with the existing password.
2. Open **Assessment Questions**, then **Add Question**. Enter a question, four
   distinct options, the correct option, and its category. Save it.
3. Add at least 10 appropriate questions. Faculty should see the unavailable notice
   while the bank contains fewer than 10; it should become available at 10.
4. Log in as an Admin-created Faculty account and complete its basic profile if needed.
5. Open **Skills Assessment**, then **Start Assessment**. Confirm exactly 10 questions.
6. Return to the assessment index and select **Resume Assessment**. The same question
   set should appear. Selections made before leaving were not autosaved.
7. Answer every question and select **Submit Assessment**. Verify the total score
   and percentage. A result of 9 correct answers should display **9/10 — 90.00%**.
8. Reload the result page or resend the same submission. The completed result must
   remain unchanged. Confirm it appears in the Faculty history.
9. Start another attempt to confirm a separate history entry is created.
10. As Admin, open **Assessment Results** and verify the completed faculty result.
11. Edit or remove a question after an attempt has started. Resume that attempt and
    confirm its original wording/options remain; grading uses its original key.
12. As another Faculty account, open the first faculty member's attempt URL. Expect
    404. As Student, visit `/faculty/assessment` or `/admin/assessment-questions`;
    expect 403. Faculty must also receive 403 on Admin question/results URLs.

The automated suite verifies the scoring examples with isolated test questions.
No test questions, answers, or scores are inserted into the live database.
Step 10 (Research Proposal Upload) is outside this implementation.
