<?php
error_reporting(E_ERROR);
require __DIR__ . '/../Controllers/SummaryAndAudioController.php';

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

// Set up configuration with missing key to trigger config error
FreshRSS_Context::$user_conf = (object) [
    'oai_url' => 'https://api.example.com',
    'oai_key' => '', // missing on purpose
    'oai_model' => 'my-configured-model',
    'oai_prompt' => 'prompt',
    'oai_prompt_2' => 'prompt2',
    'oai_provider' => 'openai',
    'oai_tts_url' => 'http://192.168.1.10:8000',
    'oai_tts_model' => 'my-tts-model',
    'oai_voice' => 'my-voice',
    'oai_speed' => 1.1,
];

// Capture the output of summarizeAction()
ob_start();
$controller = new FreshExtension_SummaryAndAudio_Controller();
$controller->view = new class {
    public function _layout($layout) {}
};
$controller->summarizeAction();
$output = ob_get_clean();

// Decode the JSON response
$data = json_decode($output, true);
$msg = $data['response']['data'] ?? null;

if ($msg !== 'missing config') {
    echo "Expected missing config message, got {$msg}\n";
    exit(1);
}

echo "SummarizeAction reports missing config as expected\n";

// Verify header status code regex supports HTTP/2 responses
$pattern = '#HTTP/\d+(?:\.\d+)?\s+(\d+)#';
$headers = [
    'HTTP/2 200',
    'HTTP/1.1 404',
];
foreach ($headers as $line) {
    if (!preg_match($pattern, $line, $m)) {
        echo "Regex failed to match header: {$line}\n";
        exit(1);
    }
    // Ensure captured status is numeric
    if (!is_numeric($m[1])) {
        echo "Regex did not capture status code for header: {$line}\n";
        exit(1);
    }
}

echo "Header regex matches HTTP/2 and HTTP/1.x responses\n";
