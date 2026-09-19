<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public static function visitors(): array
    {
        return [['guest'], [User::ROLE_STUDENT], [User::ROLE_FACULTY], [User::ROLE_ADMIN]];
    }

    #[DataProvider('visitors')]
    public function test_public_registration_is_unavailable_to_every_role(string $role): void
    {
        if ($role !== 'guest') {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        $before = User::count();
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Unapproved Account',
            'email' => 'unapproved@example.test',
            'password' => 'test-password',
            'password_confirmation' => 'test-password',
            'role' => User::ROLE_ADMIN,
        ])->assertNotFound();
        $this->assertDatabaseCount('users', $before);
    }

    public function test_public_pages_have_no_registration_link(): void
    {
        $this->get('/')->assertOk()->assertDontSee('/register', false);
        $this->get('/login')->assertOk()->assertDontSee('/register', false)
            ->assertSee('Contact your Admin if you need an account.');
    }
}
