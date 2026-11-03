<?php

namespace App\Services;

/**
 * Maps proposal text to the computing skills needed to carry out the work
 * (e.g. "computer vision", "machine learning"), not the domain it is about
 * (e.g. "seaweed"). Deterministic and local: no network, no Python.
 */
class CsSkillExtractor
{
    public const MAX_SKILLS = 12;

    /** Minimum evidence score for a skill to be reported. */
    private const THRESHOLD = 2;

    /** skill => regex alternatives matched against lower-cased text. */
    private const TAXONOMY = [
        'artificial intelligence' => ['artificial intelligence', 'ai-based', 'ai-powered', 'intelligent system', 'expert system', 'fuzzy logic', 'genetic algorithm'],
        'machine learning' => ['machine learning', 'supervised learning', 'unsupervised learning', 'classifier', 'classification model', 'random forest', 'support vector', 'svm', 'decision tree', 'naive bayes', 'k-nearest', 'knn', 'xgboost', 'gradient boosting', 'model training', 'training dataset', 'regression model', 'scikit-learn'],
        'deep learning' => ['deep learning', 'neural network', 'cnn', 'convolutional', 'lstm', 'recurrent neural', 'tensorflow', 'keras', 'pytorch', 'yolo\w*', 'resnet', 'mobilenet', 'efficientnet', 'transfer learning'],
        'computer vision' => ['computer vision', 'image recognition', 'image classification', 'image processing', 'object detection', 'image segmentation', 'opencv', 'face recognition', 'facial recognition', 'optical character', 'ocr', 'video analytics', 'image-based', 'image dataset'],
        'image processing' => ['image processing', 'opencv', 'image segmentation', 'thresholding', 'edge detection', 'image preprocessing', 'image enhancement'],
        'object detection' => ['object detection', 'yolo\w*', 'faster r-cnn', 'bounding box', 'object counting'],
        'natural language processing' => ['natural language', 'nlp', 'sentiment analysis', 'text classification', 'chatbot', 'named entity', 'text mining', 'language model', 'bert', 'large language model', 'llm', 'speech recognition', 'text-to-speech'],
        'data mining' => ['data mining', 'association rule', 'apriori', 'clustering', 'k-means', 'predictive analytics', 'data analytics', 'big data', 'time series', 'forecasting'],
        'data visualization' => ['data visualization', 'dashboard', 'power bi', 'tableau', 'chart\.js'],
        'recommendation system' => ['recommendation system', 'recommender', 'collaborative filtering', 'content-based filtering'],
        'web development' => ['web application', 'web-based', 'website', 'web system', 'web portal', 'laravel', 'php', 'django', 'flask', 'react\.?js', 'vue\.?js', 'angular', 'node\.?js', 'javascript', 'html', 'css', 'asp\.net', 'rest api', 'codeigniter', 'xampp'],
        'mobile application development' => ['mobile app\w*', 'android', 'ios', 'flutter', 'react native', 'kotlin', 'mobile-based', 'mobile development'],
        'database systems' => ['database', 'mysql', 'postgresql', 'mongodb', 'sql', 'sqlite', 'firebase', 'dbms', 'data warehouse', 'data modeling'],
        'internet of things' => ['internet of things', 'iot', 'sensors?', 'mqtt', 'nodemcu', 'smart farming', 'smart home'],
        'embedded systems' => ['embedded', 'microcontroller', 'arduino', 'raspberry pi', 'esp32', 'esp8266', 'firmware', 'plc'],
        'robotics and automation' => ['robot\w*', 'actuators?', 'drones?', 'uav', 'automation'],
        'information security' => ['information security', 'cybersecurity', 'cyber security', 'network security', 'encryption', 'cryptograph\w*', 'authentication', 'intrusion detection', 'firewall', 'penetration testing', 'vulnerabilit\w*', 'aes', 'rsa'],
        'blockchain' => ['blockchain', 'smart contract', 'ethereum', 'distributed ledger'],
        'computer networking' => ['computer network\w*', 'wireless network', 'wi-fi', 'wifi', 'router', 'tcp/ip', 'vpn', 'mesh network', 'lora\w*'],
        'cloud computing' => ['cloud computing', 'cloud-based', 'aws', 'azure', 'google cloud', 'docker', 'kubernetes', 'serverless', 'devops', 'virtualization'],
        'software engineering' => ['software development', 'software engineering', 'agile', 'scrum', 'waterfall', 'software testing', 'unit test\w*', 'sdlc', 'system development life cycle', 'uml', 'requirements analysis', 'rapid application development', 'iso/?iec 25010', 'iso 25010', 'black-?box testing'],
        'human-computer interaction' => ['usability', 'user experience', 'ux', 'user interface', 'ui/ux', 'human-computer', 'heuristic evaluation', 'system usability scale'],
        'augmented and virtual reality' => ['augmented reality', 'virtual reality', 'mixed reality', 'vr', '3d model\w*', 'unity 3d'],
        'game development' => ['game development', 'game-based', 'gamification', 'unity', 'unreal engine', 'godot'],
        'geographic information systems' => ['geographic information', 'gis', 'geospatial', 'gps', 'openstreetmap', 'leaflet', 'google maps'],
        'information systems' => ['information system', 'management system', 'inventory system', 'enrollment system', 'record management', 'e-commerce', 'enterprise resource', 'erp', 'point of sale', 'monitoring system', 'tracking system'],
        'decision support systems' => ['decision support', 'analytic hierarchy', 'ahp', 'topsis', 'multi-criteria', 'fuzzy'],
        'educational technology' => ['e-learning', 'learning management', 'moodle', 'educational technology', 'mobile learning', 'gamified learning'],
        'biometrics and identification' => ['biometric\w*', 'fingerprint', 'rfid', 'qr code', 'barcode', 'nfc'],
    ];

    /** A specialised skill also requires its parent fields. */
    private const IMPLIES = [
        'deep learning' => ['machine learning', 'artificial intelligence'],
        'machine learning' => ['artificial intelligence'],
        'computer vision' => ['artificial intelligence', 'machine learning'],
        'object detection' => ['computer vision'],
        'natural language processing' => ['artificial intelligence', 'machine learning'],
        'recommendation system' => ['machine learning', 'data mining'],
        'decision support systems' => ['information systems'],
    ];

    /** @return list<string> lower-case skill names, strongest first */
    public function extract(string $title, string $text): array
    {
        $title = mb_strtolower($title);
        $text = mb_strtolower($text);
        $scores = [];

        foreach (self::TAXONOMY as $skill => $triggers) {
            $pattern = '~(?<![\p{L}\p{N}])(?:'.implode('|', array_map(
                fn (string $t) => str_replace(' ', '\s+', $t), $triggers
            )).')(?![\p{L}\p{N}])~u';
            $body = min(6, (int) preg_match_all($pattern, $text));
            $head = preg_match($pattern, $title) ? 3 : 0;
            if ($body + $head >= self::THRESHOLD) {
                $scores[$skill] = (float) ($body + $head);
            }
        }

        // Add implied parent fields just below the skill that implies them.
        foreach ($scores as $skill => $score) {
            foreach (self::IMPLIES[$skill] ?? [] as $parent) {
                $scores[$parent] = max($scores[$parent] ?? 0.0, $score - 0.5);
            }
        }

        // A parent-only match must not outrank the specific skill underneath it.
        arsort($scores);

        return array_slice(array_keys($scores), 0, self::MAX_SKILLS);
    }
}