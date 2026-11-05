<?php

namespace Tests\Feature;

use App\Models\AdviserRequest;
use App\Models\User;
use App\Services\ProposalAnalysisService;
use App\Services\RecommendationService;
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
        $manifest = $directory.'/current-pages.json';
        $pages = file_exists($manifest) ? json_decode(file_get_contents($manifest), true) : [];
        $pages[] = $name;
        file_put_contents($manifest, json_encode(array_values(array_unique($pages))));
    }

    public function test_all_active_page_templates_render_with_existing_permissions_and_fields(): void
    {
        if (getenv('SKILLSYNC_E2E_CAPTURE') === '1') {
            $this->app['files']->ensureDirectoryExists(storage_path('app/testing/ui'));
            file_put_contents(storage_path('app/testing/ui/current-pages.json'), '[]');
        }
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
        foreach (['dashboard', 'profile', 'expertise', 'requests', 'assignments'] as $path) {
            $this->page('faculty-'.$path, '/faculty/'.$path);
        }
        $this->get('/faculty/expertise/'.$expertise->id.'/edit')->assertForbidden();
        $request = AdviserRequest::sole();
        $this->post('/faculty/requests/'.$request->id.'/approve')->assertSessionHasNoErrors();
        $this->page('faculty-assigned-students', '/faculty/assignments');

        $this->actingAs($admin);
        foreach (['dashboard', 'accounts', 'accounts/create', 'advisory-limits', 'proposals', 'requests', 'assignments'] as $path) {
            $this->page('admin-'.str_replace('/', '-', $path), '/admin/'.$path);
        }
        $this->page('admin-limit-edit', '/admin/faculty/'.$facultyProfile->id.'/advisory-limit');
        $this->page('admin-proposal-details', '/admin/proposals/'.$proposal->id);
        $this->get('/admin/proposals/'.$proposal->id.'/recommendations')->assertNotFound();
    }
}
