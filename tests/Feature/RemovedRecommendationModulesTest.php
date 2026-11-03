<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemovedRecommendationModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_modules_have_no_routes_or_dashboard_links(): void
    {
        foreach (['admin', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/'.$role.'/dashboard')->assertOk()
                ->assertDontSee('Competency Evaluation')->assertDontSee('Assessment Questions')
                ->assertDontSee('Assessment Results')->assertDontSee('Skills Assessment')
                ->assertDontSee('faculty/preferences');
        }
        foreach ([
            '/admin/competencies', '/admin/faculty/1/competency',
            '/admin/assessment-questions', '/admin/assessment-questions/create',
            '/admin/assessment-questions/1', '/admin/assessment-questions/1/edit',
            '/admin/assessment-results', '/faculty/assessment',
            '/faculty/assessment/1', '/faculty/preferences',
        ] as $url) {
            foreach (['get', 'post', 'patch', 'put', 'delete'] as $method) {
                $this->{$method}($url)->assertNotFound();
            }
        }
    }
}
