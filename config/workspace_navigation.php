<?php

return [
    'student' => [
        'primary' => [
            ['route' => 'student.proposals.index', 'label' => 'Research Proposals', 'description' => 'Upload proposals, review analysis, and compare adviser recommendations.', 'active' => ['student.proposals.*', 'student.recommendations.*']],
            ['route' => 'student.requests.index', 'label' => 'Adviser Requests', 'description' => 'Track your requests and their status.', 'active' => ['student.requests.*']],
            ['route' => 'student.assignments.index', 'label' => 'Adviser Assignments', 'description' => 'View your final adviser assignments.', 'active' => ['student.assignments.*']],
        ],
        'secondary_label' => 'Profile',
        'secondary' => [
            ['route' => 'student.profile.edit', 'label' => 'Student Profile', 'description' => 'Update your student number, course, year, and section.', 'active' => ['student.profile.*']],
        ],
    ],
    'faculty' => [
        'primary' => [
            ['route' => 'faculty.requests.index', 'label' => 'Adviser Requests', 'description' => 'Review requests addressed to you.', 'active' => ['faculty.requests.*']],
            ['route' => 'faculty.assignments.index', 'label' => 'Assigned Students', 'description' => 'View your assigned students and proposals.', 'active' => ['faculty.assignments.*']],
        ],
        'secondary_label' => 'Profile & Skills',
        'secondary' => [
            ['route' => 'faculty.profile.edit', 'label' => 'Faculty Profile', 'description' => 'Update your department and faculty profile.', 'active' => ['faculty.profile.*']],
            ['route' => 'faculty.expertise.index', 'label' => 'Faculty Skills', 'description' => 'View and manage your faculty skill categories.', 'active' => ['faculty.expertise.*']],
        ],
    ],
    'admin' => [
        'primary' => [
            ['route' => 'admin.proposals.index', 'label' => 'Research Proposals', 'description' => 'Review student proposals, extracted text, and research concepts.', 'active' => ['admin.proposals.*']],
            ['route' => 'admin.requests.index', 'label' => 'Adviser Requests', 'description' => 'Monitor requests and faculty decisions.', 'active' => ['admin.requests.*']],
            ['route' => 'admin.assignments.index', 'label' => 'Adviser Assignments', 'description' => 'Review final assignments and their approvers.', 'active' => ['admin.assignments.*']],
        ],
        'secondary_label' => 'Management',
        'secondary' => [
            ['route' => 'admin.accounts.index', 'label' => 'Accounts', 'description' => 'Create and review Student and Faculty accounts.', 'active' => ['admin.accounts.*']],
            ['route' => 'admin.advisory-limits.index', 'label' => 'Advisory Limits', 'description' => 'Review active loads and adjust capacity.', 'active' => ['admin.advisory-limits.*']],
        ],
    ],
];
