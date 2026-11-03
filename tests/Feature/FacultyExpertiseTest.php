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
        return array_replace(['expertise_area' => 'Web Development'], $overrides);
    }

    public function test_admin_can_add_read_edit_and_remove_multiple_faculty_skills(): void
    {
        $faculty = $this->faculty();
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get($base)->assertOk()->assertSee('No faculty skills registered.');
        $this->post($base, $this->details())->assertRedirect($base)->assertSessionHasNoErrors();
        $entry = $faculty->facultyProfile->expertise()->sole();
        $this->assertInstanceOf(FacultyExpertise::class, $entry);
        $this->assertTrue($entry->facultyProfile->is($faculty->facultyProfile));
        $this->assertNull($entry->proficiency_score);
        $this->post($base, $this->details(['expertise_area' => 'Database Systems']))->assertSessionHasNoErrors();
        $this->get($base)->assertSee('Web Development')->assertSee('Database Systems')->assertDontSee('Proficiency');
        $this->get("{$base}/{$entry->id}/edit")->assertOk()->assertSee('Web Development')->assertDontSee('proficiency_score');
        $this->patch("{$base}/{$entry->id}", $this->details(['expertise_area' => 'Software Engineering']))->assertSessionHasNoErrors();
        $this->assertSame('Software Engineering', $entry->fresh()->expertise_area);
        $this->assertDatabaseCount('faculty_expertise', 2);
        $this->delete("{$base}/{$entry->id}")->assertRedirect($base);
        $this->assertDatabaseMissing('faculty_expertise', ['id' => $entry->id]);
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame(5, $faculty->facultyProfile->fresh()->advisory_limit);
    }

    public function test_a_profile_is_required_without_creating_blank_records(): void
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($faculty)->get('/faculty/expertise')->assertRedirect('/faculty/profile')
            ->assertSessionHas('status', 'complete-profile-for-skills');
        $this->get('/faculty/profile')->assertSee('Save your full name and department');
        $this->post('/faculty/expertise', $this->details())->assertForbidden();
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
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_cannot_be_created_or_saved(array $invalid, string $field): void
    {
        $faculty = $this->faculty();
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->from($base)->post($base, $this->details($invalid))
            ->assertSessionHasErrors($field)->assertRedirect($base);
        $this->get($base)->assertOk();
        $this->from("{$base}/{$entry->id}/edit")->patch("{$base}/{$entry->id}", $this->details($invalid))
            ->assertSessionHasErrors($field);
        $this->get("{$base}/{$entry->id}/edit")->assertOk();
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertNull($entry->fresh()->proficiency_score);
        $this->assertSame('Web Development', $entry->fresh()->expertise_area);
    }

    public function test_duplicates_are_rejected_but_different_faculty_can_share_an_area(): void
    {
        $faculty = $this->faculty();
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $second = $faculty->facultyProfile->expertise()->create($this->details(['expertise_area' => 'Networking']));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->post($base, $this->details())->assertSessionHasErrors('expertise_area');
        $this->patch("{$base}/{$second->id}", $this->details())->assertSessionHasErrors('expertise_area');
        $this->patch("{$base}/{$entry->id}", $this->details(['expertise_area' => 'Web Development']))->assertSessionHasNoErrors();
        $this->post('/admin/faculty/'.$this->faculty()->facultyProfile->id.'/expertise', $this->details())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_expertise', 3);
    }

    public function test_admin_submission_is_associated_only_with_the_selected_faculty_account(): void
    {
        $selectedFaculty = $this->faculty();
        $otherFaculty = $this->faculty();
        $base = '/admin/faculty/'.$selectedFaculty->facultyProfile->id.'/expertise';

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post($base, $this->details(['faculty_profile_id' => $otherFaculty->facultyProfile->id]))
            ->assertRedirect($base)
            ->assertSessionHasNoErrors();

        $entry = $selectedFaculty->facultyProfile->expertise()->sole();
        $this->assertSame($selectedFaculty->facultyProfile->id, $entry->faculty_profile_id);
        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame(0, $otherFaculty->facultyProfile->expertise()->count());
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

    public function test_editing_an_existing_entry_preserves_its_legacy_proficiency_value(): void
    {
        $faculty = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create([
            'expertise_area' => 'Web Development',
            'proficiency_score' => 85,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';

        $this->actingAs($admin)->patch("{$base}/{$entry->id}", $this->details(['expertise_area' => 'Database Systems']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Database Systems', $entry->fresh()->expertise_area);
        $this->assertSame(85, $entry->fresh()->proficiency_score);
    }

    public function test_faculty_can_view_and_update_only_their_own_skill_categories(): void
    {
        $owner = $this->faculty();
        $other = $this->faculty();
        $web = $owner->facultyProfile->expertise()->create($this->details());
        $owner->facultyProfile->expertise()->create($this->details(['expertise_area' => 'Database Systems']));
        $other->facultyProfile->expertise()->create($this->details(['expertise_area' => 'Networking']));

        $page = $this->actingAs($owner)->get('/faculty/expertise')->assertOk()
            ->assertSee('Faculty Skills')->assertSee('My Skills')
            ->assertSee('Web Development')->assertSee('Database Systems')
            ->assertSee('name="skills[]"', false)
            ->assertDontSee('technical_skills')
            ->assertDontSee('faculty_profile_id');
        $this->assertSame(9, substr_count($page->getContent(), 'name="skills[]"'));

        $this->put('/faculty/expertise', [
            'skills' => ['Web Development', 'Networking'],
            'faculty_profile_id' => $other->facultyProfile->id,
        ])->assertRedirect('/faculty/expertise')->assertSessionHasNoErrors();
        $this->assertSame(
            ['Networking', 'Web Development'],
            $owner->facultyProfile->expertise()->orderBy('expertise_area')->pluck('expertise_area')->all()
        );
        $this->assertSame($owner->facultyProfile->id, $web->fresh()->faculty_profile_id);
        $this->assertSame(['Networking'], $other->facultyProfile->expertise()->pluck('expertise_area')->all());
        $this->assertDatabaseCount('faculty_expertise', 3);

        $persisted = $this->get('/faculty/expertise')->assertOk();
        $persisted->assertSee('<li class="concept-chip">Networking</li>', false)
            ->assertSee('<li class="concept-chip">Web Development</li>', false)
            ->assertDontSee('<li class="concept-chip">Database Systems</li>', false);
    }

    public function test_faculty_cannot_save_unsupported_categories_or_specific_technical_skills(): void
    {
        $faculty = $this->faculty();
        $existing = $faculty->facultyProfile->expertise()->create($this->details(['technical_skills' => 'Legacy value']));

        $this->actingAs($faculty)->put('/faculty/expertise')->assertSessionHasErrors('skills');
        $this->actingAs($faculty)->put('/faculty/expertise', [
            'skills' => ['Web Development', 'Computer Vision'],
            'technical_skills' => 'Laravel',
        ])->assertSessionHasErrors('skills.1');

        $this->assertDatabaseCount('faculty_expertise', 1);
        $this->assertSame($existing->id, $existing->fresh()->id);
        $this->assertSame('Web Development', $existing->fresh()->expertise_area);

        $this->put('/faculty/expertise', [
            'skills' => ['Web Development'],
            'technical_skills' => 'Attempted value',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Legacy value', $existing->fresh()->technical_skills);
    }

    public function test_guests_and_other_roles_cannot_use_faculty_endpoints(): void
    {
        $faculty = $this->faculty();
        $entry = $faculty->facultyProfile->expertise()->create($this->details());
        $endpoints = [
            ['GET', '/faculty/expertise'], ['POST', '/faculty/expertise'], ['PUT', '/faculty/expertise'],
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
        $this->actingAs($admin)->get('/admin/expertise')->assertOk()->assertSee($faculty->name)->assertSee($base)->assertSee('Faculty Skills');
        $this->get($base)->assertOk()->assertSee($faculty->name);
        $this->post($base, $this->details())->assertRedirect($base)->assertSessionHasNoErrors();
        $entry = $faculty->facultyProfile->expertise()->sole();
        $this->get("{$base}/{$entry->id}/edit")->assertOk();
        $this->patch("{$base}/{$entry->id}", $this->details(['expertise_area' => 'Database Systems']))->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame('Database Systems', $entry->fresh()->expertise_area);
        $this->post($base, $this->details(['expertise_area' => 'Database Systems']))->assertSessionHasErrors('expertise_area');
        $this->patch("{$base}/{$entry->id}", $this->details(['expertise_area' => 'Unknown']))->assertSessionHasErrors('expertise_area');
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
        $this->get('/faculty/expertise')->assertOk()->assertDontSee('Add Expertise');
        $faculty = $this->faculty();
        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/faculty/'.$faculty->facultyProfile->id.'/expertise')->assertOk();
        foreach (config('expertise.areas') as $area) {
            $response->assertSee($area);
        }
        $this->assertCount(9, config('expertise.areas'));
        $this->assertSame([
            'Web Development',
            'Mobile Development',
            'Database Systems',
            'Networking',
            'Cybersecurity',
            'Machine Learning / Data Analytics',
            'Software Engineering',
            'Systems Analysis',
            'Internet of Things',
        ], config('expertise.areas'));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/dashboard')
            ->assertSee(route('admin.expertise.faculty'));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get('/student/dashboard')
            ->assertDontSee(route('faculty.expertise.index'))->assertDontSee(route('admin.expertise.faculty'));
    }
}
