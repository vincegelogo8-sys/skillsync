<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Services\DocumentExtractionService;
use App\Services\SimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

/** Explicit opt-in: exercises the real installed model, not a mocked NLP result. */
class LocalNlpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_offline_upload_refresh_rta_and_pdf_docx_consistency(): void
    {
        if (getenv('SKILLSYNC_REAL_NLP') !== '1') {
            $this->markTestSkipped('Set SKILLSYNC_REAL_NLP=1 to run the installed CPU model.');
        }
        $realFiles = glob(storage_path('app/private/proposals/*.docx'));
        Process::swap(new Factory);
        config(['local_nlp.python' => base_path(PHP_OS_FAMILY === 'Windows' ? 'nlp/.venv/Scripts/python.exe' : 'nlp/.venv/bin/python'),
            // Same production CLI, wrapped with an empty cache and network-attempt guard.
            'local_nlp.script' => base_path('nlp/verify_offline.py'), 'local_nlp.timeout' => 120]);
        // Keep slow real-model tests isolated from other tests' fake proposal disk.
        $disk = 'local-nlp-'.bin2hex(random_bytes(8));
        Storage::fake($disk);
        Storage::set('proposals', Storage::disk($disk));
        Mail::fake();
        $student = User::factory()->create(['role' => 'student']);
        $student->studentProfile()->create(['student_number' => 'NLP-001', 'course' => 'BSIT', 'year_level' => 3, 'section' => 'A']);
        $faculty = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $faculty->expertise()->create(['expertise_area' => 'Infrastructure Maintenance', 'proficiency_score' => 90]);
        $other = User::factory()->create(['role' => 'faculty'])->facultyProfile()->create(['department' => 'Computing']);
        $other->expertise()->create(['expertise_area' => 'Computer Vision', 'proficiency_score' => 90]);
        $this->actingAs($student);
        $text = file_get_contents(base_path('nlp/fixtures/maintenance.txt'));
        $lines = explode("\n", trim($text));
        $pdfLines = array_merge(...array_map(fn ($line) => explode("\n", wordwrap($line, 65, "\n")), $lines));
        $documents = ['pdf' => DocumentFixtures::pdf($pdfLines), 'docx' => DocumentFixtures::docxParagraphs($lines)];
        $observations = [];
        foreach ($documents as $type => $bytes) {
            $start = microtime(true);
            $this->post('/student/proposals', ['title' => 'Crowdsourced School Infrastructure Maintenance '.$type,
                'document' => UploadedFile::fake()->createWithContent('consistency.'.$type, $bytes),
            ])->assertSessionHasNoErrors();
            $proposal = ResearchProposal::latest('id')->first();
            $analysis = $proposal->analysis;
            $this->assertTrue($analysis->hasCurrentAnalysis());
            $this->assertNotEmpty($analysis->keywords);
            $this->assertNull($analysis->technologies);
            $url = '/student/proposals/'.$proposal->id;
            $page = $this->get($url)->assertOk()->assertSee('Research Concepts')->assertDontSee('0.8526')->assertDontSee('KeyBERT');
            foreach ($analysis->keywords as $keyword) {
                $this->assertIsString($keyword);
                $page->assertSee($keyword);
            }
            $this->post($url.'/recommendations')->assertSessionHasNoErrors();
            $scores = $proposal->recommendations()->orderBy('faculty_profile_id')->get();
            $expected = app(SimilarityService::class)->calculate($analysis->research_representation,
                [$faculty->id => 'Infrastructure Maintenance', $other->id => 'Computer Vision']);
            foreach ($scores as $score) {
                $this->assertEqualsWithDelta($expected['scores'][$score->faculty_profile_id], (float) $score->topic_alignment_score, 1e-7);
                $this->assertSame($score->cosine_similarity_score, $score->topic_alignment_score);
            }
            $this->get($url.'/recommendations')->assertOk()->assertSee('Research Topic Alignment')
                ->assertSee(number_format((float) $scores->first()->topic_alignment_score, 2).'%')
                ->assertDontSee('Rank #')->assertDontSee('KeyBERT');
            $observations[$type] = ['seconds' => round(microtime(true) - $start, 3), 'keywords' => $analysis->keywords,
                'rta' => $scores->pluck('topic_alignment_score')->all()];
        }
        $this->assertSame($observations['pdf']['keywords'], $observations['docx']['keywords']);
        $this->assertSame($observations['pdf']['rta'], $observations['docx']['rta']);
        $fileHash = hash('sha256', Storage::disk('proposals')->get($proposal->file_path));
        $ids = $proposal->recommendations()->pluck('id')->all();
        $start = microtime(true);
        $this->post($url.'/refresh-extraction')->assertSessionHasNoErrors();
        $this->assertSame($fileHash, hash('sha256', Storage::disk('proposals')->get($proposal->file_path)));
        $this->assertSame($observations['docx']['keywords'], $proposal->fresh()->analysis->keywords);
        $this->assertSame($ids, $proposal->recommendations()->pluck('id')->all());
        $observations['refresh_seconds'] = round(microtime(true) - $start, 3);

        if ($realFiles !== []) {
            // Read an existing upload, then analyze a copy in the isolated test database.
            $source = $realFiles[0];
            $realText = app(DocumentExtractionService::class)->extract($source, 'docx');
            preg_match('/Proposed Title:\s*([^\n]+)/iu', $realText, $match);
            $title = $match[1] ?? 'Uploaded Research Proposal';
            $start = microtime(true);
            $this->post('/student/proposals', ['title' => $title,
                'document' => UploadedFile::fake()->createWithContent('real-proposal.docx', file_get_contents($source)),
            ])->assertSessionHasNoErrors();
            $real = ResearchProposal::latest('id')->first();
            $this->assertTrue($real->analysis->hasCurrentAnalysis());
            $this->post('/student/proposals/'.$real->id.'/recommendations')->assertSessionHasNoErrors();
            $observations['existing_upload'] = ['seconds' => round(microtime(true) - $start, 3),
                'keywords' => $real->analysis->keywords, 'rta' => $real->recommendations()->pluck('topic_alignment_score')->all()];
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $path = storage_path('app/testing/local-nlp-integration.json');
        file_put_contents($path, json_encode($observations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDOUT, "\nReal offline integration: ".json_encode($observations, JSON_UNESCAPED_SLASHES)."\n");
    }
}
