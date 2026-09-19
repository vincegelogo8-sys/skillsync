<?php

namespace App\Services;

use App\Models\AdviserAssignment;
use App\Models\AdviserRequest;
use App\Models\ResearchProposal;
use App\Models\User;

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
        $assessment = null;
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
                $assessment = app(SkillsAssessmentService::class)->score($profile->latestCompletedAssessment);
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
            $metrics[] = ['label' => 'Latest Completed Assessment', 'value' => $assessment === null ? 'Not completed' : number_format($assessment, 2).'%', 'route' => 'faculty.assessment.index'];
        }
        if ($role === User::ROLE_ADMIN) {
            $metrics[] = ['label' => 'Student Accounts', 'value' => User::where('role', User::ROLE_STUDENT)->count(), 'route' => 'admin.accounts.index'];
            $metrics[] = ['label' => 'Faculty Accounts', 'value' => User::where('role', User::ROLE_FACULTY)->count(), 'route' => 'admin.accounts.index'];
        }

        return [
            'role' => $role, 'roleLabel' => ucfirst($role), 'profileReady' => $role === User::ROLE_ADMIN || $profile !== null,
            'metrics' => $metrics,
            'recentRequests' => (clone $requests)->with(['researchProposal', 'facultyProfile.user', 'studentProfile.user'])->latest('id')->limit(5)->get(),
            'recentProposals' => $role === User::ROLE_FACULTY ? collect() : (clone $proposals)->latest('id')->limit(5)->get(),
            'recentAssignments' => $role === User::ROLE_FACULTY ? (clone $assignments)->with('researchProposal.studentProfile.user')->latest('assigned_at')->latest('id')->limit(5)->get() : collect(),
        ];
    }
}
