<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FacultyExpertiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_navigation_links_and_canonical_areas_are_available(): void
    {
        $this->actingAs($this->faculty())->get('/faculty/dashboard')->assertSee(route('faculty.expertise.index'));
        $this->get('/faculty/expertise')->assertOk()->assertDontSee('Add Expertise');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $faculty = $this->faculty();
        $base = '/admin/faculty/'.$faculty->facultyProfile->id.'/expertise';
        $this->actingAs($admin)->get('/admin/expertise')->assertNotFound();
        $this->get($base)->assertNotFound();
        $this->post($base, $this->details())->assertNotFound();
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('/admin/expertise');

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
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]))->get('/student/dashboard')
            ->assertDontSee(route('faculty.expertise.index'));
    }
}
