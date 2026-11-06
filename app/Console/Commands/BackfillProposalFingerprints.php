<?php

namespace App\Console\Commands;

use App\Models\ResearchProposal;
use App\Models\StudentProfile;
use App\Support\ProposalFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillProposalFingerprints extends Command
{
    protected $signature = 'proposals:backfill-fingerprints {--dry-run}';

    protected $description = 'Backfill proposal file and title fingerprints';

    public function handle(): int
    {
        $disk = Storage::disk('proposals');
        $proposals = ResearchProposal::with('studentProfile.user')
            ->orderBy('student_profile_id')
            ->orderBy('id')
            ->get();
        $updatedIds = [];
        $skipped = 0;
        $conflictIds = [];

        foreach ($proposals->groupBy('student_profile_id') as $studentProposals) {
            $candidates = [];

            foreach ($studentProposals as $proposal) {
                if ($proposal->file_hash !== null && $proposal->title_key !== null) {
                    continue;
                }

                if (! $disk->exists($proposal->file_path)) {
                    $student = $this->studentIdentity($proposal->student_profile_id);
                    $this->line(sprintf(
                        'Student: "%s", student number %s.',
                        $student->user?->name ?? 'Unknown student',
                        $student->student_number ?? 'unknown number'
                    ));
                    $this->line(sprintf(
                        'Missing file: proposal %d, title "%s".',
                        $proposal->id,
                        $proposal->title
                    ));
                    $skipped++;

                    continue;
                }

                $candidates[$proposal->id] = [
                    'proposal' => $proposal,
                    'file_hash' => $proposal->file_hash === null
                        ? ProposalFingerprint::fileHash($disk->path($proposal->file_path))
                        : null,
                    'title_key' => $proposal->title_key === null
                        ? ProposalFingerprint::titleKey($proposal->title)
                        : null,
                ];
            }

            foreach (['file_hash', 'title_key'] as $field) {
                $values = [];

                foreach ($studentProposals as $proposal) {
                    $value = $candidates[$proposal->id][$field] ?? $proposal->{$field};
                    if ($value !== null) {
                        $values[$value][] = $proposal;
                    }
                }

                foreach ($values as $value => $matches) {
                    if (count($matches) < 2) {
                        continue;
                    }

                    $collidingCandidates = [];
                    foreach ($matches as $proposal) {
                        if (($candidates[$proposal->id][$field] ?? null) === $value) {
                            $collidingCandidates[] = $proposal;
                        }
                    }
                    if ($collidingCandidates === []) {
                        continue;
                    }

                    foreach ($collidingCandidates as $proposal) {
                        $candidates[$proposal->id][$field] = null;
                        $conflictIds[$proposal->id] = true;
                    }

                    $this->reportConflict($studentProposals->first(), $field, $matches);
                }
            }

            foreach ($candidates as $id => $candidate) {
                $changes = [];
                foreach (['file_hash', 'title_key'] as $field) {
                    if ($candidate[$field] !== null) {
                        $changes[$field] = $candidate[$field];
                    }
                }
                if ($changes === []) {
                    continue;
                }

                $updatedIds[$id] = true;
                if ($this->option('dry-run')) {
                    $this->line(sprintf('Would update proposal %d ("%s").', $id, $candidate['proposal']->title));
                } else {
                    ResearchProposal::query()->whereKey($id)->update($changes);
                    $this->line(sprintf('Updated proposal %d ("%s").', $id, $candidate['proposal']->title));
                }
            }
        }

        $updated = $this->option('dry-run') ? 0 : count($updatedIds);
        $summary = sprintf(
            'Summary: updated %d%s, skipped %d, conflicts %d.',
            $updated,
            $this->option('dry-run') ? sprintf(' (dry run; would update %d)', count($updatedIds)) : '',
            $skipped,
            count($conflictIds)
        );
        $this->info($summary);

        return self::SUCCESS;
    }

    private function reportConflict(ResearchProposal $studentProposal, string $field, array $matches): void
    {
        $student = $this->studentIdentity($studentProposal->student_profile_id);
        $details = collect($matches)
            ->map(fn (ResearchProposal $proposal) => sprintf('%d ("%s")', $proposal->id, $proposal->title))
            ->implode(', ');

        $this->line(sprintf(
            'Conflict for %s: student "%s" (%s), proposals %s.',
            $field,
            $student->user?->name ?? 'Unknown student',
            $student->student_number ?? 'unknown number',
            $details
        ));
    }

    private function studentIdentity(int $studentProfileId): ?StudentProfile
    {
        return StudentProfile::with('user')->find($studentProfileId);
    }
}
