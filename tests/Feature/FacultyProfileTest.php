<?php

namespace Tests\Feature;

use App\Models\FacultyProfile;
use App\Models\User;
use App\Services\FacultyProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FacultyProfileTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Carlo Santos',
            'department' => 'College of Computer Studies',
        ], $overrides);
    }

    private function faculty(): User
    {
        return User::factory()->create(['role' => User::ROLE_FACULTY]);
    }

    public function test_guests_cannot_read_or_save_faculty_profiles(): void
    {
        $this->get('/faculty/profile')->assertRedirect('/login');
        $this->patch('/faculty/profile', $this->details())->assertRedirect('/login');
        $this->assertDatabaseCount('faculty_profiles', 0);
    }

    public static function otherRoles(): array
    {
        return [[User::ROLE_STUDENT], [User::ROLE_ADMIN]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_read_or_save_faculty_profiles(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get('/faculty/profile')->assertForbidden();
        $this->patch('/faculty/profile', $this->details())->assertForbidden();
        $this->get(route($role.'.dashboard'))->assertDontSee(route('faculty.profile.edit'));
        $this->assertDatabaseCount('faculty_profiles', 0);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_opening_the_profile_does_not_create_a_record(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->get('/faculty/profile')->assertOk()
            ->assertSee($faculty->name)->assertDontSee('name="advisory_limit"', false);
        $this->get('/faculty/dashboard')->assertSee(route('faculty.profile.edit'));
        $this->assertDatabaseCount('faculty_profiles', 0);
    }

    public function test_faculty_can_create_and_update_one_profile_and_the_account_name(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->patch('/faculty/profile', $this->details())
            ->assertSessionHasNoErrors()->assertRedirect('/faculty/profile')
            ->assertSessionHas('status', 'faculty-profile-updated');

        $profile = $faculty->fresh()->facultyProfile;
        $this->assertInstanceOf(FacultyProfile::class, $profile);
        $this->assertTrue($profile->user->is($faculty));
        $this->assertSame(5, $profile->advisory_limit);
        $this->assertSame('Carlo Santos', $faculty->fresh()->name);
        $this->get('/faculty/profile')->assertOk()->assertSee('Carlo Santos')
            ->assertSee('College of Computer Studies')->assertSee('Faculty profile saved successfully.');
        $this->get('/profile')->assertOk()->assertSee('Carlo Santos');

        $this->patch('/faculty/profile', $this->details([
            'name' => 'Carlo R. Santos', 'department' => 'Information Technology',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_profiles', 1);
        $this->assertSame($profile->id, $faculty->fresh()->facultyProfile->id);
        $this->assertSame('Carlo R. Santos', $faculty->fresh()->name);
        $this->assertDatabaseHas('faculty_profiles', [
            'user_id' => $faculty->id, 'department' => 'Information Technology', 'advisory_limit' => 5,
        ]);
    }

    public function test_account_settings_name_changes_appear_in_the_faculty_profile(): void
    {
        $faculty = $this->faculty();
        $faculty->update(['email' => 'faculty@gmail.com']);
        $faculty->facultyProfile()->create(['department' => 'Computing']);
        $this->actingAs($faculty)->patch('/profile', ['name' => 'Updated Faculty Name', 'email' => $faculty->email])
            ->assertSessionHasNoErrors();

        $this->get('/faculty/profile')->assertOk()->assertSee('Updated Faculty Name');
        $this->assertDatabaseCount('faculty_profiles', 1);
    }

    public function test_forged_ownership_role_credentials_and_threshold_are_ignored(): void
    {
        $other = $this->faculty();
        $otherProfile = $other->facultyProfile()->create(['department' => 'Other Department']);
        $faculty = $this->faculty();
        $originalPassword = $faculty->password;
        $originalEmail = $faculty->email;

        $this->actingAs($faculty)->get('/faculty/profile?user_id='.$other->id)
            ->assertOk()->assertDontSee('Other Department');
        $forged = $this->details([
            'user_id' => $other->id,
            'id' => $otherProfile->id,
            'role' => User::ROLE_ADMIN,
            'email' => 'forged@example.test',
            'password' => 'forged-password',
            'advisory_limit' => 99,
        ]);
        $this->patch('/faculty/profile', $forged)->assertSessionHasNoErrors();
        $profile = $faculty->fresh()->facultyProfile;
        $this->assertSame(5, $profile->advisory_limit);
        $this->assertSame($faculty->id, $profile->user_id);

        // Simulate an Admin-set limit; ordinary profile saves must preserve it.
        $profile->advisory_limit = 7;
        $profile->save();
        $this->patch('/faculty/profile', $forged)->assertSessionHasNoErrors();
        $this->assertSame(7, $profile->fresh()->advisory_limit);
        $this->assertSame(User::ROLE_FACULTY, $faculty->fresh()->role);
        $this->assertSame($originalEmail, $faculty->fresh()->email);
        $this->assertSame($originalPassword, $faculty->fresh()->password);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame('Other Department', $otherProfile->fresh()->department);
        $this->assertDatabaseCount('faculty_profiles', 2);
        $this->patch('/faculty/profile/'.$otherProfile->id, $this->details())->assertNotFound();
    }

    public static function invalidDetails(): array
    {
        return [
            'missing name' => ['name', ''],
            'missing department' => ['department', ''],
            'long name' => ['name', str_repeat('x', 256)],
            'long department' => ['department', str_repeat('x', 151)],
            'array name' => ['name', ['not text']],
            'array department' => ['department', ['not text']],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_input_is_rejected_without_changing_the_name(string $field, mixed $value): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->from('/faculty/profile')
            ->patch('/faculty/profile', $this->details([$field => $value]))
            ->assertSessionHasErrors($field)->assertRedirect('/faculty/profile');

        $this->assertDatabaseCount('faculty_profiles', 0);
        $this->assertSame($faculty->name, $faculty->fresh()->name);
        $this->get('/faculty/profile')->assertOk();
    }

    public function test_profile_text_is_escaped(): void
    {
        $faculty = $this->faculty();
        $text = '<script>alert(1)</script>';
        $this->actingAs($faculty)->patch('/faculty/profile', $this->details(['name' => $text, 'department' => $text]))
            ->assertSessionHasNoErrors();

        $this->get('/faculty/profile')->assertOk()->assertSee($text)->assertDontSee($text, false);
    }

    public function test_a_failed_profile_write_rolls_back_the_account_name(): void
    {
        $faculty = $this->faculty();
        $originalName = $faculty->name;
        Event::listen('eloquent.creating: '.FacultyProfile::class, function () {
            throw new RuntimeException('Simulated profile write failure');
        });

        try {
            app(FacultyProfileService::class)->save($faculty, $this->details());
            $this->fail('Expected the simulated write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated profile write failure', $exception->getMessage());
        }

        $this->assertSame($originalName, $faculty->fresh()->name);
        $this->assertDatabaseCount('faculty_profiles', 0);
    }

    public function test_database_rejects_a_second_profile_for_the_same_user(): void
    {
        $faculty = $this->faculty();
        $faculty->facultyProfile()->create(['department' => 'Computing']);

        $this->expectException(QueryException::class);
        $faculty->facultyProfile()->create(['department' => 'Duplicate']);
    }

    public function test_owner_and_limit_cannot_be_mass_assigned(): void
    {
        $profile = new FacultyProfile(['department' => 'Computing', 'user_id' => 100, 'advisory_limit' => 99]);

        $this->assertNull($profile->user_id);
        $this->assertSame(5, $profile->advisory_limit);
    }

    public function test_account_deletion_removes_its_faculty_profile(): void
    {
        $faculty = $this->faculty();
        $faculty->facultyProfile()->create(['department' => 'Computing']);
        $this->actingAs($faculty)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('faculty_profiles', ['user_id' => $faculty->id]);
    }
}
