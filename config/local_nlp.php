<?php

return [
    'enabled' => env('LOCAL_NLP_ENABLED', true),
    'python' => env('LOCAL_NLP_PYTHON') ?: base_path(PHP_OS_FAMILY === 'Windows' ? 'nlp/.venv/Scripts/python.exe' : 'nlp/.venv/bin/python'),
    'script' => base_path('nlp/concept_extractor.py'),
    'timeout' => (int) env('LOCAL_NLP_TIMEOUT', 120),
    'max_input_characters' => 120000,
    'max_output_bytes' => 65536,
];
