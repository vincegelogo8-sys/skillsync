<?php

namespace Tests\Feature;

use App\Models\FacultyCompetency;
use App\Models\User;
use App\Services\CompetencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacultyCompetencyTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(): User
    {
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $faculty->facultyProfile()->create(['department' => 'Computing']);

        return $faculty;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function details(array $overrides = []): array
    {
        return array_replace([
            'system_analysis' => 4, 'research_methodology' => 4,
            'research_documentation' => 5, 'data_analysis' => 3,
            'system_evaluation' => 4, 'remarks' => 'Reviewed completed research documentation.',
        ], $overrides);
    }

    private function url(User $faculty): string
    {
        return '/admin/faculty/'.$faculty->facultyProfile->id.'/competency';
    }

    public function test_admin_can_evaluate_update_and_view_the_normalized_score(): void
    {
        $faculty = $this->faculty();
        $admin = $this->admin();
        $url = $this->url($faculty);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Not evaluated')->assertDontSee('value="4" selected', false);
        $this->assertDatabaseCount('faculty_competencies', 0);
        $this->patch($url, $this->details())->assertSessionHasNoErrors()->assertRedirect($url)->assertSessionHas('status', 'competency-saved');
        $evaluation = $faculty->facultyProfile->competency()->sole();
        $this->assertSame($admin->id, $evaluation->evaluated_by);
        $this->assertTrue($evaluation->facultyProfile->is($faculty->facultyProfile));
        $this->assertTrue($evaluation->evaluator->is($admin));
        $this->get($url)->assertOk()->assertSee('80.00%')->assertSee('20/25')->assertSee($admin->name)
            ->assertSee('Competency evaluation saved successfully.')->assertSee('value="4" selected', false);
        $this->get('/admin/competencies')->assertSee('80.00%');
        $secondAdmin = $this->admin();
        $this->actingAs($secondAdmin)->patch($url, $this->details(['data_analysis' => 5, 'remarks' => 'Updated evidence.']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_competencies', 1);
        $this->assertSame($evaluation->id, $evaluation->fresh()->id);
        $this->assertSame($secondAdmin->id, $evaluation->fresh()->evaluated_by);
        $this->get($url)->assertSee('88.00%')->assertSee('Updated evidence.');
    }

    public static function scores(): array
    {
        return [[1, 20.0], [3, 60.0], [5, 100.0]];
    }

    #[DataProvider('scores')]
    public function test_scores_are_normalized_without_applying_the_future_30_percent_weight(int $rating, float $expected): void
    {
        $faculty = $this->faculty();
        $this->actingAs($this->admin())->patch($this->url($faculty), array_fill_keys(array_keys(FacultyCompetency::DIMENSIONS), $rating))
            ->assertSessionHasNoErrors();
        $this->assertSame($expected, app(CompetencyService::class)->score($faculty->facultyProfile->competency()->sole()));
        $this->assertNull(app(CompetencyService::class)->score(null));
    }

    public static function invalidRatings(): array
    {
        return [[''], [null], [0], [6], [-1], [2.5], ['Advanced'], [[4]]];
    }

    #[DataProvider('invalidRatings')]
    public function test_every_dimension_requires_an_integer_between_one_and_five(mixed $value): void
    {
        $faculty = $this->faculty();
        $url = $this->url($faculty);
        $this->actingAs($this->admin())->patch($url, $this->details())->assertSessionHasNoErrors();
        $before = $faculty->facultyProfile->competency()->sole()->toArray();
        foreach (array_keys(FacultyCompetency::DIMENSIONS) as $field) {
            $this->from($url)->patch($url, $this->details([$field => $value]))->assertSessionHasErrors($field)->assertRedirect($url);
            $this->get($url)->assertOk();
            $this->assertSame($before, $faculty->facultyProfile->competency()->sole()->toArray());
        }
    }

    public function test_incomplete_ratings_create_no_evaluation_and_remarks_are_validated(): void
    {
        $faculty = $this->faculty();
        $url = $this->url($faculty);
        $this->actingAs($this->admin())->patch($url, ['remarks' => 'Evidence only'])
            ->assertSessionHasErrors(array_keys(FacultyCompetency::DIMENSIONS));
        $this->patch($url, $this->details(['remarks' => str_repeat('x', 5001)]))->assertSessionHasErrors('remarks');
        $this->patch($url, $this->details(['remarks' => ['invalid']]))->assertSessionHasErrors('remarks');
        $this->assertDatabaseCount('faculty_competencies', 0);
        $this->patch($url, $this->details(['remarks' => null]))->assertSessionHasNoErrors();
        $this->assertNull($faculty->facultyProfile->competency()->sole()->remarks);
    }

    public function test_guests_students_and_faculty_cannot_read_or_edit_admin_evaluations(): void
    {
        $faculty = $this->faculty();
        $url = $this->url($faculty);
        foreach (['/admin/competencies', $url] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->patch($url, $this->details())->assertRedirect('/login');
        foreach ([$faculty, User::factory()->create(['role' => User::ROLE_STUDENT])] as $user) {
            $this->actingAs($user)->get('/admin/competencies')->assertForbidden();
            $this->get($url)->assertForbidden();
            $this->patch($url, $this->details())->assertForbidden();
            $this->get(route($user->role.'.dashboard'))->assertDontSee(route('admin.competencies.index'));
        }
        $this->assertDatabaseCount('faculty_competencies', 0);
    }

    public function test_forged_ownership_evaluator_and_score_are_ignored(): void
    {
        $faculty = $this->faculty();
        $other = $this->faculty();
        $admin = $this->admin();
        $this->actingAs($admin)->patch($this->url($faculty), $this->details([
            'faculty_profile_id' => $other->facultyProfile->id, 'evaluated_by' => $other->id,
            'score' => 100, 'advisory_limit' => 99, 'role' => 'admin',
        ]))->assertSessionHasNoErrors();
        $evaluation = $faculty->facultyProfile->competency()->sole();
        $this->assertSame($admin->id, $evaluation->evaluated_by);
        $this->assertSame(80.0, app(CompetencyService::class)->score($evaluation));
        $this->assertNull($other->facultyProfile->competency);
        $this->assertSame(5, $faculty->facultyProfile->fresh()->advisory_limit);
        $this->assertSame(User::ROLE_FACULTY, $faculty->fresh()->role);
    }

    public function test_list_handles_missing_profiles_unevaluated_faculty_and_pagination(): void
    {
        $faculty = $this->faculty();
        $incomplete = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($this->admin())->get('/admin/competencies')->assertOk()
            ->assertSee($faculty->email)->assertSee($incomplete->email)->assertDontSee($student->email)
            ->assertSee('Not evaluated')->assertSee('Faculty must complete their basic profile first.');
        $this->get('/admin/dashboard')->assertSee(route('admin.competencies.index'));
        User::factory()->count(20)->create(['role' => User::ROLE_FACULTY]);
        $this->get('/admin/competencies')->assertViewHas('faculty', fn ($rows) => $rows->count() === 20 && $rows->total() === 22);
        $this->get('/admin/competencies?page=2')->assertOk()->assertViewHas('faculty', fn ($rows) => $rows->count() === 2);
        $this->assertDatabaseCount('faculty_competencies', 0);
    }

    public function test_missing_and_nonfaculty_profiles_cannot_be_evaluated(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->facultyProfile()->create(['department' => 'Computing']);
        $this->actingAs($this->admin())->get('/admin/faculty/999999/competency')->assertNotFound();
        $this->patch('/admin/faculty/999999/competency', $this->details())->assertNotFound();
        $this->get('/admin/faculty/'.$profile->id.'/competency')->assertForbidden();
        $this->patch('/admin/faculty/'.$profile->id.'/competency', $this->details())->assertForbidden();
        $this->assertDatabaseCount('faculty_competencies', 0);
    }

    public function test_remarks_are_escaped_and_can_be_cleared(): void
    {
        $faculty = $this->faculty();
        $url = $this->url($faculty);
        $remarks = '<script>alert("unsafe")</script>';
        $this->actingAs($this->admin())->patch($url, $this->details(['remarks' => $remarks]))->assertSessionHasNoErrors();
        $this->get($url)->assertSee($remarks)->assertDontSee($remarks, false);
        $this->patch($url, $this->details(['remarks' => '']))->assertSessionHasNoErrors();
        $this->assertNull($faculty->facultyProfile->competency()->sole()->remarks);
    }

    public function test_evaluator_deletion_preserves_ratings_and_profile_deletion_removes_them(): void
    {
        $faculty = $this->faculty();
        $admin = $this->admin();
        $this->actingAs($admin)->patch($this->url($faculty), $this->details())->assertSessionHasNoErrors();
        $admin->delete();
        $evaluation = $faculty->facultyProfile->competency()->sole();
        $this->assertNull($evaluation->evaluated_by);
        $this->assertSame(80.0, app(CompetencyService::class)->score($evaluation));
        $this->actingAs($this->admin())->get($this->url($faculty))->assertOk()->assertSee('Deleted account');
        $faculty->facultyProfile->delete();
        $this->assertDatabaseCount('faculty_competencies', 0);
    }

    public function test_database_allows_only_one_evaluation_per_faculty(): void
    {
        $faculty = $this->faculty();
        $this->actingAs($this->admin())->patch($this->url($faculty), $this->details())->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        $faculty->facultyProfile->competency()->create($this->details());
    }

    public function test_evaluation_does_not_modify_expertise_preferences_or_profile(): void
    {
        $faculty = $this->faculty();
        $expertise = $faculty->facultyProfile->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $preference = $faculty->facultyProfile->preferences()->create(['preference_type' => 'technology', 'preference_value' => 'Laravel']);
        $before = [$expertise->fresh()->toArray(), $preference->fresh()->toArray(), $faculty->facultyProfile->fresh()->toArray()];
        $this->actingAs($this->admin())->patch($this->url($faculty), $this->details())->assertSessionHasNoErrors();
        $this->assertSame($before, [$expertise->fresh()->toArray(), $preference->fresh()->toArray(), $faculty->facultyProfile->fresh()->toArray()]);
    }
}
