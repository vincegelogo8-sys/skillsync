<?php

namespace App\Services;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\FacultyProfile;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Notifications\AdviserRequestAcceptedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdviserAssignmentService
{
    public function __construct(private AdvisoryThresholdService $threshold) {}

    public function approve(AdviserRequest $request, User $admin): AdviserAssignment
    {
        return DB::transaction(function () use ($request, $admin) {
            $admin = User::findOrFail($admin->id);
            abort_unless($admin->role === User::ROLE_ADMIN, 403);
            // All request/assignment writes acquire the proposal before faculty/request rows.
            $proposal = ResearchProposal::whereKey($request->research_proposal_id)->lockForUpdate()->firstOrFail();
            $faculty = FacultyProfile::whereKey($request->faculty_profile_id)->lockForUpdate()->firstOrFail();
            $request = AdviserRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $assignment = $proposal->assignment()->lockForUpdate()->first();
            // Safe replay: retain the original assignment, approver and timestamp.
            if ($request->status === 'approved' && $assignment?->status === 'active' && $assignment->faculty_profile_id === $faculty->id) {
                return $assignment;
            }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['assignment' => 'Only a pending request can be approved and assigned.']);
            }
            if ($assignment) {
                throw ValidationException::withMessages(['assignment' => 'This proposal already has an assignment record. It cannot be assigned again through request approval.']);
            }
            if ($faculty->user->role !== User::ROLE_FACULTY || $proposal->studentProfile->user->role !== User::ROLE_STUDENT || $request->student_profile_id !== $proposal->student_profile_id) {
                throw ValidationException::withMessages(['assignment' => 'The request no longer has a valid Student owner or Faculty recipient.']);
            }
            // A locking read sees the latest committed load even after waiting for another approval.
            $load = $faculty->activeAssignments()->lockForUpdate()->get(['id'])->count();
            if ($this->threshold->calculate($load, $faculty->advisory_limit)['is_full']) {
                throw ValidationException::withMessages(['assignment' => 'This adviser is FULL. Approval did not change the request or create an assignment.']);
            }
            $assignment = $proposal->assignment()->make();
            $assignment->forceFill(['faculty_profile_id' => $faculty->id, 'approved_by' => $admin->id, 'assigned_at' => now(), 'status' => AdviserAssignment::STATUS_ACTIVE])->save();
            $request->status = 'approved';
            $request->active_slot = 1;
            $request->responded_at = now();
            $request->save();
            AdviserRequest::where('research_proposal_id', $proposal->id)->where('id', '!=', $request->id)->where('status', 'pending')
                ->update(['status' => 'cancelled', 'active_slot' => null, 'responded_at' => now(), 'updated_at' => now()]);

            app(AdviserRequestNotificationService::class)->afterCommit($request, AdviserRequestAcceptedNotification::class, 'studentProfile');

            return $assignment;
        });
    }
}
