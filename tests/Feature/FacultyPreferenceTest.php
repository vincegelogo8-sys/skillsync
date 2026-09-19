<?php

namespace Tests\Feature;

use App\Models\FacultyPreference;
use App\Models\User;
use App\Services\FacultyPreferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FacultyPreferenceTest extends TestCase
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
        return array_replace([
            'project_types' => ['Web-Based System', 'Recommendation System'],
            'technologies' => ['Laravel', 'PHP', 'MySQL'],
        ], $overrides);
    }

    public function test_faculty_can_save_multiple_preferences_and_reload_their_selections(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->get('/faculty/preferences')->assertOk()->assertSee('Preferred Project Types')
            ->assertSee('Preferred Technologies')->assertDontSee('checked', false);
        $this->assertDatabaseCount('faculty_preferences', 0);
        $this->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors()
            ->assertRedirect('/faculty/preferences')->assertSessionHas('status', 'faculty-preferences-updated');
        $this->assertDatabaseCount('faculty_preferences', 5);
        $entry = $faculty->facultyProfile->preferences()->where('preference_value', 'Laravel')->sole();
        $this->assertSame('technology', $entry->preference_type);
        $this->assertTrue($entry->facultyProfile->is($faculty->facultyProfile));
        $this->get('/faculty/preferences')->assertOk()->assertSee('Faculty preferences saved successfully.')
            ->assertSee('value="Laravel" checked', false)->assertSee('value="Web-Based System" checked', false)
            ->assertDontSee('value="Python" checked', false);
    }

    public function test_repeat_saves_preserve_rows_and_changes_remove_only_unchecked_values(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $original = $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray();
        $this->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $this->assertSame($original, $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray());
        $this->patch('/faculty/preferences', [
            'project_types' => ['Web-Based System'], 'technologies' => ['Laravel', 'Python'],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_preferences', 3);
        $this->assertDatabaseMissing('faculty_preferences', ['preference_value' => 'Recommendation System']);
        $this->assertDatabaseMissing('faculty_preferences', ['preference_value' => 'PHP']);
        $this->assertDatabaseHas('faculty_preferences', ['preference_value' => 'Python', 'preference_type' => 'technology']);
    }

    public function test_unchecked_groups_and_all_empty_selections_can_be_saved(): void
    {
        $this->actingAs($this->faculty())->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $this->patch('/faculty/preferences', ['technologies' => ['C++', 'C#', '.NET', 'Node.js']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_preferences', 4);
        $this->assertDatabaseMissing('faculty_preferences', ['preference_type' => 'project_type']);
        $this->patch('/faculty/preferences', ['project_types' => ['AR/VR']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_preferences', 1);
        $this->assertDatabaseMissing('faculty_preferences', ['preference_type' => 'technology']);
        $this->patch('/faculty/preferences', [])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_preferences', 0);
        $this->get('/faculty/preferences')->assertOk()->assertDontSee('checked', false);
    }

    public function test_all_configured_choices_can_be_saved(): void
    {
        $data = ['project_types' => config('preferences.project_types'), 'technologies' => config('preferences.technologies')];
        $this->actingAs($this->faculty())->patch('/faculty/preferences', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_preferences', count($data['project_types']) + count($data['technologies']));
    }

    public static function invalidPreferences(): array
    {
        return [
            'scalar projects' => [['project_types' => 'Web-Based System'], 'project_types'],
            'null projects' => [['project_types' => null], 'project_types'],
            'unknown project' => [['project_types' => ['Research Domain']], 'project_types.0'],
            'wrong case' => [['project_types' => ['web-based system']], 'project_types.0'],
            'duplicate projects' => [['project_types' => ['GIS', 'GIS']], 'project_types.0'],
            'nested projects' => [['project_types' => [['GIS']]], 'project_types.0'],
            'associative projects' => [['project_types' => ['unexpected' => 'GIS']], 'project_types'],
            'too many projects' => [['project_types' => array_fill(0, 19, 'GIS')], 'project_types'],
            'scalar technologies' => [['technologies' => 'Laravel'], 'technologies'],
            'null technologies' => [['technologies' => null], 'technologies'],
            'unknown technology' => [['technologies' => ['Unlisted Technology']], 'technologies.0'],
            'duplicate technologies' => [['technologies' => ['PHP', 'PHP']], 'technologies.0'],
            'nested technologies' => [['technologies' => [['PHP']]], 'technologies.0'],
            'associative technologies' => [['technologies' => ['unexpected' => 'PHP']], 'technologies'],
            'number technology' => [['technologies' => [123]], 'technologies.0'],
            'empty technology' => [['technologies' => ['']], 'technologies.0'],
            'crossed categories' => [['technologies' => ['Web-Based System']], 'technologies.0'],
        ];
    }

    #[DataProvider('invalidPreferences')]
    public function test_invalid_input_preserves_both_saved_lists(array $invalid, string $field): void
    {
        $faculty = $this->faculty();
        $this->actingAs($faculty)->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $before = $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray();
        $this->from('/faculty/preferences')->patch('/faculty/preferences', $this->details($invalid))
            ->assertSessionHasErrors($field)->assertRedirect('/faculty/preferences');
        $this->get('/faculty/preferences')->assertOk()->assertSee('Your preferences were not saved.');
        $this->assertSame($before, $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray());
    }

    public function test_failed_validation_preserves_valid_choices_and_an_unchecked_group_in_the_form(): void
    {
        $this->actingAs($this->faculty())->patch('/faculty/preferences', $this->details());
        $this->from('/faculty/preferences')->patch('/faculty/preferences', ['technologies' => ['Python', 'Unknown']])
            ->assertSessionHasErrors('technologies.1');
        $this->get('/faculty/preferences')->assertSee('value="Python" checked', false)
            ->assertDontSee('value="Web-Based System" checked', false)->assertDontSee('value="Laravel" checked', false);
        $this->assertDatabaseCount('faculty_preferences', 5);
    }

    public function test_guests_students_and_admin_cannot_read_or_save_faculty_preferences(): void
    {
        $this->get('/faculty/preferences')->assertRedirect('/login');
        $this->patch('/faculty/preferences', $this->details())->assertRedirect('/login');
        foreach ([User::ROLE_STUDENT, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/faculty/preferences')->assertForbidden();
            $this->patch('/faculty/preferences', $this->details())->assertForbidden();
            $this->get(route($role.'.dashboard'))->assertDontSee(route('faculty.preferences.index'));
        }
        $this->assertDatabaseCount('faculty_preferences', 0);
    }

    public function test_profile_is_required_without_creating_blank_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))
            ->get('/faculty/preferences')->assertRedirect('/faculty/profile')
            ->assertSessionHas('status', 'complete-profile-for-preferences');
        $this->get('/faculty/profile')->assertSee('Save your full name and department before selecting preferences.');
        $this->patch('/faculty/preferences', $this->details())->assertRedirect('/faculty/profile');
        $this->assertDatabaseCount('faculty_profiles', 0);
        $this->assertDatabaseCount('faculty_preferences', 0);
    }

    public function test_ownership_is_derived_from_login_and_other_faculty_data_stays_private(): void
    {
        $owner = $this->faculty();
        $other = $this->faculty();
        $this->actingAs($owner)->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $this->actingAs($other)->get('/faculty/preferences?faculty_profile_id='.$owner->facultyProfile->id)
            ->assertOk()->assertDontSee('value="Laravel" checked', false);
        $this->patch('/faculty/preferences', $this->details([
            'project_types' => ['GIS'], 'technologies' => ['Python'],
            'faculty_profile_id' => $owner->facultyProfile->id, 'user_id' => $owner->id,
            'preference_type' => 'research_methodology', 'advisory_limit' => 999, 'role' => 'admin',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(5, $owner->facultyProfile->preferences()->count());
        $this->assertSame(2, $other->facultyProfile->preferences()->count());
        $this->assertDatabaseHas('faculty_preferences', ['faculty_profile_id' => $other->facultyProfile->id, 'preference_value' => 'Python']);
        $this->assertDatabaseMissing('faculty_preferences', ['preference_type' => 'research_methodology']);
        $this->patch('/faculty/preferences', [])->assertSessionHasNoErrors();
        $this->assertSame(5, $owner->facultyProfile->preferences()->count());
        $this->assertSame(5, $other->facultyProfile->fresh()->advisory_limit);
        $this->assertSame(User::ROLE_FACULTY, $other->fresh()->role);
    }

    public function test_saves_do_not_change_expertise_or_profile_data(): void
    {
        $faculty = $this->faculty();
        $expertise = $faculty->facultyProfile->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $before = $expertise->fresh()->toArray();
        $this->actingAs($faculty)->patch('/faculty/preferences', $this->details())->assertSessionHasNoErrors();
        $this->patch('/faculty/preferences', [])->assertSessionHasNoErrors();
        $this->assertSame($before, $expertise->fresh()->toArray());
        $this->assertSame('Computing', $faculty->facultyProfile->fresh()->department);
        $this->assertSame(5, $faculty->facultyProfile->fresh()->advisory_limit);
    }

    public function test_a_failed_save_rolls_back_changes_to_both_lists(): void
    {
        $faculty = $this->faculty();
        $service = app(FacultyPreferenceService::class);
        $service->save($faculty->facultyProfile, $this->details());
        $before = $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray();
        Event::listen('eloquent.creating: '.FacultyPreference::class, function (FacultyPreference $preference) {
            if ($preference->preference_value === 'Python') {
                throw new RuntimeException('Simulated preference write failure');
            }
        });
        try {
            $service->save($faculty->facultyProfile, ['project_types' => ['GIS'], 'technologies' => ['Python']]);
            $this->fail('Expected a simulated write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated preference write failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.FacultyPreference::class);
        }
        $this->assertSame($before, $faculty->facultyProfile->preferences()->orderBy('id')->get()->toArray());
    }

    public function test_database_rejects_duplicate_rows(): void
    {
        $faculty = $this->faculty();
        $values = ['preference_type' => 'technology', 'preference_value' => 'Laravel'];
        $faculty->facultyProfile->preferences()->create($values);
        $this->expectException(QueryException::class);
        $faculty->facultyProfile->preferences()->create($values);
    }

    public function test_deleting_a_profile_cascades_only_its_preferences(): void
    {
        $owner = $this->faculty();
        $other = $this->faculty();
        $service = app(FacultyPreferenceService::class);
        $service->save($owner->facultyProfile, $this->details());
        $service->save($other->facultyProfile, $this->details());
        $owner->facultyProfile->delete();
        $this->assertDatabaseCount('faculty_preferences', 5);
        $this->assertSame(5, $other->facultyProfile->preferences()->count());
    }

    public function test_faculty_can_find_preferences_from_dashboard_and_profile(): void
    {
        $this->actingAs($this->faculty())->get('/faculty/dashboard')->assertSee(route('faculty.preferences.index'));
        $this->get('/faculty/profile')->assertSee(route('faculty.preferences.index'));
    }
}
