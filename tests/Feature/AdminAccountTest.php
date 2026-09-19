<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
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
            ->assertDontSee($student->password, false)->assertDontSee($admin->email);
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
        $this->assertDatabaseCount('student_profiles', 0);
        $this->assertDatabaseCount('faculty_profiles', 0);
        $this->get('/admin/accounts')->assertOk()->assertSee($created->email)
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
}
