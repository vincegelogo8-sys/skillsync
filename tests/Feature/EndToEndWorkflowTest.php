<?php

namespace Tests\Feature;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\ResearchProposal;
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
            $profile = $role === 'student'
                ? ['student_number' => 'E2E-001', 'course' => 'BS Information Technology', 'year_level' => 3, 'section' => 'B']
                : ['department' => 'Computing'];
            $this->send('post', '/admin/accounts', ['name' => $name, 'email' => $role.'@gmail.com', 'role' => $role, 'password' => 'workflow-password', 'password_confirmation' => 'workflow-password', ...$profile])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($admin);
        }
        $faculty = User::where('role', 'faculty')->sole();
        $student = User::where('role', 'student')->sole();
        $this->login($faculty, 'workflow-password');
        $this->send('patch', '/faculty/profile', ['name' => $faculty->name, 'department' => 'Computing'])->assertSessionHasNoErrors();
        $this->send('put', '/faculty/expertise', ['skills' => ['Web Development', 'Database Systems']])->assertSessionHasNoErrors();
        $this->login($admin, 'password');
        $profile = $faculty->fresh()->facultyProfile;

        $this->send('patch', '/admin/faculty/'.$profile->id.'/advisory-limit', ['advisory_limit' => 1])->assertSessionHasNoErrors();

        $this->login($student, 'workflow-password');
        $this->send('patch', '/student/profile', ['student_number' => 'E2E-001', 'course' => 'BS Information Technology', 'year_level' => 3, 'section' => 'B'])->assertSessionHasNoErrors();
        $bytes = $type === 'pdf' ? DocumentFixtures::pdf(['Abstract', 'Web application using Laravel PHP MySQL.', 'Objectives:', 'To develop a web application using Laravel PHP MySQL.', 'Database management and web development.']) : DocumentFixtures::docx();
        $this->send('post', '/student/proposals', ['title' => 'Web Application for Student Records '.$type, 'document' => UploadedFile::fake()->createWithContent('proposal.'.$type, $bytes)])->assertSessionHasNoErrors();
        $proposal = ResearchProposal::sole();
        $this->assertNotNull($proposal->analysis->analyzed_at);
        $this->assertStringContainsString('Laravel', $proposal->analysis->extracted_text);
        Storage::disk('proposals')->assertExists($proposal->file_path);
        $this->get('/student/proposals/'.$proposal->id.'/download')->assertNotFound();
        $recommendationsUrl = '/student/proposals/'.$proposal->id.'/recommendations';
        $this->send('post', $recommendationsUrl)->assertSessionHasNoErrors();
        $recommendations = $proposal->recommendations()->sole();
        $this->assertSame($recommendations->topic_alignment_score, $recommendations->final_score);
        $this->capture('recommendations-'.$type, $this->get($recommendationsUrl)->assertOk()->assertSee('AVAILABLE'));
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
        $this->get($recommendationsUrl)->assertSee('FULL')->assertSee('Already Requested');
        $this->capture('student-'.$type, $this->get('/student/dashboard')->assertOk());
        $this->assertSame($recommendations->toArray(), $recommendations->fresh()->toArray());
        Mail::assertNothingSent();
        $this->send('post', '/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/student/assignments')->assertRedirect('/login');
    }
}
