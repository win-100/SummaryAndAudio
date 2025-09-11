<?php
error_reporting(E_ERROR);
require __DIR__ . '/../Controllers/ArticleSummaryController.php';

// Stub classes and functions required by the controller
class Minz_ActionController {}
class FreshRSS_Context { public static $user_conf; }
class Minz_Request {
    public static $params = [];
    public static function param($name) {
        return self::$params[$name] ?? null; // No 'more' or 'id' parameter needed for this test
    }
}
class FreshRSS_Factory {
    public static function createEntryDao() {
        return new class {
            public function searchById($id) {
                return new class {
                    public function content() {
                        return 'Example content';
                    }
                };
            }
        };
    }
}

// Set up configuration with a specific model name
FreshRSS_Context::$user_conf = (object) [
    'oai_url' => 'https://api.example.com',
    'oai_key' => 'test-key',
    'oai_model' => 'my-configured-model',
    'oai_prompt' => 'prompt',
    'oai_prompt_2' => 'prompt2',
    'oai_provider' => 'openai',
    'oai_tts_model' => 'my-tts-model',
    'oai_voice' => 'my-voice',
    'oai_speed' => 1.1,
];

// Capture the output of summarizeAction()
ob_start();
$controller = new FreshExtension_ArticleSummary_Controller();
$controller->view = new class {
    public function _layout($layout) {}
};
$controller->summarizeAction();
$output = ob_get_clean();

// Decode the JSON response
$data = json_decode($output, true);
$summary = $data['response']['summary'] ?? null;

// Simple assertion
if ($summary !== 'test summary') {
    echo "Summary mismatch: expected test summary, got {$summary}\n";
    exit(1);
}

echo "Summary returned\n";

// Test fetchTtsParamsAction()
ob_start();
$controller->fetchTtsParamsAction();
$ttsOutput = ob_get_clean();
$ttsData = json_decode($ttsOutput, true);
$voice = $ttsData['response']['data']['voice'] ?? null;
$format = $ttsData['response']['data']['response_format'] ?? null;
$speed = $ttsData['response']['data']['speed'] ?? null;

if ($voice !== 'my-voice') {
    echo "Voice mismatch: expected my-voice, got {$voice}\n";
    exit(1);
}

if ($format !== 'opus') {
    echo "Format mismatch: expected opus, got {$format}\n";
    exit(1);
}

if ($speed !== 1.1) {
    echo "Speed mismatch: expected 1.1, got {$speed}\n";
    exit(1);
}

echo "Voice matches configuration\n";
echo "Format matches configuration\n";
echo "Speed matches configuration\n";

// Test speakAction()
Minz_Request::$params = ['content' => 'Speak me'];
ob_start();
$controller->speakAction();
$speakOutput = ob_get_clean();
$speakData = json_decode($speakOutput, true);
$input = $speakData['response']['data']['input'] ?? null;
$speakFormat = $speakData['response']['data']['response_format'] ?? null;
$speakSpeed = $speakData['response']['data']['speed'] ?? null;

if ($input !== 'Speak me') {
    echo "Input mismatch: expected Speak me, got {$input}\n";
    exit(1);
}

if ($speakFormat !== 'opus') {
    echo "Speak format mismatch: expected opus, got {$speakFormat}\n";
    exit(1);
}

if ($speakSpeed !== 1.1) {
    echo "Speak speed mismatch: expected 1.1, got {$speakSpeed}\n";
    exit(1);
}

echo "Speak action returns input\n";
echo "Speak action returns format\n";
echo "Speak action returns speed\n";
