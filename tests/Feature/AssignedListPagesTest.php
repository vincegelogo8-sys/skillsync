<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssignedListPagesTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $name, int $limit = 5): FacultyProfile
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_FACULTY]);
        $profile = $user->facultyProfile()->create(['department' => 'Computing']);
        $profile->forceFill(['advisory_limit' => $limit])->save();

        return $profile;
    }

    private function proposal(
        string $studentName,
        string $course,
        string $section,
        string $title,
        ?FacultyProfile $faculty = null,
        string $status = 'active',
        ?string $assignedAt = null
    ): ResearchProposal {
        $student = User::factory()->create(['name' => $studentName, 'role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create([
            'student_number' => 'S-'.$student->id,
            'course' => $course,
            'year_level' => 3,
            'section' => $section,
        ]);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill([
            'title' => $title,
            'file_path' => 'proposal-'.$student->id.'.pdf',
            'original_filename' => 'proposal.pdf',
            'file_type' => 'pdf',
            'status' => 'analyzed',
        ])->save();
        if ($faculty) {
            AdviserAssignment::forceCreate([
                'research_proposal_id' => $proposal->id,
                'faculty_profile_id' => $faculty->id,
                'assigned_at' => $assignedAt ?? now(),
                'status' => $status,
            ]);
        }

        return $proposal;
    }

    public function test_admin_assignment_grouping_includes_empty_advisers_and_displays_active_load(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $assignedFaculty = $this->faculty('Prof. Zoe Zulu', 1);
        $emptyFaculty = $this->faculty('Prof. Amy Alpha');
        $this->proposal('Student Example', 'BS Information Technology', 'A', 'Topic', $assignedFaculty);

        $response = $this->actingAs($admin)->get('/admin/assignments')->assertOk()
            ->assertSee('Grouped by adviser')->assertSee('Prof. Zoe Zulu')->assertSee('Prof. Amy Alpha')
            ->assertSee('Computing')->assertSee('1 of 1 students')->assertSee('No students assigned yet.')
            ->assertSee('Student No.')->assertSee('Research topic')->assertSee('View proposal');
        $response->assertSee('class="table-readable w-full text-left text-sm"', false)
            ->assertSee('class="cell-nowrap px-3 py-2"', false);
        $response->assertDontSee('FULL')->assertDontSee('adviser-progress');
        $this->assertSame(2, $response->viewData('advisers')->total());
    }

    public function test_admin_grouped_advisers_are_closed_by_default_and_open_for_student_filters(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $assignedFaculty = $this->faculty('Prof. Filtered', 5);
        $emptyFaculty = $this->faculty('Prof. Empty');
        $course = 'BS Information Technology';
        $this->proposal('Student One', $course, 'A', 'Unique Topic One', $assignedFaculty);
        $this->proposal('Student Two', $course, 'B', 'Another Topic', $assignedFaculty);

        $response = $this->actingAs($admin)->get('/admin/assignments')->assertOk()
            ->assertSee('Prof. Filtered')->assertSee('Computing')->assertSee('2 of 5 students')
            ->assertSee('Student One')->assertSee('Unique Topic One')
            ->assertSee('data-accordion-expand-all', false)->assertSee('data-accordion-collapse-all', false);
        $html = $response->getContent();
        preg_match_all('/<details class="adviser-group py-5"[^>]*>/', $html, $details);
        $this->assertCount(1, $details[0]);
        $this->assertStringNotContainsString(' open', $details[0][0]);
        $this->assertStringContainsString('class="adviser-summary adviser-summary-empty"', $html);
        $this->assertStringContainsString('No students assigned yet.', $html);

        foreach ([
            '/admin/assignments?course=BS%20Information%20Technology',
            '/admin/assignments?course=BS%20Information%20Technology&section=A',
            '/admin/assignments?q=Unique%20Topic%20One',
        ] as $url) {
            $filtered = $this->get($url)->assertOk();
            $this->assertMatchesRegularExpression('/<details\b(?=[^>]*\bopen(?:\s|=|>))[^>]*>/', $filtered->getContent(), $url);
        }
    }

    public function test_admin_grouped_view_hides_expand_controls_when_no_adviser_has_students(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->faculty('Prof. Without Students');

        $this->actingAs($admin)->get('/admin/assignments')->assertOk()
            ->assertSee('No students assigned yet.')
            ->assertDontSee('data-accordion-expand-all', false)
            ->assertDontSee('data-accordion-collapse-all', false)
            ->assertDontSee('<details class="adviser-group', false);
    }

    public function test_admin_assignment_filters_search_and_group_sorts_only_matching_advisers(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $facultyZ = $this->faculty('Prof. Zoe Zulu');
        $facultyA = $this->faculty('Prof. Amy Alpha', 5);
        $this->proposal('Alice Alpha', 'BS Information Technology', 'A', 'Alpha Topic', $facultyZ);
        $this->proposal('Alyssa Another', 'BS Information Technology', 'A', 'Another Topic', $facultyZ);
        $this->proposal('Bob Beta', 'BS Information Systems', 'B', 'Beta Topic', $facultyA);

        $this->actingAs($admin)->get('/admin/assignments?course=BS%20Information%20Technology&section=A')
            ->assertOk()->assertSee('Prof. Zoe Zulu')->assertSee('Alice Alpha')->assertDontSee('Prof. Amy Alpha')->assertDontSee('Bob Beta');
        $this->get('/admin/assignments?q=Beta')->assertOk()->assertSee('Prof. Amy Alpha')->assertSee('Bob Beta')->assertDontSee('Prof. Zoe Zulu');
        $this->get('/admin/assignments?sort=students&dir=desc')->assertOk()->assertSeeInOrder(['Prof. Zoe Zulu', 'Prof. Amy Alpha']);
        $this->get('/admin/assignments?sort=capacity&dir=asc')->assertOk()->assertSeeInOrder(['Prof. Zoe Zulu', 'Prof. Amy Alpha']);
        $this->get('/admin/assignments?sort=invalid')->assertOk()->assertSeeInOrder(['Prof. Amy Alpha', 'Prof. Zoe Zulu']);
        $this->get('/admin/assignments?view=list&sort=student&dir=asc')->assertOk()->assertSeeInOrder(['Alice Alpha', 'Bob Beta']);
        $this->get('/admin/assignments?view=list&sort=course&dir=asc')->assertOk()->assertSee('BS Information Technology');
        $this->get('/admin/assignments?view=list&q=Another')->assertOk()->assertSee('Another Topic')->assertDontSee('Beta Topic');
    }

    public function test_admin_group_pagination_keeps_filters_and_is_by_adviser(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        for ($i = 1; $i <= 16; $i++) {
            $faculty = $this->faculty('Faculty '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->proposal('Student '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'BS Information Technology', 'A', 'Topic '.$i, $faculty);
        }

        $response = $this->actingAs($admin)->get('/admin/assignments?course=BS%20Information%20Technology&section=A&view=grouped&sort=adviser&dir=asc')
            ->assertOk()->assertSee('Faculty 01')->assertDontSee('Faculty 16');
        $this->assertSame(16, $response->viewData('advisers')->total());
        $this->get('/admin/assignments?course=BS%20Information%20Technology&section=A&view=grouped&sort=adviser&dir=asc&page=2')
            ->assertOk()->assertSee('Faculty 16')->assertDontSee('Faculty 01');
    }

    public function test_faculty_assignments_are_scoped_and_can_be_filtered_sorted_and_grouped(): void
    {
        $owner = $this->faculty('Faculty Owner');
        $other = $this->faculty('Faculty Other');
        $ownOne = $this->proposal('Zoe Student', 'BS Information Technology', 'B', 'Zebra Topic', $owner);
        $ownTwo = $this->proposal('Amy Student', 'BS Information Technology', 'A', 'Apple Topic', $owner);
        $this->proposal('Other Student', 'BS Information Systems', 'A', 'Other Topic', $other);

        $response = $this->actingAs($owner->user)->get('/faculty/assignments?course=BS%20Information%20Technology')
            ->assertOk()->assertSee('2 of 5 students')->assertSee('Zoe Student')->assertSee('Amy Student')->assertDontSee('Other Student')
            ->assertSee('BS Information Technology (2)');
        $this->assertSame(2, $response->viewData('assignments')->total());
        $this->get('/faculty/assignments?sort=student&dir=asc')->assertOk()->assertSeeInOrder(['Amy Student', 'Zoe Student']);
        $this->get('/faculty/assignments?view=sections&section=A&course=BS%20Information%20Technology')
            ->assertOk()->assertSee('Section A')->assertSee('Amy Student')->assertDontSee('Zoe Student')->assertDontSee('Other Student');
        $this->get('/faculty/assignments?view=list&sort=topic&dir=asc')->assertOk()->assertSeeInOrder(['Apple Topic', 'Zebra Topic']);
    }

    public function test_faculty_can_open_only_their_own_active_assignment_proposal(): void
    {
        Storage::fake('proposals');
        $owner = $this->faculty('Faculty Owner');
        $other = $this->faculty('Faculty Other');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $studentProfile = $student->studentProfile()->create(['student_number' => 'S-1', 'course' => 'BS Information Technology', 'year_level' => 3, 'section' => 'A']);
        $proposal = $studentProfile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Document', 'file_path' => 'active.pdf', 'original_filename' => 'active.pdf', 'file_type' => 'pdf', 'status' => 'uploaded'])->save();
        $proposal->analysis()->make()->forceFill([
            'extracted_text' => 'Text available to the assigned adviser.',
        ])->save();
        Storage::disk('proposals')->put('active.pdf', 'contents');
        $assignment = AdviserAssignment::forceCreate(['research_proposal_id' => $proposal->id, 'faculty_profile_id' => $owner->id, 'assigned_at' => now(), 'status' => 'active']);

        $response = $this->actingAs($owner->user)->get(route('faculty.assignments.proposal', $assignment))
            ->assertOk()->assertSee('Document')->assertSee($student->name)
            ->assertSee('Text available to the assigned adviser.')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertNull($response->headers->get('Content-Disposition'));
        $response->assertDontSee('active.pdf')->assertDontSee('<iframe', false)->assertDontSee('<embed', false)->assertDontSee('<object', false);
        $this->actingAs($other->user)->get(route('faculty.assignments.proposal', $assignment))->assertNotFound();
        $this->actingAs($admin)->get(route('faculty.assignments.proposal', $assignment))->assertForbidden();
        $this->actingAs($student)->get(route('faculty.assignments.proposal', $assignment))->assertForbidden();
        $assignment->forceFill(['status' => 'completed'])->save();
        $this->actingAs($owner->user)->get(route('faculty.assignments.proposal', $assignment))->assertNotFound();
    }

    public function test_admin_proposals_show_filters_and_sorts_without_changing_student_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $facultyZ = $this->faculty('Prof. Zoe Zulu');
        $facultyA = $this->faculty('Prof. Amy Alpha');
        $assigned = $this->proposal('Zoe Student', 'BS Information Technology', 'B', 'Zebra Topic', $facultyZ);
        $unassigned = $this->proposal('Amy Student', 'BS Information Systems', 'A', 'Apple Topic');
        $this->proposal('Other Student', 'BS Information Technology', 'A', 'Other Topic', $facultyA);

        $this->actingAs($admin)->get('/admin/proposals')->assertOk()
            ->assertSee('BS Information Technology')->assertSee('Section')
            ->assertSee('Prof. Zoe Zulu')->assertSee('No adviser yet');
        $this->get('/admin/proposals?course=BS%20Information%20Technology&section=B')
            ->assertOk()->assertSee('Zoe Student')->assertDontSee('Amy Student');
        $this->get('/admin/proposals?adviser='.$facultyZ->id)->assertOk()->assertSee('Zebra Topic')->assertDontSee('Apple Topic');
        $this->get('/admin/proposals?adviser=none')->assertOk()->assertSee('Apple Topic')->assertDontSee('Zebra Topic');
        $this->get('/admin/proposals?adviser=999999')->assertOk()->assertSee('Zebra Topic')->assertSee('Apple Topic');
        $this->get('/admin/proposals?course=invalid&section=D&sort=bad&dir=bad')->assertOk()->assertSee('Zebra Topic')->assertSee('Apple Topic');
        $this->get('/admin/proposals?course=BS%20Information%20Technology&section=D')->assertOk()->assertSee('Zebra Topic')->assertSee('Other Topic');
        $this->get('/admin/proposals?sort=course&dir=asc')->assertOk()->assertSeeInOrder(['BS Information Systems', 'BS Information Technology']);
        $this->get('/admin/proposals?sort=student&dir=asc')->assertOk()->assertSeeInOrder(['Amy Student', 'Other Student', 'Zoe Student']);
        $this->get('/admin/proposals?sort=adviser&dir=desc')->assertOk()->assertSeeInOrder(['Prof. Zoe Zulu', 'Prof. Amy Alpha', 'No adviser yet']);
        $this->get('/admin/proposals?sort=title&dir=asc')->assertOk()->assertSeeInOrder(['Apple Topic', 'Other Topic', 'Zebra Topic']);
        for ($i = 1; $i <= 16; $i++) {
            $this->proposal('Unassigned '.$i, 'BS Information Technology', 'A', 'Unassigned '.$i);
        }
        $pageTwo = $this->get('/admin/proposals?course=BS%20Information%20Technology&adviser=none&sort=status&dir=desc&page=2')
            ->assertOk()->assertSee('course=BS%20Information%20Technology')->assertSee('adviser=none')->assertSee('sort=status');
        $this->assertSame(16, $pageTwo->viewData('proposals')->total());

        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student->studentProfile()->create(['student_number' => 'S-1', 'course' => 'BS Information Technology', 'year_level' => 3, 'section' => 'A']);
        $this->actingAs($student)->get('/student/proposals?course=BS%20Information%20Technology&sort=title')
            ->assertOk()->assertDontSee('Sort by')->assertDontSee('No adviser yet');
        $this->actingAs($student)->get('/admin/proposals')->assertForbidden();
        $this->actingAs($facultyZ->user)->get('/admin/proposals')->assertForbidden();
    }

    public function test_dashboard_unassigned_metric_links_to_the_none_adviser_filter(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $unassigned = $this->proposal('Unassigned Student', 'BS Information Technology', 'A', 'Unassigned Topic');
        $assigned = $this->proposal('Assigned Student', 'BS Information Technology', 'B', 'Assigned Topic', $this->faculty('Faculty One'));

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertSee('Proposals without an adviser')
            ->assertSee(route('admin.proposals.index', ['adviser' => 'none']))
            ->assertViewHas('metrics', fn ($metrics) => array_column($metrics, 'value', 'label')['Proposals without an adviser'] === 1);
        $this->assertNotSame($unassigned->id, $assigned->id);
    }
}
