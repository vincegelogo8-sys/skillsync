<?php

namespace App\Services;

use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Notifications\AdviserRequestDeclinedNotification;
use App\Notifications\AdviserRequestReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdviserRequestService
{
    public function __construct(private AdvisoryThresholdService $threshold) {}

    public function submit(ResearchProposal $proposal, FacultyProfile $faculty, User $actor): AdviserRequest
    {
        return DB::transaction(function () use ($proposal, $faculty, $actor) {
            $proposal = ResearchProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->role === User::ROLE_STUDENT && $actor->studentProfile?->id === $proposal->student_profile_id, 404);
            $faculty = FacultyProfile::whereKey($faculty->id)->lockForUpdate()->firstOrFail();
            abort_unless($faculty->user->role === User::ROLE_FACULTY, 404);
            if (! $proposal->analysis?->analyzed_at || ! $proposal->recommendations()->where('faculty_profile_id', $faculty->id)->exists()) {
                throw ValidationException::withMessages(['adviser_request' => 'Generate recommendations and choose a recommended faculty member before requesting an adviser.']);
            }
            if ($proposal->assignment()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['adviser_request' => 'This proposal already has an active adviser assignment.']);
            }
            if (AdviserRequest::where('research_proposal_id', $proposal->id)->where('faculty_profile_id', $faculty->id)->whereIn('status', AdviserRequest::ACTIVE_STATUSES)->exists()) {
                throw ValidationException::withMessages(['adviser_request' => 'Already Requested. This proposal has an active request for that faculty member.']);
            }
            if ($this->threshold->calculate($faculty->activeAssignments()->lockForUpdate()->get(['id'])->count(), $faculty->advisory_limit)['is_full']) {
                throw ValidationException::withMessages(['adviser_request' => 'This adviser is FULL. Please choose another faculty member or try again when capacity is available.']);
            }
            $request = new AdviserRequest;
            $request->forceFill([
                'student_profile_id' => $proposal->student_profile_id,
                'faculty_profile_id' => $faculty->id,
                'research_proposal_id' => $proposal->id,
                'status' => 'pending', 'active_slot' => 1, 'requested_at' => now(),
            ])->save();

            app(AdviserRequestNotificationService::class)->afterCommit($request, AdviserRequestReceivedNotification::class, 'facultyProfile');

            return $request;
        });
    }

    /** Cancellation and decline release the duplicate guard without deleting history. */
    public function close(AdviserRequest $request, User $actor, string $status): void
    {
        DB::transaction(function () use ($request, $actor, $status) {
            ResearchProposal::whereKey($request->research_proposal_id)->lockForUpdate()->firstOrFail();
            $request = AdviserRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            $canCancel = $status === 'cancelled' && $actor->role === User::ROLE_STUDENT && $actor->studentProfile?->id === $request->student_profile_id;
            $canDecline = $status === 'declined' && ($actor->role === User::ROLE_ADMIN || ($actor->role === User::ROLE_FACULTY && $actor->facultyProfile?->id === $request->faculty_profile_id));
            abort_unless($canCancel || $canDecline, 404);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['adviser_request' => 'Only pending requests can be cancelled or declined.']);
            }
            $request->status = $status;
            $request->active_slot = null;
            $request->responded_at = now();
            $request->save();
            if ($status === 'declined') {
                app(AdviserRequestNotificationService::class)->afterCommit($request, AdviserRequestDeclinedNotification::class, 'studentProfile');
            }
        });
    }
}
