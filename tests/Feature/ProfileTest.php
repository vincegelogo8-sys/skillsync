<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@gmail.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@gmail.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'test@gmail.com']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_student_and_faculty_email_updates_require_unique_gmail_and_allow_current_email(): void
    {
        User::factory()->create(['email' => 'taken@gmail.com']);
        foreach (['student', 'faculty'] as $role) {
            $user = User::factory()->create(['role' => $role, 'email' => $role.'@skillsync.test']);
            $this->actingAs($user);
            foreach (['', 'invalid', 'other@example.com', 'other@sub.gmail.com', 'other@gmail.com.example.com', 'taken@gmail.com'] as $email) {
                $this->patch('/profile', ['name' => $user->name, 'email' => $email])->assertSessionHasErrors('email');
                $this->assertSame($role.'@skillsync.test', $user->fresh()->email);
            }
            $email = $role.'@gmail.com';
            $this->patch('/profile', ['name' => $user->name, 'email' => $email])->assertSessionHasNoErrors();
            $this->patch('/profile', ['name' => $user->name, 'email' => $email])->assertSessionHasNoErrors();
            $this->assertSame($email, $user->fresh()->email);
        }
    }

    public function test_admin_email_is_not_restricted_to_gmail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch('/profile', ['name' => $admin->name, 'email' => 'admin@example.com'])->assertSessionHasNoErrors();
    }

    public function test_profile_page_has_no_delete_account_section_or_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk()->assertDontSee('Delete Account');
        $this->delete('/profile')->assertMethodNotAllowed();
        $this->assertNotNull($user->fresh());
    }
}
