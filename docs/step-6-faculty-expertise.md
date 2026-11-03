# Step 6: Faculty Skills

## Existing storage

Faculty skill categories remain in the existing `faculty_expertise` table.
Each row belongs to a `faculty_profiles` record through `faculty_profile_id`;
`FacultyProfile::expertise()` and `FacultyExpertise::facultyProfile()` are the
existing Eloquent relationship pair. No table, column, or migration change is
required for faculty selection or recommendation matching.

The current database contains 36 rows across 13 faculty profiles, all using the
nine supported categories. The category allowlist is `config/expertise.php`.
Existing proficiency values and legacy technical-skill values are preserved,
but neither is entered or used for skill matching.

## Faculty management

Authenticated faculty open **Faculty Skills** from the faculty workspace. The
page reads supported categories through the signed-in user's own
`facultyProfile` relationship. Its checkbox form saves the complete selection
to the same profile; omitted checkboxes remove deselected supported categories,
and unique category rows are reused rather than duplicated. Faculty cannot
submit a profile ID to change the owner. The route and request authorize faculty
role and derive ownership from the authenticated account.

Only these categories are selectable:

1. Web Development
2. Mobile Development
3. Database Systems
4. Networking
5. Cybersecurity
6. Machine Learning / Data Analytics
7. Software Engineering
8. Systems Analysis
9. Internet of Things

The admin retains the existing faculty-list and per-profile management pages.
They use the same category allowlist. Existing records outside the allowlist are
not deleted by faculty selection and are excluded from the matching view.

## Proposal recommendations

Proposal extraction continues to use the configured local/offline NLP pipeline.
`ExpertiseMatchingService` canonicalizes extracted analysis categories and saved
faculty categories against the nine-category mapping. A direct match requires a
supported category on both sides. A related match additionally requires an
existing configured relationship and supporting evidence in the proposal text.
Unsupported categories and technical-skill text do not produce matches.

`RecommendationService` eager-loads each faculty profile's saved
`expertise` relationship, excludes faculty with no matching category, and stores
only relevant categories and their direct/related explanations. The existing
sorter uses distinct matched category counts internally, then surname and first
name; counts and scores are not displayed. Capacity checks, adviser requests,
decisions, and notifications remain separate from skill matching.

## Validation

Run the focused tests from the project root:

```powershell
php artisan test tests/Feature/FacultyExpertiseTest.php
php artisan test tests/Feature/ExpertiseMatchingTest.php
php artisan test tests/Feature/RecommendationTest.php
```

The feature tests use the configured test database and do not alter faculty rows
in the application database. Do not run destructive refresh commands against
the application database.
