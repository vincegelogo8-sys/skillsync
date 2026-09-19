<?php

namespace Tests\Feature;

use App\Models\FacultyExpertise;
use App\Models\User;
use App\Services\FacultyExpertiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacultyExpertiseTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(): User
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $faculty->facultyProfile()->create(['department' => 'Computing']);

        return $faculty;
    }

    private function details(array $overrides = []): array
    {
        return array_replace(['expertise_area' => 'Web Development', 'proficiency_score' => 90], $overrides);
    }

    public function test_faculty_can_add_read_edit_and_remove_multiple_expertise_areas(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->get('/faculty/expertise')->assertOk()->assertSee('No research expertise added yet.');
        $this->post('/faculty/expertise', $this->details())->assertRedirect('/faculty/expertise')->assertSessionHasNoErrors();
        $entry = $faculty->facultyProfile->expertise()->sole();
        $this->assertInstanceOf(FacultyExpertise::class, $entry);
        $this->assertTrue($entry->facultyProfile->is($faculty->facultyProfile));
        $this->assertSame(90, $entry->proficiency_score);
        $this->post('/faculty/expertise', $this->details(['expertise_area' => 'Database Systems', 'proficiency_score' => 0]))->assertSessionHasNoErrors();
        $this->get('/faculty/expertise')->assertSee('Web Development')->assertSee('90/100')->assertSee('0/100');
        $this->get("/faculty/expertise/{$entry->id}/edit")->assertOk()->assertSee('value="90"', false);
        $this->patch("/faculty/expertise/{$entry->id}", $this->details(['proficiency_score' => 100]))->assertSessionHasNoErrors();
        $this->assertSame(100, $entry->fresh()->proficiency_score);
        $this->patch("/faculty/expertise/{$entry->id}", $this->details(['expertise_area' => 'Computer Vision']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_expertise', 2);
        $this->delete("/faculty/expertise/{$entry->id}")->assertRedirect('/faculty/expertise');
        $this->assertDatabaseMissing('faculty_expertise', ['id' => $entry->id]);
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame(5, $faculty->facultyProfile->fresh()->advisory_limit);
    }

    public function test_a_profile_is_required_without_creating_blank_records(): void
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($faculty)->get('/faculty/expertise')->assertRedirect('/faculty/profile')
            ->assertSessionHas('status', 'complete-profile-for-expertise');
        $this->get('/faculty/profile')->assertSee('Save your full name and department');
        $this->post('/faculty/expertise', $this->details())->assertRedirect('/faculty/profile');
        $this->assertDatabaseCount('faculty_profiles', 0);
        $this->assertDatabaseCount('faculty_expertise', 0);
    }

    public static function invalidDetails(): array
    {
        return [
            'missing area' => [['expertise_area' => ''], 'expertise_area'],
            'unknown area' => [['expertise_area' => 'AI'], 'expertise_area'],
            'wrong case' => [['expertise_area' => 'web development'], 'expertise_area'],
            'area array' => [['expertise_area' => ['Web Development']], 'expertise_area'],
            'missing score' => [['proficiency_score' => ''], 'proficiency_score'],
            'negative score' => [['proficiency_score' => -1], 'proficiency_score'],
            'excessive score' => [['proficiency_score' => 101], 'proficiency_score'],
            'fractional score' => [['proficiency_score' => 70.5], 'proficiency_score'],
            'text score' => [['proficiency_score' => 'high'], 'proficiency_score'],
            'score array' => [['proficiency_score' => [90]], 'proficiency_score'],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_cannot_be_created_or_saved(array $invalid, string $field): void
    {
        $faculty = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $this->actingAs($faculty)->from('/faculty/expertise')->post('/faculty/expertise', $this->details($invalid))
            ->assertSessionHasErrors($field)->assertRedirect('/faculty/expertise');
        $this->get('/faculty/expertise')->assertOk();
        $this->from("/faculty/expertise/{$entry->id}/edit")->patch("/faculty/expertise/{$entry->id}", $this->details($invalid))
            ->assertSessionHasErrors($field);
        $this->get("/faculty/expertise/{$entry->id}/edit")->assertOk();
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame(90, $entry->fresh()->proficiency_score);
        $this->assertSame('Web Development', $entry->fresh()->expertise_area);
    }

    public function test_duplicates_are_rejected_but_different_faculty_can_share_an_area(): void
    {
        $faculty = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $second = $faculty->facultyProfile->expertise()->create($this->details(['expertise_area' => 'Networking']));
        $this->actingAs($faculty)->post('/faculty/expertise', $this->details())->assertSessionHasErrors('expertise_area');
        $this->patch("/faculty/expertise/{$second->id}", $this->details())->assertSessionHasErrors('expertise_area');
        $this->patch("/faculty/expertise/{$entry->id}", $this->details(['proficiency_score' => 75]))->assertSessionHasNoErrors();
        $this->actingAs($this->faculty())->post('/faculty/expertise', $this->details())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_expertise', 3);
    }

    public function test_database_duplicate_race_returns_a_validation_error(): void
    {
        $faculty = $this->faculty();
        $faculty->facultyProfile->expertise()->create($this->details());
        try {
            app(FacultyExpertiseService::class)->save($faculty->facultyProfile, $this->details());
            $this->fail('Duplicate expertise must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expertise_area', $exception->errors());
        }
        $this->assertDatabaseCount('faculty_expertise', 1);
    }

    public function test_faculty_cannot_read_edit_delete_or_take_ownership_of_another_facultys_entry(): void
    {
        $owner = $this->faculty();
        $other = $this->faculty();
        $entry = $owner->facultyProfile->expertise()->create($this->details());
        $this->actingAs($other)->get('/faculty/expertise')->assertDontSee('90/100');
        $this->get("/faculty/expertise/{$entry->id}/edit")->assertNotFound();
        $this->patch("/faculty/expertise/{$entry->id}", $this->details())->assertNotFound();
        $this->delete("/faculty/expertise/{$entry->id}")->assertNotFound();
        $this->post('/faculty/expertise', $this->details([
            'faculty_profile_id' => $owner->facultyProfile->id, 'user_id' => $owner->id, 'advisory_limit' => 999,
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('faculty_expertise', ['faculty_profile_id' => $other->facultyProfile->id]);
        $this->assertSame($owner->facultyProfile->id, $entry->fresh()->faculty_profile_id);
        $this->assertSame(5, $other->facultyProfile->fresh()->advisory_limit);
    }

    public function test_guests_and_other_roles_cannot_use_faculty_endpoints(): void
    {
        $faculty = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $endpoints = [
            ['GET', '/faculty/expertise'], ['POST', '/faculty/expertise'],
            ['GET', "/faculty/expertise/{$entry->id}/edit"],
            ['PATCH', "/faculty/expertise/{$entry->id}"], ['DELETE', "/faculty/expertise/{$entry->id}"],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url, $this->details())->assertRedirect('/login');
        }
        foreach ([User::ROLE_STUDENT, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($endpoints as [$method, $url]) {
                $this->call($method, $url, $this->details())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('faculty_expertise', 1);
    }

    public function test_admin_can_manage_expertise_for_a_selected_faculty(): void
    {
        $faculty = $this->faculty();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $this->actingAs($admin)->get('/admin/expertise')->assertOk()->assertSee($faculty->name)->assertSee($base);
        $this->get($base)->assertOk()->assertSee($faculty->name);
        $this->post($base, $this->details())->assertRedirect($base)->assertSessionHasNoErrors();
        $entry = $faculty->facultyProfile->expertise()->sole();
        $this->get("{$base}/{$entry->id}/edit")->assertOk();
        $this->patch("{$base}/{$entry->id}", $this->details(['proficiency_score' => 80]))->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(80, $entry->fresh()->proficiency_score);
        $this->post($base, $this->details())->assertSessionHasErrors('expertise_area');
        $this->patch("{$base}/{$entry->id}", $this->details(['proficiency_score' => 101]))->assertSessionHasErrors('proficiency_score');
        $this->delete("{$base}/{$entry->id}")->assertRedirect($base);
        $this->assertDatabaseCount('faculty_expertise', 0);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_routes_reject_guests_students_faculty_and_mismatched_profiles(): void
    {
        $faculty = $this->faculty();
        $other = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $endpoints = [
            ['GET', '/admin/expertise'], ['GET', $base], ['POST', $base],
            ['GET', "{$base}/{$entry->id}/edit"], ['PATCH', "{$base}/{$entry->id}"], ['DELETE', "{$base}/{$entry->id}"],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url, $this->details())->assertRedirect('/login');
        }
        foreach ([$faculty, User::factory()->create(['role' => User::ROLE_STUDENT])] as $user) {
            $this->actingAs($user);
            foreach ($endpoints as [$method, $url]) {
                $this->call($method, $url, $this->details())->assertForbidden();
            }
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $wrong = '/admin/faculty/'.$other->facultyProfile->id.'/expertise/'.$entry->id;
        $this->get($wrong.'/edit')->assertNotFound();
        $this->patch($wrong, $this->details())->assertNotFound();
        $this->delete($wrong)->assertNotFound();
        $this->get('/admin/faculty/999999/expertise')->assertNotFound();
        $this->assertDatabaseCount('faculty_expertise', 1);
    }

    public function test_admin_list_handles_incomplete_profiles_and_excludes_nonfaculty(): void
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/expertise')->assertOk()->assertSee($faculty->email)
            ->assertSee('Faculty must complete their basic profile first.')->assertDontSee($student->email);
        $this->assertDatabaseCount('faculty_profiles', 0);
        $profile = $student->facultyProfile()->create(['department' => 'Computing']);
        $this->get('/admin/faculty/'.$profile->id.'/expertise')->assertForbidden();
    }

    public function test_navigation_links_and_canonical_areas_are_available(): void
    {
        $this->actingAs($this->faculty())->get('/faculty/dashboard')->assertSee(route('faculty.expertise.index'));
        $response = $this->get('/faculty/expertise')->assertOk();
        foreach (config('expertise.areas') as $area) {
            $response->assertSee($area);
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/dashboard')
            ->assertSee(route('admin.expertise.faculty'));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get('/student/dashboard')
            ->assertDontSee(route('faculty.expertise.index'))->assertDontSee(route('admin.expertise.faculty'));
    }
}
