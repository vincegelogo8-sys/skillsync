<?php

return [
    'student' => [
        'primary' => [
            ['route' => 'student.proposals.index', 'label' => 'Research Proposals', 'icon' => 'document', 'description' => 'Upload proposals, review analysis, and compare adviser recommendations.', 'active' => ['student.proposals.*', 'student.recommendations.*']],
            ['route' => 'student.requests.index', 'label' => 'Adviser Requests', 'icon' => 'inbox', 'description' => 'Track your requests and their status.', 'active' => ['student.requests.*']],
            ['route' => 'student.assignments.index', 'label' => 'Adviser Assignments', 'icon' => 'clipboard-check', 'description' => 'View your final adviser assignments.', 'active' => ['student.assignments.*']],
        ],
        'secondary_label' => 'Profile',
        'secondary' => [
            ['route' => 'student.profile.edit', 'label' => 'Student Profile', 'icon' => 'user', 'description' => 'Update your student number, course, year, and section.', 'active' => ['student.profile.*']],
        ],
    ],
    'faculty' => [
        'primary' => [
            ['route' => 'faculty.requests.index', 'label' => 'Adviser Requests', 'icon' => 'inbox', 'description' => 'Review requests addressed to you.', 'active' => ['faculty.requests.*']],
            ['route' => 'faculty.assignments.index', 'label' => 'Assigned Students', 'icon' => 'graduation-cap', 'description' => 'View your assigned students and proposals.', 'active' => ['faculty.assignments.*']],
        ],
        'secondary_label' => 'Profile & Skills',
        'secondary' => [
            ['route' => 'faculty.profile.edit', 'label' => 'Faculty Profile', 'icon' => 'user', 'description' => 'Update your department and faculty profile.', 'active' => ['faculty.profile.*']],
            ['route' => 'faculty.expertise.index', 'label' => 'Faculty Skills', 'icon' => 'sparkles', 'description' => 'View and manage your faculty skill categories.', 'active' => ['faculty.expertise.*']],
        ],
    ],
    'admin' => [
        'primary' => [
            ['route' => 'admin.proposals.index', 'label' => 'Research Proposals', 'icon' => 'document', 'description' => 'Review student proposals, extracted text, and research concepts.', 'active' => ['admin.proposals.*']],
            ['route' => 'admin.requests.index', 'label' => 'Adviser Requests', 'icon' => 'inbox', 'description' => 'Monitor requests and faculty decisions.', 'active' => ['admin.requests.*']],
            ['route' => 'admin.assignments.index', 'label' => 'Adviser Assignments', 'icon' => 'clipboard-check', 'description' => 'Review final assignments and their approvers.', 'active' => ['admin.assignments.*']],
            ['route' => 'admin.history.index', 'label' => 'History', 'icon' => 'clock', 'description' => 'Browse all proposals, students and faculty, separated by course.', 'active' => ['admin.history.*']],
        ],
        'secondary_label' => 'Management',
        'secondary' => [
            ['route' => 'admin.accounts.index', 'label' => 'Accounts', 'icon' => 'users', 'description' => 'Create and review Student and Faculty accounts.', 'active' => ['admin.accounts.*']],
            ['route' => 'admin.advisory-limits.index', 'label' => 'Advisory Limits', 'icon' => 'sliders', 'description' => 'Review active loads and adjust capacity.', 'active' => ['admin.advisory-limits.*']],
        ],
    ],
];