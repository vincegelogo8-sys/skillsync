<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\ResearchProposal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Account',
            'email' => 'new@gmail.com',
            'role' => User::ROLE_STUDENT,
            'password' => 'initial-password',
            'password_confirmation' => 'initial-password',
            'student_number' => 'S-100',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
            'department' => 'Computing',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function studentAccount(string $name, string $course, string $section): User
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'name' => $name,
            'email' => str_replace(' ', '.', strtolower($name)).'@gmail.com',
        ]);
        $student->studentProfile()->create([
            'student_number' => 'S-'.$student->id,
            'course' => $course,
            'year_level' => 3,
            'section' => $section,
        ]);

        return $student;
    }

    public static function accountRoles(): array
    {
        return [[User::ROLE_STUDENT], [User::ROLE_FACULTY]];
    }

    public function test_guests_cannot_view_or_create_accounts(): void
    {
        $this->get('/admin/accounts')->assertRedirect('/login');
        $this->get('/admin/accounts/create')->assertRedirect('/login');
        $this->post('/admin/accounts', $this->details())->assertRedirect('/login');
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('accountRoles')]
    public function test_students_and_faculty_cannot_view_or_create_accounts(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get('/admin/accounts')->assertForbidden();
        $this->get('/admin/accounts/create')->assertForbidden();
        $this->post('/admin/accounts', $this->details())->assertForbidden();
        $this->get(route($role.'.dashboard'))->assertDontSee(route('admin.accounts.index'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_view_the_list_and_form(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create();
        $this->actingAs($admin)->get('/admin/accounts')->assertOk()->assertSee($student->email)
            ->assertSee('Students (1)')->assertSee('Faculty (0)')
            ->assertSee(route('admin.accounts.edit', $student))
            ->assertDontSee($student->password, false)->assertDontSee($admin->email);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->get('/admin/accounts?type=faculty')->assertOk()->assertSee($faculty->email)
            ->assertSee('aria-current="page"', false)->assertDontSee($student->email);
        $this->get('/admin/accounts/create')->assertOk()->assertSee('value="student"', false)
            ->assertSee('value="faculty"', false)->assertDontSee('value="admin"', false);
        $this->get('/admin/dashboard')->assertSee(route('admin.accounts.index'));
    }

    #[DataProvider('accountRoles')]
    public function test_admin_creates_a_login_without_replacing_the_admin_session(string $role): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/accounts', $this->details([
            'role' => $role, 'id' => $admin->id, 'email_verified_at' => now()->toDateTimeString(),
        ]))->assertSessionHasNoErrors()->assertRedirect('/admin/accounts');

        $created = User::where('email', 'new@gmail.com')->sole();
        $this->assertSame($role, $created->role);
        $this->assertTrue(Hash::check('initial-password', $created->password));
        $this->assertNull($created->email_verified_at);
        $this->assertNotSame($admin->id, $created->id);
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('users', 2);
        if ($role === User::ROLE_STUDENT) {
            $this->assertDatabaseHas('student_profiles', [
                'user_id' => $created->id,
                'student_number' => 'S-100',
                'course' => 'BS Information Technology',
                'year_level' => 3,
                'section' => 'A',
            ]);
            $this->assertDatabaseCount('faculty_profiles', 0);
        } else {
            $this->assertDatabaseHas('faculty_profiles', ['user_id' => $created->id, 'department' => 'Computing']);
            $this->assertDatabaseCount('student_profiles', 0);
        }
        $this->get('/admin/accounts?type='.$role)->assertOk()->assertSee($created->email)
            ->assertDontSee('initial-password', false)->assertDontSee($created->password, false);
        $this->assertStringNotContainsString('initial-password', json_encode(session()->all()));
        Mail::assertNothingSent();

        $this->post('/logout');
        $this->post('/login', ['email' => $created->email, 'password' => 'initial-password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($created);
        $this->get('/dashboard')->assertRedirect(route($role.'.dashboard'));
        $this->get(route($role.'.dashboard'))->assertOk();
    }

    public function test_duplicate_emails_are_rejected_without_overwriting_accounts(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'existing@gmail.com']);
        $this->actingAs($admin)->from('/admin/accounts/create')
            ->post('/admin/accounts', $this->details(['email' => $existing->email]))
            ->assertSessionHasErrors('email');
        $this->assertSame($existing->name, $existing->fresh()->name);
        $this->assertSame($existing->password, $existing->fresh()->password);
        $this->assertDatabaseCount('users', 2);
    }

    public static function invalidStudentProfileDetails(): array
    {
        return [
            'missing student number' => ['student_number', ''],
            'missing course' => ['course', ''],
            'unsupported course' => ['course', 'BSIT'],
            'missing year' => ['year_level', ''],
            'unsupported year' => ['year_level', 2],
            'missing section' => ['section', ''],
            'unsupported section' => ['section', 'D'],
        ];
    }

    #[DataProvider('invalidStudentProfileDetails')]
    public function test_invalid_student_profile_fields_prevent_account_creation(string $field, mixed $value): void
    {
        $this->actingAs($this->admin())->from('/admin/accounts/create')
            ->post('/admin/accounts', $this->details([$field => $value]))
            ->assertSessionHasErrors($field)->assertRedirect('/admin/accounts/create');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_duplicate_student_number_is_rejected_when_creating_an_account(): void
    {
        $existing = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $existing->studentProfile()->create([
            'student_number' => 'S-DUP',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ]);

        $this->actingAs($this->admin())->post('/admin/accounts', $this->details([
            'student_number' => 'S-DUP',
        ]))->assertSessionHasErrors('student_number');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('student_profiles', 1);
    }

    public function test_faculty_department_is_required_during_account_creation(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', $this->details([
            'role' => User::ROLE_FACULTY,
            'department' => '',
        ]))->assertSessionHasErrors('department');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('faculty_profiles', 0);
    }

    public function test_failed_student_profile_save_rolls_back_the_new_user(): void
    {
        $existing = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $existing->studentProfile()->create([
            'student_number' => 'S-TAKEN',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ]);

        try {
            app(AccountProvisioningService::class)->create($this->details([
                'email' => 'rollback@gmail.com',
                'student_number' => 'S-TAKEN',
            ]));
            $this->fail('A duplicate profile should fail provisioning.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('users', ['email' => 'rollback@gmail.com']);
            $this->assertDatabaseCount('student_profiles', 1);
        }
    }

    public static function invalidDetails(): array
    {
        return [
            'admin role' => ['role', User::ROLE_ADMIN],
            'unknown role' => ['role', 'coordinator'],
            'missing role' => ['role', null],
            'array role' => ['role', ['student']],
            'missing name' => ['name', ''],
            'long name' => ['name', str_repeat('x', 256)],
            'array name' => ['name', ['Invalid']],
            'invalid email' => ['email', 'invalid'],
            'non Gmail email' => ['email', 'student@example.com'],
            'Gmail subdomain' => ['email', 'student@sub.gmail.com'],
            'Gmail lookalike' => ['email', 'student@gmail.com.example.com'],
            'array email' => ['email', ['invalid@example.test']],
            'missing password' => ['password', ''],
            'short password' => ['password', 'short'],
            'mismatched passwords' => ['password_confirmation', 'different-password'],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_requests_create_no_account_or_flash_passwords(string $field, mixed $value): void
    {
        $this->actingAs($this->admin())->from('/admin/accounts/create')
            ->post('/admin/accounts', $this->details([$field => $value]))
            ->assertSessionHasErrors($field === 'password_confirmation' ? 'password' : $field)
            ->assertRedirect('/admin/accounts/create');
        $this->assertDatabaseCount('users', 1);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->get('/admin/accounts/create')->assertOk()->assertDontSee('initial-password', false);
    }

    public function test_account_list_is_paginated_and_escapes_names(): void
    {
        $admin = $this->admin();
        User::factory()->count(21)->create();
        $last = User::query()->latest('id')->firstOrFail();
        $last->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get('/admin/accounts')->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->count() === 20 && $accounts->total() === 21)
            ->assertSee($last->name)->assertDontSee($last->name, false);
        $this->get('/admin/accounts?page=2')->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->count() === 1);
    }

    #[DataProvider('accountRoles')]
    public function test_admin_can_open_edit_page_for_student_and_faculty_accounts(string $role): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['role' => $role, 'name' => 'Account Holder', 'email' => 'holder@gmail.com']);

        $this->actingAs($admin)->get(route('admin.accounts.edit', $account))
            ->assertOk()
            ->assertSee('Account Holder')
            ->assertSee('holder@gmail.com')
            ->assertSee(ucfirst($role))
            ->assertSee('New Password (optional)')
            ->assertDontSee('name="role"', false)
            ->assertDontSee('value="'.$account->password.'"', false);
    }

    public function test_admin_can_update_name_and_email_without_changing_blank_password(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'old@gmail.com',
            'password' => 'original-password',
            'email_verified_at' => now(),
        ]);
        $passwordHash = $account->password;

        $this->actingAs($admin)->patch(route('admin.accounts.update', $account), [
            'name' => 'Updated Student',
            'email' => 'updated@gmail.com',
            'password' => '',
            'password_confirmation' => '',
            'student_number' => 'S-UPD',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.accounts.index'));

        $account->refresh();
        $this->assertSame('Updated Student', $account->name);
        $this->assertSame('updated@gmail.com', $account->email);
        $this->assertSame($passwordHash, $account->password);
        $this->assertNull($account->email_verified_at);
        $this->assertSame(User::ROLE_STUDENT, $account->role);
        $this->get(route('admin.accounts.index'))->assertSee('Account updated successfully.');
    }

    public function test_admin_can_set_a_new_confirmed_password(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['role' => User::ROLE_FACULTY, 'email' => 'faculty@gmail.com']);
        $passwordHash = $account->password;

        $this->actingAs($admin)->patch(route('admin.accounts.update', $account), [
            'name' => $account->name,
            'email' => $account->email,
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
            'department' => 'Computing',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.accounts.index'));

        $this->assertNotSame($passwordHash, $account->fresh()->password);
        $this->assertTrue(Hash::check('a-new-secure-password', $account->fresh()->password));
    }

    public function test_admin_can_update_student_and_faculty_profiles_with_the_account(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'profile-student@gmail.com']);
        $student->studentProfile()->create([
            'student_number' => 'S-EDIT',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ]);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY, 'email' => 'profile-faculty@gmail.com']);
        $faculty->facultyProfile()->create(['department' => 'Old Department']);

        $this->actingAs($admin)->get(route('admin.accounts.edit', $student))->assertOk()
            ->assertSee('value="S-EDIT"', false)
            ->assertSee('value="BS Information Technology" selected', false);
        $this->patch(route('admin.accounts.update', $student), [
            'name' => $student->name,
            'email' => $student->email,
            'student_number' => 'S-EDIT',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ])->assertSessionHasNoErrors();
        $this->patch(route('admin.accounts.update', $student), [
            'name' => 'Edited Student',
            'email' => $student->email,
            'student_number' => 'S-EDIT-2',
            'course' => 'BS Information Systems',
            'year_level' => 4,
            'section' => 'C',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'student_number' => 'S-EDIT-2',
            'course' => 'BS Information Systems',
            'year_level' => 4,
            'section' => 'C',
        ]);

        $this->patch(route('admin.accounts.update', $faculty), [
            'name' => 'Edited Faculty',
            'email' => $faculty->email,
            'department' => 'New Department',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('faculty_profiles', ['user_id' => $faculty->id, 'department' => 'New Department']);
    }

    public function test_duplicate_email_and_invalid_fields_are_rejected_without_changes(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'owner@gmail.com']);
        $other = User::factory()->create(['email' => 'taken@gmail.com']);

        $this->actingAs($admin)->from(route('admin.accounts.edit', $account))
            ->patch(route('admin.accounts.update', $account), [
                'name' => 'Should Not Save',
                'email' => $other->email,
            ])->assertSessionHasErrors('email');

        $this->patch(route('admin.accounts.update', $account), [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertSame('owner@gmail.com', $account->fresh()->email);
        $this->assertNotSame('Should Not Save', $account->fresh()->name);
    }

    public function test_non_admins_and_admin_accounts_cannot_use_edit_routes(): void
    {
        $account = User::factory()->create(['role' => User::ROLE_STUDENT]);
        foreach ([User::ROLE_STUDENT, User::ROLE_FACULTY] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.accounts.edit', $account))->assertForbidden();
            $this->patch(route('admin.accounts.update', $account), [])->assertForbidden();
        }

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.accounts.edit', $admin))->assertNotFound();
        $this->patch(route('admin.accounts.update', $admin), [
            'name' => 'Changed Admin',
            'email' => $admin->email,
        ])->assertForbidden();
        $this->assertSame($admin->name, $admin->fresh()->name);
    }

    public function test_account_id_profiles_and_proposals_remain_attached_after_update(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'student@gmail.com']);
        $studentProfile = $account->studentProfile()->create([
            'student_number' => 'S-100', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A',
        ]);
        $proposal = $studentProfile->researchProposals()->make();
        $proposal->forceFill([
            'title' => 'Existing Proposal', 'file_path' => 'existing-proposal.pdf',
            'original_filename' => 'proposal.pdf', 'file_type' => 'pdf', 'status' => 'uploaded',
        ])->save();
        $accountId = $account->id;
        $profileId = $studentProfile->id;
        $proposalId = $proposal->id;

        $facultyAccount = User::factory()->create([
            'role' => User::ROLE_FACULTY,
            'email' => 'faculty-before@gmail.com',
        ]);
        $facultyProfile = $facultyAccount->facultyProfile()->create(['department' => 'Computing']);
        $facultyAccountId = $facultyAccount->id;
        $facultyProfileId = $facultyProfile->id;

        $this->actingAs($admin)->patch(route('admin.accounts.update', $account), [
            'id' => 999999,
            'name' => 'Updated Student',
            'email' => 'student-updated@gmail.com',
            'role' => User::ROLE_FACULTY,
            'email_verified_at' => now()->toDateTimeString(),
            'student_number' => 'S-100',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'A',
        ])->assertSessionHasNoErrors();

        $this->assertSame($accountId, $account->fresh()->id);
        $this->assertSame(User::ROLE_STUDENT, $account->fresh()->role);
        $this->assertSame($profileId, $account->fresh()->studentProfile->id);
        $this->assertSame($proposalId, $account->fresh()->studentProfile->researchProposals()->sole()->id);
        $this->assertDatabaseCount('student_profiles', 1);
        $this->assertDatabaseCount('research_proposals', 1);

        $this->patch(route('admin.accounts.update', $facultyAccount), [
            'name' => 'Updated Faculty',
            'email' => 'faculty-after@gmail.com',
            'department' => 'Computing',
        ])->assertSessionHasNoErrors();

        $this->assertSame($facultyAccountId, $facultyAccount->fresh()->id);
        $this->assertSame(User::ROLE_FACULTY, $facultyAccount->fresh()->role);
        $this->assertSame($facultyProfileId, $facultyAccount->fresh()->facultyProfile->id);
        $this->assertDatabaseCount('faculty_profiles', 1);
    }

    public function test_accounts_tabs_and_student_filters_return_expected_records(): void
    {
        $admin = $this->admin();
        $first = $this->studentAccount('Alpha Student', 'BS Information Technology', 'A');
        $this->studentAccount('Beta Student', 'BS Information Technology', 'B');
        $this->studentAccount('Gamma Student', 'BS Information Systems', 'A');
        $unprofiled = User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Unprofiled Student']);
        $outsideOptions = User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Outside Options']);
        $outsideOptions->studentProfile()->create([
            'student_number' => 'S-OUTSIDE',
            'course' => 'BSIT',
            'year_level' => 3,
            'section' => 'D',
        ]);
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => 'Faculty Holder']);
        $faculty->facultyProfile()->create(['department' => 'Computing']);

        $this->actingAs($admin)->get('/admin/accounts')->assertOk()
            ->assertSee($first->email)->assertSee($unprofiled->email)
            ->assertDontSee($faculty->email)
            ->assertSee(route('admin.accounts.index', ['type' => User::ROLE_FACULTY]))
            ->assertDontSee('aria-label="Filter by section"', false)
            ->assertDontSee('<select id="section"', false)
            ->assertDontSee('<select id="course"', false)
            ->assertSee('BS Information Technology (2)')
            ->assertSee('BS Information Systems (1)')
            ->assertSee('All courses (5)');

        $this->get('/admin/accounts?type=faculty')->assertOk()
            ->assertSee($faculty->email)->assertSee('Computing')
            ->assertDontSee($first->email)
            ->assertSee(route('admin.accounts.index', ['type' => User::ROLE_STUDENT]));

        $this->get('/admin/accounts?type=student&section=A')->assertViewHas('section', null)
            ->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 5);

        $coursePage = $this->get(route('admin.accounts.index', [
            'type' => 'student', 'course' => 'BS Information Technology', 'section' => 'A', 'q' => 'Alpha',
        ]))->assertOk()->assertSee('aria-label="Filter by section"', false)
            ->assertSee('A (1)')->assertSee('B (0)')->assertSee('C (0)')
            ->assertSee('aria-current="true"', false)
            ->assertSee('BS Information Technology (1)')
            ->assertSee('BS Information Systems (0)')
            ->assertSee(route('admin.accounts.index', [
                'type' => 'student', 'course' => 'BS Information Systems', 'q' => 'Alpha',
            ]));
        preg_match('~<nav aria-label="Filter by course".*?</nav>~s', $coursePage->getContent(), $courseNav);
        $this->assertNotEmpty($courseNav);
        $this->assertStringNotContainsString('section=', $courseNav[0]);
        $this->assertStringContainsString(htmlspecialchars(route('admin.accounts.index', ['type' => 'student', 'q' => 'Alpha'])), $courseNav[0]);
        $this->assertStringContainsString('name="course" value="BS Information Technology"', $coursePage->getContent());
        $this->assertStringContainsString('name="section" value="A"', $coursePage->getContent());
        $this->assertStringNotContainsString('<select id="section"', $coursePage->getContent());
        $this->assertStringNotContainsString('<select id="course"', $coursePage->getContent());

        $courseAndSection = $this->get(route('admin.accounts.index', [
            'type' => 'student', 'course' => 'BS Information Technology', 'section' => 'A',
        ]))->assertViewHas('accounts', function ($accounts) use ($first): bool {
            return $accounts->total() === 1 && $accounts->first()->id === $first->id;
        })->assertSee('All sections (2)');

        $this->get(route('admin.accounts.index', [
            'type' => 'student', 'course' => 'BS Information Technology', 'section' => 'D',
        ]))->assertViewHas('section', null);
        $this->get('/admin/accounts?type=student&course=unknown&section=A')->assertViewHas('course', null)
            ->assertViewHas('section', null)->assertDontSee('aria-label="Filter by section"', false);
        $this->get('/admin/accounts?type=invalid&section=D&course=unknown')->assertViewHas('type', User::ROLE_STUDENT)
            ->assertViewHas('section', null)->assertViewHas('course', null);
        $this->get('/admin/accounts?type=student&q='.str_repeat('x', 101))
            ->assertViewHas('search', null)->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 5);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))
            ->get('/admin/accounts')->assertForbidden();
    }

    public function test_account_pagination_preserves_valid_student_filters(): void
    {
        $this->actingAs($this->admin());
        for ($index = 0; $index < 21; $index++) {
            $this->studentAccount('Filtered Student '.$index, 'BS Information Systems', 'C');
        }
        $this->studentAccount('Other Student', 'BS Information Technology', 'A');

        $this->get('/admin/accounts?type=student&section=C&course=BS%20Information%20Systems&q=Filtered')
            ->assertViewHas('accounts', function ($accounts): bool {
                parse_str((string) parse_url($accounts->url(2), PHP_URL_QUERY), $query);

                return $accounts->total() === 21
                    && ($query['course'] ?? null) === 'BS Information Systems'
                    && ($query['section'] ?? null) === 'C'
                    && ($query['q'] ?? null) === 'Filtered'
                    && ($query['type'] ?? null) === 'student';
            });
    }
}
