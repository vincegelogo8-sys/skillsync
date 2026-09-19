<?php

namespace Tests\Feature;

use App\Models\AdviserRequest;
use App\Models\SkillsAssessmentQuestion;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
use App\Services\SkillsAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UiPresentationTest extends TestCase
{
    use RefreshDatabase;

    /** Capture real rendered routes with isolated data, never a production login. */
    private function page(string $name, string $url, bool $workspace = true): void
    {
        $response = $this->get($url)->assertOk();
        if ($workspace) {
            $response->assertSee('workspace-page-header')->assertSee('aria-current="page"', false);
        }
        if (getenv('SKILLSYNC_E2E_CAPTURE') !== '1') {
            return;
        }
        $directory = storage_path('app/testing/ui');
        $this->app['files']->ensureDirectoryExists($directory);
        $public = 'file:///'.str_replace('\\', '/', public_path()).'/';
        $html = preg_replace_callback('~https?://[^/"\s]+/((?:build|images)/[^"\s]+|logo\.png)~', fn ($match) => $public.$match[1], $response->getContent());
        file_put_contents($directory.'/'.$name.'.html', $html);
    }

    public function test_all_active_page_templates_render_with_existing_permissions_and_fields(): void
    {
        Notification::fake();
        $this->page('public-home', '/', false);
        $this->page('auth-login', '/login', false);
        $this->page('auth-forgot', '/forgot-password', false);
        $this->page('auth-reset', '/reset-password/test-token', false);

        $student = User::factory()->create(['role' => 'student', 'name' => 'Alex Rivera']);
        $studentProfile = $student->studentProfile()->create(['student_number' => 'UI-001', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B']);
        $faculty = User::factory()->create(['role' => 'faculty', 'name' => 'Dr. Morgan Santos']);
        $facultyProfile = $faculty->facultyProfile()->create(['department' => 'College of Computing']);
        $expertise = $facultyProfile->expertise()->create(['expertise_area' => 'Web Development', 'proficiency_score' => 90]);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Research Admin']);
        for ($i = 1; $i <= 10; $i++) {
            $question = SkillsAssessmentQuestion::create(['question' => 'Which approach supports a maintainable web application?', 'option_a' => 'Use clearly separated responsibilities.', 'option_b' => 'Duplicate every function.', 'option_c' => 'Remove input validation.', 'option_d' => 'Share passwords between users.', 'correct_answer' => 'a', 'category' => 'Web Development']);
        }

        $this->actingAs($student);
        $this->page('student-proposals-empty', '/student/proposals');
        $proposal = $studentProfile->researchProposals()->make();
        $proposal->forceFill(['title' => 'CampusSkill: A Web-Based Skill Exchange and Service Request System', 'file_path' => 'ui.pdf', 'original_filename' => 'CampusSkill Concept Paper.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = "Proposed Title: CampusSkill\nObjectives:\n1. To develop a web application using Laravel.\n2. To provide a real-time dashboard.\nParticipants: Students";
        $analysis->save();
        app(ProposalAnalysisService::class)->analyze($proposal);
        app(RecommendationService::class)->generate($proposal, $student);
        foreach (['dashboard', 'profile', 'proposals', 'proposals/create', 'requests', 'assignments'] as $path) {
            $this->page('student-'.str_replace('/', '-', $path), '/student/'.$path);
        }
        $this->page('student-proposal-details', '/student/proposals/'.$proposal->id);
        $this->page('student-recommendations', '/student/proposals/'.$proposal->id.'/recommendations');
        $this->post('/student/proposals/'.$proposal->id.'/requests/'.$facultyProfile->id)->assertSessionHasNoErrors();
        $this->page('student-requested-recommendations', '/student/proposals/'.$proposal->id.'/recommendations');
        $this->page('student-requests-populated', '/student/requests');
        $this->page('account-settings', '/profile');
        $this->page('auth-confirm', '/confirm-password', false);
        $student->forceFill(['email_verified_at' => null])->save();
        $this->page('auth-verify', '/verify-email', false);

        $this->actingAs($faculty);
        foreach (['dashboard', 'profile', 'expertise', 'preferences', 'assessment', 'requests', 'assignments'] as $path) {
            $this->page('faculty-'.$path, '/faculty/'.$path);
        }
        $this->page('faculty-expertise-edit', '/faculty/expertise/'.$expertise->id.'/edit');
        $attempt = app(SkillsAssessmentService::class)->start($facultyProfile);
        $this->page('faculty-assessment-active', '/faculty/assessment/'.$attempt->id);
        $this->get('/faculty/assessment/'.$attempt->id)->assertDontSee('correct_answer')->assertSee('data-assessment-progress', false);
        $answers = $attempt->answers()->get()->mapWithKeys(fn ($answer) => [$answer->id => 'a'])->all();
        app(SkillsAssessmentService::class)->submit($facultyProfile, $attempt, $answers);
        $this->page('faculty-assessment-completed', '/faculty/assessment/'.$attempt->id);
        $request = AdviserRequest::sole();
        $this->post('/faculty/requests/'.$request->id.'/approve')->assertSessionHasNoErrors();
        $this->page('faculty-assigned-students', '/faculty/assignments');

        $this->actingAs($admin);
        foreach (['dashboard', 'accounts', 'accounts/create', 'expertise', 'competencies', 'advisory-limits', 'assessment-questions', 'assessment-questions/create', 'assessment-results', 'proposals', 'requests', 'assignments'] as $path) {
            $this->page('admin-'.str_replace('/', '-', $path), '/admin/'.$path);
        }
        $this->page('admin-expertise-detail', '/admin/faculty/'.$facultyProfile->id.'/expertise');
        $this->page('admin-expertise-edit', '/admin/faculty/'.$facultyProfile->id.'/expertise/'.$expertise->id.'/edit');
        $this->page('admin-competency-edit', '/admin/faculty/'.$facultyProfile->id.'/competency');
        $this->page('admin-limit-edit', '/admin/faculty/'.$facultyProfile->id.'/advisory-limit');
        $this->page('admin-question-edit', '/admin/assessment-questions/'.$question->id.'/edit');
        $this->page('admin-proposal-details', '/admin/proposals/'.$proposal->id);
        $this->page('admin-recommendations', '/admin/proposals/'.$proposal->id.'/recommendations');
    }
}
