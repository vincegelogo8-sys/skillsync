<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\FacultyCompetency;
use App\Models\ResearchProposal;
use App\Models\SkillsAssessmentAttempt;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentFixtures;
use Tests\Support\EnforceCsrfTokens;
use Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $method, string $url, array $data = []): TestResponse
    {
        $token = csrf_token() ?: bin2hex(random_bytes(20));

        return $this->withSession(['_token' => $token])->{$method}($url, [...$data, '_token' => $token]);
    }

    private function login(User $user, string $password): void
    {
        if (auth()->check()) {
            $this->send('post', '/logout')->assertRedirect('/');
        }
        $this->get('/login')->assertOk();
        $this->send('post', '/login', ['email' => $user->email, 'password' => $password])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect('/'.$user->role.'/dashboard');
    }

    private function capture(string $name, TestResponse $response): void
    {
        if (getenv('SKILLSYNC_E2E_CAPTURE') !== '1') {
            return;
        }
        $directory = storage_path('app/testing/e2e');
        $this->app['files']->ensureDirectoryExists($directory);
        $public = 'file:///'.str_replace('\\', '/', public_path()).'/';
        $html = preg_replace_callback('~https?://[^/"\s]+/(build/[^"\s]+|logo\.png)~', fn ($match) => $public.$match[1], $response->getContent());
        file_put_contents($directory.'/'.$name.'.html', $html);
    }

    public static function documents(): array
    {
        return [['pdf'], ['docx']];
    }

    #[DataProvider('documents')]
    public function test_complete_role_workflow_with_real_document_extraction(string $type): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforceCsrfTokens::class);
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('proposals');
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Test Admin']);
        $this->login($admin, 'password');
        foreach (['student' => 'Test Student', 'faculty' => 'Test Faculty'] as $role => $name) {
            $this->send('post', '/admin/accounts', ['name' => $name, 'email' => $role.'@gmail.com', 'role' => $role, 'password' => 'workflow-password', 'password_confirmation' => 'workflow-password'])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($admin);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->send('post', '/admin/assessment-questions', ['question' => 'Test question '.$i, 'option_a' => 'MySQL', 'option_b' => 'HTML', 'option_c' => 'CSS', 'option_d' => 'HTTP', 'correct_answer' => 'a', 'category' => 'Database Systems'])->assertSessionHasNoErrors();
        }
        $faculty = User::where('role', 'faculty')->sole();
        $student = User::where('role', 'student')->sole();
        $this->login($faculty, 'workflow-password');
        $this->send('patch', '/faculty/profile', ['name' => $faculty->name, 'department' => 'Computing'])->assertSessionHasNoErrors();
        foreach (['Web Development' => 90, 'Database Systems' => 80] as $area => $score) {
            $this->send('post', '/faculty/expertise', ['expertise_area' => $area, 'proficiency_score' => $score])->assertSessionHasNoErrors();
        }
        $this->send('patch', '/faculty/preferences', ['project_types' => ['Web-Based System'], 'technologies' => ['Laravel', 'PHP', 'MySQL']])->assertSessionHasNoErrors();
        $this->send('post', '/faculty/assessment')->assertSessionHasNoErrors();
        $attempt = SkillsAssessmentAttempt::sole();
        $answers = $attempt->answers()->orderBy('position')->get()->mapWithKeys(fn ($item, $index) => [$item->id => $index < 8 ? 'a' : 'b'])->all();
        $this->send('post', '/faculty/assessment/'.$attempt->id, ['answers' => $answers])->assertSessionHasNoErrors();
        $this->assertSame('80.00', $attempt->fresh()->percentage);

        $this->login($admin, 'password');
        $profile = $faculty->fresh()->facultyProfile;
        $this->send('patch', '/admin/faculty/'.$profile->id.'/competency', array_fill_keys(array_keys(FacultyCompetency::DIMENSIONS), 4))->assertSessionHasNoErrors();
        $this->send('patch', '/admin/faculty/'.$profile->id.'/advisory-limit', ['advisory_limit' => 1])->assertSessionHasNoErrors();

        $this->login($student, 'workflow-password');
        $this->send('patch', '/student/profile', ['student_number' => 'E2E-001', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'B'])->assertSessionHasNoErrors();
        $bytes = $type === 'pdf' ? DocumentFixtures::pdf(['Abstract', 'Web application using Laravel PHP MySQL.', 'Objectives:', 'To develop a web application using Laravel PHP MySQL.', 'Database management and web development.']) : DocumentFixtures::docx();
        $this->send('post', '/student/proposals', ['title' => 'Web Application for Student Records', 'document' => UploadedFile::fake()->createWithContent('proposal.'.$type, $bytes)])->assertSessionHasNoErrors();
        $proposal = ResearchProposal::sole();
        $this->assertNotNull($proposal->analysis->analyzed_at);
        $this->assertStringContainsString('Laravel', $proposal->analysis->extracted_text);
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $this->get('/student/proposals/'.$proposal->id.'/download')->assertOk();
        $rankingUrl = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->send('post', $rankingUrl)->assertSessionHasNoErrors();
        $ranking = $proposal->recommendations()->sole();
        $this->assertSame('80.00000000', $ranking->advising_competency_score);
        $this->assertSame('80.00000000', $ranking->skills_assessment_score);
        $this->assertEqualsWithDelta((float) $ranking->topic_alignment_score * .4 + 80 * .3 + (float) $ranking->preference_compatibility_score * .2 + 80 * .1, (float) $ranking->final_score, 1e-7);
        $this->capture('ranking-'.$type, $this->get($rankingUrl)->assertOk()->assertSee('AVAILABLE'));
        $this->send('post', '/student/proposals/'.$proposal->id.'/requests/'.$profile->id)->assertSessionHasNoErrors();
        $request = AdviserRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertDatabaseCount('adviser_assignments', 0);

        $this->login($faculty, 'workflow-password');
        $this->get('/faculty/requests')->assertOk()->assertSee($proposal->title)->assertSee('Pending');
        $this->send('post', '/faculty/requests/'.$request->id.'/approve')->assertSessionHasNoErrors()->assertRedirect('/faculty/assignments');
        $this->login($admin, 'password');
        $this->send('post', '/admin/requests/'.$request->id.'/approve')->assertNotFound();
        $assignment = AdviserAssignment::sole();
        $this->assertSame($faculty->id, $assignment->approved_by);
        $this->assertSame('active', $assignment->status);
        $this->assertSame('approved', $request->fresh()->status);
        $this->capture('admin-'.$type, $this->get('/admin/dashboard')->assertOk());
        $this->login($faculty, 'workflow-password');
        $this->get('/faculty/assignments')->assertOk()->assertSee($student->name)->assertSee($proposal->title);
        $this->capture('faculty-'.$type, $this->get('/faculty/dashboard')->assertOk()->assertSee('1 / 1')->assertSee('FULL'));
        $this->login($student, 'workflow-password');
        $this->get('/student/assignments')->assertOk()->assertSee($faculty->name);
        $this->get('/student/proposals/'.$proposal->id)->assertSee('Assigned Adviser');
        $this->get($rankingUrl)->assertSee('FULL')->assertSee('Already Requested');
        $this->capture('student-'.$type, $this->get('/student/dashboard')->assertOk());
        $this->assertSame($ranking->toArray(), $ranking->fresh()->toArray());
        Mail::assertNothingSent();
        $this->send('post', '/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/student/assignments')->assertRedirect('/login');
    }
}
