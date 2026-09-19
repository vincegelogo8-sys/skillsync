<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'student' => [User::ROLE_STUDENT],
            'faculty' => [User::ROLE_FACULTY],
            'admin' => [User::ROLE_ADMIN],
        ];
    }

    #[DataProvider('roles')]
    public function test_guests_are_redirected_to_login(string $role): void
    {
        $this->get(route($role.'.dashboard'))->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_each_role_can_only_access_its_own_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route($role.'.dashboard'));

        foreach (User::ROLES as $target) {
            $response = $this->get(route($target.'.dashboard'));

            if ($target === $role) {
                $response->assertOk()->assertSee(ucfirst($role).' Dashboard');
            } else {
                $response->assertForbidden();
            }
        }
    }

    #[DataProvider('roles')]
    public function test_login_opens_the_accounts_role_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect(route($role.'.dashboard'));
    }

    #[DataProvider('roles')]
    public function test_profile_updates_cannot_change_roles(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'email' => $role.'@gmail.com']);
        $target = $role === User::ROLE_ADMIN ? User::ROLE_STUDENT : User::ROLE_ADMIN;

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Updated Name',
            'email' => $user->email,
            'role' => $target,
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertSame($role, $user->fresh()->role);
    }

    public function test_unknown_roles_are_denied(): void
    {
        $user = User::factory()->create();
        // Simulate an invalid legacy role without persisting an invalid DB value.
        $user->role = 'coordinator';
        $this->actingAs($user)->get('/dashboard')->assertForbidden();

        foreach (User::ROLES as $role) {
            $this->get(route($role.'.dashboard'))->assertForbidden();
        }
    }

    public function test_roles_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'Example', 'role' => User::ROLE_ADMIN]);

        $this->assertSame(User::ROLE_STUDENT, $user->role);
    }
}
