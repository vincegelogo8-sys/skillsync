<?php

namespace App\Services;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\ResearchProposal;
use App\Models\User;
use App\Support\ActivityGroups;
use Carbon\Carbon;

class DashboardSummaryService
{
    public function forUser(User $user): array
    {
        abort_unless(in_array($user->role, User::ROLES, true), 403);
        $role = $user->role;
        $requests = AdviserRequest::query();
        $assignments = AdviserAssignment::query();
        $proposals = ResearchProposal::query();
        $profile = null;
        $capacity = null;
        if ($role === User::ROLE_STUDENT) {
            $profile = $user->studentProfile;
            $id = $profile?->id ?? 0;
            $requests->where('student_profile_id', $id);
            $proposals->where('student_profile_id', $id);
            $assignments->whereHas('researchProposal', fn ($query) => $query->where('student_profile_id', $id));
        } elseif ($role === User::ROLE_FACULTY) {
            $profile = $user->facultyProfile;
            $id = $profile?->id ?? 0;
            $requests->where('faculty_profile_id', $id);
            $assignments->where('faculty_profile_id', $id);
            if ($profile) {
                $capacity = app(AdvisoryThresholdService::class)->forFaculties([$profile])[$id];
            }
        }
        $metrics = [
            ['label' => 'Pending Requests', 'value' => (clone $requests)->where('status', 'pending')->count(), 'route' => $role.'.requests.index'],
            ['label' => 'Active Assignments', 'value' => (clone $assignments)->where('status', 'active')->count(), 'route' => $role.'.assignments.index'],
        ];
        if ($role !== User::ROLE_FACULTY) {
            array_unshift($metrics, ['label' => 'Research Proposals', 'value' => (clone $proposals)->count(), 'route' => $role.'.proposals.index']);
            $metrics[] = ['label' => 'Awaiting Analysis', 'value' => (clone $proposals)->whereDoesntHave('analysis', fn ($query) => $query->whereNotNull('analyzed_at'))->count(), 'route' => $role.'.proposals.index'];
        } else {
            $metrics[] = ['label' => 'Advisory Load', 'value' => $capacity ? $capacity['load'].' / '.$capacity['limit'].' · '.$capacity['status'] : 'Complete your profile', 'route' => 'faculty.assignments.index'];
        }
        if ($role === User::ROLE_ADMIN) {
            $metrics[] = [
                'label' => 'Proposals without an adviser',
                'value' => ResearchProposal::whereDoesntHave('assignment', fn ($query) => $query->where('status', AdviserAssignment::STATUS_ACTIVE))->count(),
                'route' => 'admin.proposals.index',
                'params' => ['adviser' => 'none'],
            ];
            foreach ([User::ROLE_STUDENT => 'Student Accounts', User::ROLE_FACULTY => 'Faculty Accounts'] as $accountRole => $label) {
                $active = User::where('role', $accountRole)->active()->count();
                $deactivated = User::where('role', $accountRole)->deactivated()->count();
                $metrics[] = [
                    'label' => $label,
                    'value' => $active.' active',
                    'secondary' => $deactivated.' deactivated',
                    'route' => 'admin.accounts.index',
                ];
            }
        }

        $windowStartUtc = Carbon::now(config('app.display_timezone', 'Asia/Manila'))
            ->startOfDay()
            ->subDays(6)
            ->utc();
        $recentRequests = (clone $requests)
            ->where(function ($query) use ($windowStartUtc): void {
                $query->where('requested_at', '>=', $windowStartUtc)
                    ->orWhere('responded_at', '>=', $windowStartUtc)
                    ->orWhere('updated_at', '>=', $windowStartUtc);
            })
            ->with(['researchProposal', 'facultyProfile.user', 'studentProfile.user'])
            ->latest('id')
            ->get();
        $recentProposals = $role === User::ROLE_FACULTY
            ? collect()
            : (clone $proposals)->where('created_at', '>=', $windowStartUtc)->with('studentProfile.user')->latest('created_at')->latest('id')->get();
        $recentAssignments = $role === User::ROLE_FACULTY
            ? (clone $assignments)->where('assigned_at', '>=', $windowStartUtc)->with('researchProposal.studentProfile.user')->latest('assigned_at')->latest('id')->get()
            : collect();

        return [
            'role' => $role, 'roleLabel' => ucfirst($role), 'profileReady' => $role === User::ROLE_ADMIN || $profile !== null,
            'metrics' => $metrics,
            'recentRequestGroups' => ActivityGroups::make($recentRequests, function (AdviserRequest $request) {
                $timestamps = collect([$request->requested_at, $request->responded_at])->filter();

                return $timestamps->sortBy(fn (Carbon $at): int => $at->getTimestamp())->last() ?? $request->updated_at;
            }),
            'recentProposalGroups' => ActivityGroups::make($recentProposals, fn (ResearchProposal $proposal) => $proposal->created_at),
            'recentAssignmentGroups' => ActivityGroups::make($recentAssignments, fn (AdviserAssignment $assignment) => $assignment->assigned_at),
        ];
    }
}
