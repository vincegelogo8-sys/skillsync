<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $overrides = []): array
    {
        return array_replace([
            'student_number' => '2026-0001',
            'course' => 'BS Information Technology',
            'year_level' => 3,
            'section' => 'BSIT 3-B',
        ], $overrides);
    }

    public function test_guests_cannot_read_or_save_student_profiles(): void
    {
        $this->get('/student/profile')->assertRedirect('/login');
        $this->patch('/student/profile', $this->details())->assertRedirect('/login');
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public static function staffRoles(): array
    {
        return [[User::ROLE_FACULTY], [User::ROLE_ADMIN]];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_cannot_read_or_save_student_profiles(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get('/student/profile')->assertForbidden();
        $this->patch('/student/profile', $this->details())->assertForbidden();
        $this->get('/dashboard')->assertRedirect(route($role.'.dashboard'));
        $this->get(route($role.'.dashboard'))->assertDontSee(route('student.profile.edit'));
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_opening_an_empty_profile_does_not_create_a_record(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student)->get('/student/profile')
            ->assertOk()->assertSee('Student Profile')->assertSee($student->name);
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_student_can_create_read_and_update_one_profile(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student)->patch('/student/profile', $this->details())
            ->assertSessionHasNoErrors()->assertRedirect('/student/profile')
            ->assertSessionHas('status', 'student-profile-updated');

        $profile = $student->fresh()->studentProfile;
        $this->assertInstanceOf(StudentProfile::class, $profile);
        $this->assertTrue($profile->user->is($student));
        $this->assertSame(3, $profile->year_level);
        $this->get('/student/profile')->assertOk()
            ->assertSee('2026-0001')->assertSee('BS Information Technology');

        $this->patch('/student/profile', $this->details(['year_level' => 4, 'section' => 'BSIT 4-A']))
            ->assertSessionHasNoErrors()->assertRedirect('/student/profile');
        $this->assertDatabaseCount('student_profiles', 1);
        $this->assertSame($profile->id, $student->fresh()->studentProfile->id);
        $this->assertDatabaseHas('student_profiles', $this->details([
            'user_id' => $student->id, 'year_level' => 4, 'section' => 'BSIT 4-A',
        ]));
    }

    public function test_student_cannot_use_another_students_number(): void
    {
        $other = User::factory()->create();
        $other->studentProfile()->create($this->details());
        $student = User::factory()->create();
        $student->studentProfile()->create($this->details(['student_number' => '2026-0002']));

        $this->actingAs($student)->from('/student/profile')->patch('/student/profile', $this->details())
            ->assertSessionHasErrors('student_number')->assertRedirect('/student/profile');

        $this->assertSame('2026-0002', $student->fresh()->studentProfile->student_number);
        $this->assertDatabaseCount('student_profiles', 2);
    }

    public function test_forged_owner_profile_id_and_role_do_not_change_another_account(): void
    {
        $other = User::factory()->create();
        $otherProfile = $other->studentProfile()->create($this->details());
        $student = User::factory()->create();

        $this->actingAs($student)->get('/student/profile?user_id='.$other->id)
            ->assertOk()->assertDontSee('2026-0001');

        $this->patch('/student/profile', $this->details([
            'student_number' => '2026-0002',
            'user_id' => $other->id,
            'id' => $otherProfile->id,
            'role' => User::ROLE_ADMIN,
            'name' => 'Forged name',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(User::ROLE_STUDENT, $student->fresh()->role);
        $this->assertSame($student->name, $student->fresh()->name);
        $this->assertSame('2026-0001', $otherProfile->fresh()->student_number);
        $this->assertSame('2026-0002', $student->fresh()->studentProfile->student_number);
        $this->assertDatabaseCount('student_profiles', 2);

        $this->patch('/student/profile/'.$otherProfile->id, $this->details())->assertNotFound();
    }

    public static function invalidDetails(): array
    {
        return [
            'missing number' => ['student_number', ''],
            'missing course' => ['course', ''],
            'missing year' => ['year_level', ''],
            'missing section' => ['section', ''],
            'long number' => ['student_number', str_repeat('x', 51)],
            'long course' => ['course', str_repeat('x', 151)],
            'long section' => ['section', str_repeat('x', 51)],
            'year below range' => ['year_level', 0],
            'year above range' => ['year_level', 7],
            'fractional year' => ['year_level', 2.5],
            'text year' => ['year_level', 'third'],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_are_rejected(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create())->from('/student/profile')
            ->patch('/student/profile', $this->details([$field => $value]))
            ->assertSessionHasErrors($field)->assertRedirect('/student/profile');

        $this->assertDatabaseCount('student_profiles', 0);
        $this->get('/student/profile')->assertOk();
    }

    public function test_saved_text_is_escaped_when_rendered(): void
    {
        $student = User::factory()->create();
        $course = '<script>alert(1)</script>';
        $student->studentProfile()->create($this->details(['course' => $course]));

        $this->actingAs($student)->get('/student/profile')
            ->assertOk()->assertSee($course)->assertDontSee($course, false);
    }

    public function test_account_deletion_also_removes_its_student_profile(): void
    {
        $student = User::factory()->create();
        $student->studentProfile()->create($this->details());
        $this->actingAs($student)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('student_profiles', ['user_id' => $student->id]);
    }
}
