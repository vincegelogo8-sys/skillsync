<?php

namespace Tests\Feature;

use App\Models\SkillsAssessmentAttempt;
use App\Models\SkillsAssessmentQuestion;
use App\Models\User;
use App\Services\SkillsAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkillsAssessmentTest extends TestCase
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
            'question' => 'Which option represents a relational database?',
            'option_a' => 'MySQL', 'option_b' => 'HTML', 'option_c' => 'CSS', 'option_d' => 'HTTP',
            'correct_answer' => 'a', 'category' => 'Database Systems',
        ], $overrides);
    }

    private function bank(int $count = 10): void
    {
        for ($i = 1; $i <= $count; $i++) {
            SkillsAssessmentQuestion::create($this->details(['question' => 'Test question '.$i, 'correct_answer' => ['a', 'b', 'c', 'd'][$i % 4]]));
        }
    }

    private function start(User $faculty): SkillsAssessmentAttempt
    {
        $this->actingAs($faculty)->post('/faculty/assessment')->assertSessionHasNoErrors()->assertRedirect();

        return $faculty->facultyProfile->assessmentAttempts()->latest('id')->firstOrFail();
    }

    private function answers(SkillsAssessmentAttempt $attempt, int $correct = 10): array
    {
        return $attempt->answers()->orderBy('position')->get()->mapWithKeys(fn ($item, $index) => [
            $item->id => $index < $correct ? $item->correct_answer : ($item->correct_answer === 'a' ? 'b' : 'a'),
        ])->all();
    }

    public function test_admin_can_create_read_edit_remove_questions_and_view_results(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/assessment-questions')->assertOk()->assertSee('No questions yet.');
        $this->get('/admin/assessment-questions/create')->assertOk();
        $this->post('/admin/assessment-questions', $this->details())->assertSessionHasNoErrors()->assertRedirect('/admin/assessment-questions');
        $question = SkillsAssessmentQuestion::sole();
        $this->get('/admin/assessment-questions/'.$question->id.'/edit')->assertOk()->assertSee('value="a" selected', false);
        $this->patch('/admin/assessment-questions/'.$question->id, $this->details(['correct_answer' => 'b', 'question' => 'Revised question']))->assertSessionHasNoErrors();
        $this->assertSame('b', $question->fresh()->correct_answer);
        $this->get('/admin/assessment-questions')->assertSee('Revised question');
        $this->delete('/admin/assessment-questions/'.$question->id)->assertRedirect('/admin/assessment-questions');
        $this->assertDatabaseCount('skills_assessment_questions', 0);
        $this->get('/admin/assessment-results')->assertOk()->assertSee('No completed assessments yet.');
        $this->get('/admin/dashboard')->assertSee(route('admin.assessment-questions.index'))->assertSee(route('admin.assessment-results.index'));
    }

    public static function invalidQuestions(): array
    {
        return [
            [['question' => ''], 'question'], [['question' => str_repeat('x', 5001)], 'question'],
            [['option_a' => ''], 'option_a'], [['option_b' => 'MySQL'], 'option_b'],
            [['option_c' => 'HTML'], 'option_c'], [['option_d' => 'CSS'], 'option_d'],
            [['option_d' => str_repeat('x', 501)], 'option_d'], [['option_a' => ['invalid']], 'option_a'],
            [['correct_answer' => 'e'], 'correct_answer'], [['correct_answer' => ['a']], 'correct_answer'],
            [['category' => 'Unknown'], 'category'],
        ];
    }

    #[DataProvider('invalidQuestions')]
    public function test_invalid_questions_cannot_be_created_or_overwrite_existing_questions(array $invalid, string $field): void
    {
        $question = SkillsAssessmentQuestion::create($this->details());
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post('/admin/assessment-questions', $this->details($invalid))->assertSessionHasErrors($field);
        $this->get('/admin/assessment-questions/create')->assertOk();
        $this->patch('/admin/assessment-questions/'.$question->id, $this->details($invalid))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('skills_assessment_questions', 1);
        $this->assertSame($this->details()['question'], $question->fresh()->question);
        $this->assertSame('a', $question->fresh()->correct_answer);
    }

    public function test_guests_students_and_faculty_cannot_access_admin_questions_or_results(): void
    {
        $question = SkillsAssessmentQuestion::create($this->details());
        $urls = [
            ['GET', '/admin/assessment-questions'], ['GET', '/admin/assessment-questions/create'],
            ['POST', '/admin/assessment-questions'], ['GET', '/admin/assessment-questions/'.$question->id.'/edit'],
            ['PATCH', '/admin/assessment-questions/'.$question->id], ['DELETE', '/admin/assessment-questions/'.$question->id],
            ['GET', '/admin/assessment-results'],
        ];
        foreach ($urls as [$method, $url]) {
            $this->call($method, $url, $this->details())->assertRedirect('/login');
        }
        foreach ([User::ROLE_STUDENT, User::ROLE_FACULTY] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($urls as [$method, $url]) {
                $this->call($method, $url, $this->details())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('skills_assessment_questions', 1);
    }

    public function test_a_completed_profile_and_ten_questions_are_required_to_start(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->get('/faculty/assessment')->assertRedirect('/faculty/profile');
        $this->post('/faculty/assessment')->assertRedirect('/faculty/profile');
        $this->get('/faculty/profile')->assertSee('Save your full name and department before taking the skills assessment.');
        $this->assertDatabaseCount('faculty_profiles', 0);
        $faculty = $this->faculty();
        $this->bank(9);
        $this->actingAs($faculty)->get('/faculty/assessment')->assertSee('Assessment is not available yet.');
        $this->from('/faculty/assessment')->post('/faculty/assessment')->assertSessionHasErrors('assessment');
        $this->assertDatabaseCount('skills_assessment_attempts', 0);
        $this->assertDatabaseCount('skills_assessment_answers', 0);
    }

    public function test_start_selects_ten_unique_questions_and_resumes_the_same_active_attempt(): void
    {
        $faculty = $this->faculty();
        $this->bank(14);
        $attempt = $this->start($faculty);
        $this->assertSame(10, $attempt->total_items);
        $this->assertSame(10, $attempt->answers()->count());
        $this->assertSame(10, $attempt->answers()->pluck('question_id')->unique()->count());
        $this->assertNull($attempt->score);
        $this->assertNull($attempt->percentage);
        $this->assertNull($attempt->completed_at);
        $this->assertSame($attempt->id, $this->start($faculty)->id);
        $this->assertDatabaseCount('skills_assessment_attempts', 1);
        $this->get('/faculty/assessment')->assertSee('Resume Assessment');
        $this->get('/faculty/dashboard')->assertSee(route('faculty.assessment.index'));
        $this->get('/faculty/assessment/'.$attempt->id)->assertOk()->assertDontSee('correct_answer')
            ->assertViewHas('items', fn ($items) => $items->count() === 10 && $items->every(fn ($item) => ! array_key_exists('correct_answer', $item->getAttributes())));
        $this->assertArrayNotHasKey('correct_answer', $attempt->answers()->first()->toArray());
        $this->assertArrayNotHasKey('correct_answer', SkillsAssessmentQuestion::first()->toArray());
    }

    public static function scores(): array
    {
        return [[0, '0.00'], [9, '90.00'], [10, '100.00']];
    }

    #[DataProvider('scores')]
    public function test_scores_are_calculated_on_server_and_results_are_visible_to_faculty_and_admin(int $correct, string $percentage): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $attempt = $this->start($faculty);
        $this->post('/faculty/assessment/'.$attempt->id, [
            'answers' => $this->answers($attempt, $correct), 'score' => 999, 'percentage' => 999, 'faculty_profile_id' => 999,
        ])->assertSessionHasNoErrors()->assertRedirect('/faculty/assessment/'.$attempt->id);
        $this->assertSame($correct, $attempt->fresh()->score);
        $this->assertSame($percentage, $attempt->fresh()->percentage);
        $this->assertNotNull($attempt->fresh()->completed_at);
        $this->assertSame(10, $attempt->answers()->whereNotNull('selected_answer')->count());
        $this->get('/faculty/assessment/'.$attempt->id)->assertOk()->assertSee($percentage.'%')->assertDontSee('correct_answer')
            ->assertDontSee('name="answers[', false)->assertViewHas('items', fn ($items) => $items->isEmpty());
        $this->get('/faculty/assessment')->assertSee($percentage.'%');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin/assessment-results')
            ->assertOk()->assertSee($faculty->email)->assertSee($percentage.'%');
    }

    public function test_completed_attempts_cannot_be_regraded_and_retake_creates_a_new_attempt(): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $attempt = $this->start($faculty);
        $this->post('/faculty/assessment/'.$attempt->id, ['answers' => $this->answers($attempt, 9)])->assertSessionHasNoErrors();
        $original = $attempt->fresh()->toArray();
        $this->post('/faculty/assessment/'.$attempt->id, ['answers' => $this->answers($attempt, 10)])->assertSessionHasNoErrors();
        $this->assertSame($original, $attempt->fresh()->toArray());
        $second = $this->start($faculty);
        $this->assertNotSame($attempt->id, $second->id);
        $this->assertDatabaseCount('skills_assessment_attempts', 2);
    }

    public function test_question_edits_and_deletions_do_not_change_existing_attempts(): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $attempt = $this->start($faculty);
        $item = $attempt->answers()->first();
        $originalQuestion = $item->question;
        $answers = $this->answers($attempt);
        SkillsAssessmentQuestion::whereKey($item->question_id)->update(['question' => 'Changed text', 'correct_answer' => $item->correct_answer === 'a' ? 'b' : 'a']);
        SkillsAssessmentQuestion::findOrFail($item->question_id)->delete();
        $this->get('/faculty/assessment/'.$attempt->id)->assertOk()->assertSee($originalQuestion)->assertDontSee('Changed text');
        $this->get('/faculty/assessment')->assertSee('Resume Assessment');
        $this->assertNull($item->fresh()->question_id);
        $this->post('/faculty/assessment/'.$attempt->id, ['answers' => $answers])->assertSessionHasNoErrors();
        $this->assertSame(10, $attempt->fresh()->score);
    }

    public static function invalidSubmissions(): array
    {
        return [['missing'], ['extra'], ['wrong_id'], ['invalid_option'], ['nested'], ['scalar'], ['empty']];
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_submissions_save_no_answers_or_score(string $kind): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $attempt = $this->start($faculty);
        $answers = $this->answers($attempt);
        $first = array_key_first($answers);
        if ($kind === 'missing' || $kind === 'wrong_id') {
            unset($answers[$first]);
        }
        if ($kind === 'extra' || $kind === 'wrong_id') {
            $answers[999999] = 'a';
        }
        if ($kind === 'invalid_option') {
            $answers[$first] = 'e';
        }
        if ($kind === 'nested') {
            $answers[$first] = ['a'];
        }
        if ($kind === 'scalar') {
            $answers = 'a';
        }
        if ($kind === 'empty') {
            $answers = [];
        }
        $url = '/faculty/assessment/'.$attempt->id;
        $this->from($url)->post($url, ['answers' => $answers])->assertSessionHasErrors()->assertRedirect($url);
        $this->get($url)->assertOk()->assertDontSee('correct_answer');
        $this->assertNull($attempt->fresh()->completed_at);
        $this->assertNull($attempt->fresh()->score);
        $this->assertSame(0, $attempt->answers()->whereNotNull('selected_answer')->count());
    }

    public function test_other_faculty_cannot_view_or_submit_an_attempt(): void
    {
        $owner = $this->faculty();
        $this->bank();
        $attempt = $this->start($owner);
        $this->actingAs($this->faculty())->get('/faculty/assessment/'.$attempt->id)->assertNotFound();
        $this->post('/faculty/assessment/'.$attempt->id, ['answers' => $this->answers($attempt)])->assertNotFound();
        $this->get('/faculty/assessment')->assertDontSee('Attempt #'.$attempt->id);
        $this->get('/faculty/assessment/999999')->assertNotFound();
        $this->assertNull($attempt->fresh()->completed_at);
    }

    public function test_guests_students_and_admin_cannot_use_faculty_attempt_endpoints(): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $attempt = app(SkillsAssessmentService::class)->start($faculty->facultyProfile);
        $urls = [['GET', '/faculty/assessment'], ['POST', '/faculty/assessment'], ['GET', '/faculty/assessment/'.$attempt->id], ['POST', '/faculty/assessment/'.$attempt->id]];
        foreach ($urls as [$method, $url]) {
            $this->call($method, $url)->assertRedirect('/login');
        }
        foreach ([User::ROLE_STUDENT, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($urls as [$method, $url]) {
                $this->call($method, $url)->assertForbidden();
            }
        }
    }

    public function test_profile_deletion_cascades_attempts_and_answers_but_preserves_question_bank(): void
    {
        $faculty = $this->faculty();
        $this->bank();
        $this->start($faculty);
        $faculty->facultyProfile->delete();
        $this->assertDatabaseCount('skills_assessment_attempts', 0);
        $this->assertDatabaseCount('skills_assessment_answers', 0);
        $this->assertDatabaseCount('skills_assessment_questions', 10);
    }
}
