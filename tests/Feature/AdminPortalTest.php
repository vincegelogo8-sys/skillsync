<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_portal_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Administrator Portal');
    }

    public function test_admin_signs_in_through_the_admin_portal(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_cannot_sign_in_through_the_general_login(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->from('/login')->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** @dataProvider nonAdminRoles */
    public function test_other_roles_cannot_sign_in_through_the_admin_portal(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->from('/admin/login')->post('/admin/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function nonAdminRoles(): array
    {
        return [[User::ROLE_STUDENT], [User::ROLE_FACULTY]];
    }

    public function test_wrong_admin_password_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guests_opening_an_admin_page_go_to_the_admin_portal(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
    }

    public function test_guests_opening_a_student_page_still_go_to_the_general_login(): void
    {
        $this->get('/student/dashboard')->assertRedirect(route('login'));
    }

    public function test_admin_logout_returns_to_the_admin_landing_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/logout')->assertRedirect(route('admin.landing'));
        $this->assertGuest();
    }

    public function test_student_logout_returns_to_the_home_page(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $this->actingAs($student)->post('/logout')->assertRedirect('/');
    }

    public function test_admin_landing_page_renders_for_guests_and_links_to_the_admin_login(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Administrator Portal')
            ->assertSee(route('admin.login'), false);
    }

    public function test_signed_in_users_opening_the_admin_landing_page_go_to_their_dashboard(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_FACULTY, User::ROLE_STUDENT] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/admin')->assertRedirect(route('dashboard'));
        }
    }
}