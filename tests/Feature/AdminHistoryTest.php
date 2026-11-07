<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const BSIT = 'BS Information Technology';

    private const BSIS = 'BS Information Systems';

    private function student(string $name, string $course, string $title): array
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => $name]);
        $profile = $user->studentProfile()->create(['student_number' => 'H-'.$user->id, 'course' => $course, 'year_level' => 3, 'section' => 'A']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => $title, 'file_path' => 'h-'.$user->id.'.pdf', 'original_filename' => 'p.pdf', 'file_type' => 'pdf', 'status' => 'uploaded'])->save();

        return [$user, $profile, $proposal];
    }

    public function test_only_admins_can_open_the_history_page(): void
    {
        $this->get('/admin/history')->assertRedirect(route('login'));
        foreach ([User::ROLE_STUDENT, User::ROLE_FACULTY] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/history')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/history')->assertOk()->assertSee('History');
    }

    public function test_proposals_and_students_are_separated_by_course(): void
    {
        $this->student('Ana Tech', self::BSIT, 'Alpha Network Study');
        $this->student('Ben Systems', self::BSIS, 'Beta Records Study');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->get('/admin/history')->assertOk()->assertSee('Alpha Network Study')->assertSee('Beta Records Study');
        $this->get(route('admin.history.index', ['course' => self::BSIT]))->assertOk()
            ->assertSee('Alpha Network Study')->assertDontSee('Beta Records Study');
        $this->get(route('admin.history.index', ['course' => self::BSIS]))->assertOk()
            ->assertSee('Beta Records Study')->assertDontSee('Alpha Network Study');

        $this->get(route('admin.history.index', ['tab' => 'students', 'course' => self::BSIS]))->assertOk()
            ->assertSee('Ben Systems')->assertDontSee('Ana Tech');
        $this->get(route('admin.history.index', ['tab' => 'students', 'course' => self::BSIT]))->assertOk()
            ->assertSee('Ana Tech')->assertDontSee('Ben Systems');
    }

    public function test_unknown_course_is_ignored_instead_of_hiding_everything(): void
    {
        $this->student('Ana Tech', self::BSIT, 'Alpha Network Study');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('admin.history.index', ['tab' => 'students', 'course' => 'Nonsense']))
            ->assertOk()->assertSee('Ana Tech');
    }

    public function test_faculty_tab_counts_active_advisees_per_course(): void
    {
        [, , $itProposal] = $this->student('Ana Tech', self::BSIT, 'Alpha Network Study');
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => 'Prof Advisor']);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'Computing']);
        $assignment = new AdviserAssignment;
        $assignment->forceFill(['faculty_profile_id' => $facultyProfile->id, 'research_proposal_id' => $itProposal->id, 'status' => 'active', 'assigned_at' => now()])->save();

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('admin.history.index', ['tab' => 'faculty']))
            ->assertOk()->assertSee('Prof Advisor')->assertSee('BSIT advisees')->assertSee('BSIS advisees');

        $row = $response->viewData('items')->first();
        $this->assertSame(1, $row->course_0_count);
        $this->assertSame(0, $row->course_1_count);
        $this->assertSame(1, $row->active_assignments_count);
    }
}