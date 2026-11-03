<?php

return [
    'categories' => [
        'Web Development' => [
            'web development', 'web application', 'web-based', 'website', 'web portal',
            'php', 'laravel', 'django', 'flask', 'react.js', 'vue.js', 'angular',
            'javascript', 'html', 'css',
        ],
        'Mobile Development' => [
            'mobile development', 'mobile application', 'mobile app', 'android',
            'ios application', 'flutter', 'react native', 'kotlin',
        ],
        'Database Systems' => [
            'database systems', 'database', 'mysql', 'postgresql', 'mongodb', 'sqlite',
            'sql database', 'dbms', 'data warehouse',
        ],
        'Networking' => [
            'computer networking', 'networking', 'computer network', 'wireless network',
            'network monitoring', 'network traffic', 'tcp/ip', 'wi-fi', 'wifi', 'router', 'network protocol',
        ],
        'Cybersecurity' => [
            'cybersecurity', 'cyber security', 'information security', 'network security',
            'intrusion detection', 'encryption', 'firewall', 'penetration testing',
            'authentication security',
        ],
        'Machine Learning / Data Analytics' => [
            'machine learning', 'data analytics', 'data analysis', 'predictive analytics',
            'data mining', 'classification model', 'random forest', 'support vector machine',
            'svm', 'neural network', 'regression model', 'clustering',
        ],
        'Software Engineering' => [
            'software engineering', 'software development', 'software testing',
            'software design', 'software lifecycle', 'sdlc', 'agile', 'scrum',
            'unit testing', 'system development life cycle',
        ],
        'Systems Analysis' => [
            'systems analysis', 'system analysis', 'requirements analysis',
            'requirements gathering', 'system requirements', 'use case analysis',
            'business process analysis',
        ],
        'Internet of Things' => [
            'internet of things', 'iot', 'sensor network', 'microcontroller',
            'arduino', 'raspberry pi', 'esp32', 'esp8266', 'mqtt',
        ],
    ],
    'aliases' => [
        'web development' => 'Web Development',
        'mobile development' => 'Mobile Development',
        'mobile application development' => 'Mobile Development',
        'database systems' => 'Database Systems',
        'database management systems' => 'Database Systems',
        'networking' => 'Networking',
        'computer networking' => 'Networking',
        'cybersecurity' => 'Cybersecurity',
        'information security' => 'Cybersecurity',
        'machine learning data analytics' => 'Machine Learning / Data Analytics',
        'machine learning' => 'Machine Learning / Data Analytics',
        'data analytics' => 'Machine Learning / Data Analytics',
        'software engineering' => 'Software Engineering',
        'systems analysis' => 'Systems Analysis',
        'system analysis' => 'Systems Analysis',
        'internet of things' => 'Internet of Things',
        'iot' => 'Internet of Things',
    ],
    'related' => [
        [
            'from' => 'Web Development',
            'to' => 'Database Systems',
            'evidence' => '/\b(store\w*|persist\w*|records?|retrieve\w*|query\w*|database|data storage)\b/iu',
            'reason' => 'the proposal describes persistent records or data retrieval',
        ],
        [
            'from' => 'Web Development',
            'to' => 'Software Engineering',
            'evidence' => '/\b(design\w*|develop\w*|build|implement\w*|test\w*|software|system architecture)\b/iu',
            'reason' => 'the proposal describes software design, implementation, or testing',
        ],
        [
            'from' => 'Mobile Development',
            'to' => 'Database Systems',
            'evidence' => '/\b(store\w*|persist\w*|records?|retrieve\w*|query\w*|database|data storage)\b/iu',
            'reason' => 'the mobile application handles persistent records or data retrieval',
        ],
        [
            'from' => 'Mobile Development',
            'to' => 'Networking',
            'evidence' => '/\b(connect|communicat|send|receive|synchroni[sz]|api|network|server|remote)\w*/iu',
            'reason' => 'the mobile application communicates with a server, API, or network',
        ],
        [
            'from' => 'Networking',
            'to' => 'Cybersecurity',
            'evidence' => '/\b(protect|secure|security|monitor|intrusion|attack|threat|encrypt|firewall|vulnerabilit)\w*/iu',
            'reason' => 'the network work includes protection, security monitoring, or threat detection',
        ],
        [
            'from' => 'Machine Learning / Data Analytics',
            'to' => 'Database Systems',
            'evidence' => '/\b(database|mysql|postgresql|mongodb|sql|store\w*|retrieve\w*|query\w*|records?)\b/iu',
            'reason' => 'the proposal obtains or stores analysis data in a database',
        ],
        [
            'from' => 'Systems Analysis',
            'to' => 'Software Engineering',
            'evidence' => '/\b(design\w*|develop\w*|build|implement\w*|code\w*|test\w*|software|prototype)\b/iu',
            'reason' => 'the proposal proceeds from requirements analysis to software implementation',
        ],
        [
            'from' => 'Internet of Things',
            'to' => 'Networking',
            'evidence' => '/\b(connect|communicat|transmit|wireless|network|mqtt|wifi|wi-fi|bluetooth|protocol|gateway)\w*/iu',
            'reason' => 'the IoT devices communicate using a network or protocol',
        ],
        [
            'from' => 'Internet of Things',
            'to' => 'Database Systems',
            'evidence' => '/\b(database|mysql|postgresql|mongodb|sql|store\w*|persist\w*|record\w*|historical data)\b/iu',
            'reason' => 'the system stores or manages collected sensor data',
        ],
    ],
];
