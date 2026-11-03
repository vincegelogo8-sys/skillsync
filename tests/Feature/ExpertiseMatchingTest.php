<?php

namespace Tests\Feature;

use App\Models\ProposalAnalysis;
use App\Models\User;
use App\Services\ExpertiseMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpertiseMatchingTest extends TestCase
{
    use RefreshDatabase;

    public static function supportedCategories(): array
    {
        return [
            'web' => ['A web application will be developed as a web portal.', 'Web Development'],
            'mobile' => ['The Android mobile application will use Flutter.', 'Mobile Development'],
            'database' => ['The system stores records in a MySQL database.', 'Database Systems'],
            'networking' => ['The network protocol connects the computer network devices.', 'Networking'],
            'cybersecurity' => ['Cybersecurity and encryption protect user accounts.', 'Cybersecurity'],
            'machine learning' => ['Machine learning classifiers analyze the dataset.', 'Machine Learning / Data Analytics'],
            'software engineering' => ['Software engineering applies unit testing and agile development.', 'Software Engineering'],
            'systems analysis' => ['Requirements analysis and system requirements guide the project.', 'Systems Analysis'],
            'iot' => ['Internet of Things sensors collect readings using an ESP32.', 'Internet of Things'],
        ];
    }

    #[DataProvider('supportedCategories')]
    public function test_directly_identifies_each_supported_faculty_skill_category(string $text, string $expected): void
    {
        $result = app(ExpertiseMatchingService::class)->analyze('', $text);

        $this->assertContains($expected, $result['direct']);
        $this->assertSame([], array_intersect(['Cloud Computing', 'Computer Vision', 'Natural Language Processing', 'Game Development', 'UI/UX'], [...$result['direct'], ...$result['related']]));
    }

    public function test_excluded_categories_are_not_emitted_by_extraction(): void
    {
        $result = app(ExpertiseMatchingService::class)->analyze(
            '',
            'The proposal covers cloud computing, computer vision, natural language processing, game development, and UI/UX.'
        );

        $this->assertSame([], $result['direct']);
        $this->assertSame([], $result['related']);
    }

    public function test_related_categories_require_specific_proposal_evidence(): void
    {
        $matcher = app(ExpertiseMatchingService::class);
        $unrelated = $matcher->analyze('', 'A web application presents informational pages.');
        $supported = $matcher->analyze('', 'A web application is designed and implemented to store and retrieve persistent records.');
        $mobile = $matcher->analyze('', 'An Android mobile application synchronizes readings with a remote server and stores historical records.');
        $networkSecurity = $matcher->analyze('', 'A network monitoring tool protects traffic and detects unauthorized access.');
        $machineLearning = $matcher->analyze('', 'A machine learning model stores its results for later review.');
        $systemsAnalysis = $matcher->analyze('', 'Requirements analysis produces an implementation prototype for the project.');
        $iot = $matcher->analyze('', 'IoT sensors send readings through a gateway and store the historical data.');

        $this->assertSame([], $unrelated['related']);
        $this->assertContains('Database Systems', $supported['related']);
        $this->assertContains('Software Engineering', $supported['related']);
        $this->assertContains('Networking', $mobile['related']);
        $this->assertContains('Database Systems', $mobile['related']);
        $this->assertContains('Cybersecurity', $networkSecurity['related']);
        $this->assertContains('Database Systems', $machineLearning['related']);
        $this->assertContains('Software Engineering', $systemsAnalysis['related']);
        $this->assertContains('Networking', $iot['related']);
        $this->assertContains('Database Systems', $iot['related']);
    }

    public function test_saved_faculty_skills_are_canonicalized_and_only_category_matches_return(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'EM-1', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Mobile Records', 'file_path' => 'em.pdf', 'original_filename' => 'em.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'Android app uses SQLite and Laravel.';
        $analysis->forceFill([
            'analysis_status' => 'completed',
            'analysis_version' => ProposalAnalysis::CURRENT_VERSION,
            'analyzed_at' => now(),
            'keywords' => ['Mobile Development', 'Database Systems'],
            'identified_expertise_areas' => [],
            'technologies' => ['SQLite', 'Laravel'],
            'research_representation' => 'mobile database',
        ])->save();
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Mobile Application Development', 'technical_skills' => 'Flutter, Kotlin']);
        $faculty->expertise()->create(['expertise_area' => 'Database Systems', 'technical_skills' => 'SQLite, Oracle']);
        $faculty->expertise()->create(['expertise_area' => 'Cloud Computing', 'technical_skills' => 'AWS']);

        $matches = app(ExpertiseMatchingService::class)->matches($analysis, $faculty->load('expertise'));

        $this->assertEqualsCanonicalizing(['Mobile Development', 'Database Systems'], array_column($matches, 'area'));
        $databaseMatch = collect($matches)->firstWhere('area', 'Database Systems');
        $this->assertArrayNotHasKey('specific_skills', $databaseMatch);
        $this->assertSame(['direct', 'direct'], array_column($matches, 'match_type'));
        $this->assertSame([
            'Web Development',
            'Mobile Development',
            'Database Systems',
            'Networking',
            'Cybersecurity',
            'Machine Learning / Data Analytics',
            'Software Engineering',
            'Systems Analysis',
            'Internet of Things',
        ], config('expertise.areas'));
    }

    public function test_saved_unsupported_categories_and_unjustified_related_categories_are_excluded(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $profile = $student->studentProfile()->create(['student_number' => 'EM-2', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
        $proposal = $profile->researchProposals()->make();
        $proposal->forceFill(['title' => 'Web Portal', 'file_path' => 'em-2.pdf', 'original_filename' => 'em-2.pdf', 'file_type' => 'pdf', 'status' => 'extracted'])->save();
        $analysis = $proposal->analysis()->make();
        $analysis->extracted_text = 'A web portal presents static information.';
        $analysis->forceFill([
            'analysis_status' => 'completed',
            'analysis_version' => ProposalAnalysis::CURRENT_VERSION,
            'analyzed_at' => now(),
            'keywords' => ['Web Development', 'Computer Vision'],
            'identified_expertise_areas' => ['Database Systems', 'Cloud Computing'],
            'technologies' => [],
            'research_representation' => 'web portal',
        ])->save();
        $faculty = User::factory()->create(['role' => User::ROLE_FACULTY])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Web Development']);
        $faculty->expertise()->create(['expertise_area' => 'Database Systems']);
        $faculty->expertise()->create(['expertise_area' => 'Cloud Computing']);

        $matches = app(ExpertiseMatchingService::class)->matches($analysis, $faculty->load('expertise'));

        $this->assertSame(['Web Development'], array_column($matches, 'category'));
        $this->assertSame(['direct'], array_column($matches, 'match_type'));
    }
}
