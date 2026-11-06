<?php

namespace Tests\Feature;

use App\Models\ResearchProposal;
use App\Models\User;
use App\Support\ProposalFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalFingerprintBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('proposals');
    }

    private function student(string $name = 'Fingerprint Student', string $number = 'FP-001'): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => $name]);
        $student->studentProfile()->create([
            'student_number' => $number,
            'course' => 'BSIT',
            'year_level' => 3,
            'section' => 'A',
        ]);

        return $student;
    }

    private function proposal(User $student, string $title, string $path): ResearchProposal
    {
        $proposal = $student->studentProfile->researchProposals()->make()->forceFill([
            'student_profile_id' => $student->studentProfile->id,
            'title' => $title,
            'file_path' => $path,
            'original_filename' => basename($path),
            'file_type' => 'pdf',
            'status' => 'uploaded',
        ]);
        $proposal->save();

        return $proposal;
    }

    public function test_backfill_fills_fingerprints_and_is_idempotent(): void
    {
        $student = $this->student();
        Storage::disk('proposals')->put('first.pdf', 'first proposal bytes');
        Storage::disk('proposals')->put('second.pdf', 'second proposal bytes');
        $first = $this->proposal($student, 'Funeral  Preference Recommender!', 'first.pdf');
        $second = $this->proposal($student, 'A Distinct Proposal', 'second.pdf');

        $this->artisan('proposals:backfill-fingerprints')
            ->expectsOutputToContain('Summary: updated 2, skipped 0, conflicts 0.')
            ->assertExitCode(0);

        $first->refresh();
        $second->refresh();
        $this->assertSame(hash('sha256', 'first proposal bytes'), $first->file_hash);
        $this->assertSame('funeral preference recommender', $first->title_key);
        $this->assertSame(hash('sha256', 'second proposal bytes'), $second->file_hash);
        $this->assertSame('a distinct proposal', $second->title_key);

        $this->artisan('proposals:backfill-fingerprints')
            ->expectsOutputToContain('Summary: updated 0, skipped 0, conflicts 0.')
            ->assertExitCode(0);
    }

    public function test_backfill_leaves_only_per_student_collisions_null_and_reports_student_details(): void
    {
        $student = $this->student('Alex Student', 'S-4242');
        $this->assertSame('S-4242', $student->studentProfile->student_number);
        Storage::disk('proposals')->put('duplicate-a.pdf', 'same bytes');
        Storage::disk('proposals')->put('duplicate-b.pdf', 'same bytes');
        Storage::disk('proposals')->put('title-collision.pdf', 'different bytes one');
        Storage::disk('proposals')->put('title-collision-2.pdf', 'different bytes two');
        $fileCollisionOne = $this->proposal($student, 'File collision one', 'duplicate-a.pdf');
        $fileCollisionTwo = $this->proposal($student, 'File collision two', 'duplicate-b.pdf');
        $titleCollision = $this->proposal($student, 'Shared Proposal!', 'title-collision.pdf');
        $sameTitle = $this->proposal($student, 'shared proposal', 'title-collision-2.pdf');

        $this->artisan('proposals:backfill-fingerprints')
            ->expectsOutputToContain('Alex Student')
            ->expectsOutputToContain((string) $fileCollisionOne->id)
            ->expectsOutputToContain((string) $fileCollisionTwo->id)
            ->expectsOutputToContain('Summary: updated 4, skipped 0, conflicts 4.')
            ->assertExitCode(0);

        $this->assertNull($fileCollisionOne->fresh()->file_hash);
        $this->assertSame(ProposalFingerprint::titleKey($fileCollisionOne->title), $fileCollisionOne->fresh()->title_key);
        $this->assertNull($fileCollisionTwo->fresh()->file_hash);
        $this->assertSame(ProposalFingerprint::titleKey($fileCollisionTwo->title), $fileCollisionTwo->fresh()->title_key);
        $this->assertNull($titleCollision->fresh()->title_key);
        $this->assertSame(hash('sha256', 'different bytes one'), $titleCollision->fresh()->file_hash);
        $this->assertNull($sameTitle->fresh()->title_key);
        $this->assertSame(hash('sha256', 'different bytes two'), $sameTitle->fresh()->file_hash);
    }

    public function test_backfill_skips_and_reports_rows_with_missing_files(): void
    {
        $student = $this->student();
        $proposal = $this->proposal($student, 'Missing document', 'missing.pdf');

        $this->artisan('proposals:backfill-fingerprints')
            ->expectsOutputToContain('Missing file:')
            ->expectsOutputToContain('Fingerprint Student')
            ->expectsOutputToContain('Summary: updated 0, skipped 1, conflicts 0.')
            ->assertExitCode(0);

        $this->assertNull($proposal->fresh()->file_hash);
        $this->assertNull($proposal->fresh()->title_key);
    }

    public function test_dry_run_reports_proposed_changes_without_writing_them(): void
    {
        $student = $this->student();
        Storage::disk('proposals')->put('dry-run.pdf', 'dry-run bytes');
        $proposal = $this->proposal($student, 'Dry Run Proposal', 'dry-run.pdf');

        $this->artisan('proposals:backfill-fingerprints --dry-run')
            ->expectsOutputToContain('Would update proposal')
            ->expectsOutputToContain('Summary: updated 0 (dry run; would update 1), skipped 0, conflicts 0.')
            ->assertExitCode(0);

        $this->assertNull($proposal->fresh()->file_hash);
        $this->assertNull($proposal->fresh()->title_key);
    }
}
