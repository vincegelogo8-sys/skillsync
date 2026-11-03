<?php

namespace Tests\Feature;

use App\Services\EmailLinkGenerator;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class EmailLinkGeneratorTest extends TestCase
{
    public function test_configured_email_base_url_is_used_for_existing_named_route_destinations(): void
    {
        config(['app.email_base_url' => 'http://192.168.50.20:8000/']);
        $links = new EmailLinkGenerator(Request::create('http://127.0.0.1:8000/'));

        $this->assertSame(
            'http://192.168.50.20:8000/faculty/requests',
            $links->route('faculty.requests.index')
        );
        $this->assertSame(
            'http://192.168.50.20:8000/student/requests',
            $links->route('student.requests.index')
        );
        $this->assertSame(
            'http://192.168.50.20:8000/student/proposals/47/recommendations?tab=active',
            $links->route('student.recommendations.index', ['proposal' => 47, 'tab' => 'active'])
        );
    }

    public function test_private_lan_host_and_application_port_from_the_request_are_used(): void
    {
        config(['app.email_base_url' => null, 'app.url' => 'http://127.0.0.1:8000']);
        $links = new EmailLinkGenerator(Request::create('http://192.168.1.72:8000/student/requests'));

        $this->assertSame('http://192.168.1.72:8000/faculty/requests', $links->route('faculty.requests.index'));
    }

    public function test_explicit_loopback_override_is_rejected(): void
    {
        config(['app.email_base_url' => 'http://127.0.0.1:8000']);
        $links = new EmailLinkGenerator(Request::create('http://localhost:8000/'));

        $this->expectException(RuntimeException::class);
        $links->route('faculty.requests.index');
    }
}
