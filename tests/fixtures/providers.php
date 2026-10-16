<?php
// Local mock only; no external service or real credential is used.
$base = getenv('TOOLKIT_TEST_PREFIX');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true);
file_put_contents($base . '.requests', json_encode(['path' => $path,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => $body]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$mode = trim((string) @file_get_contents($base . '.mode'));
if (strpos($path, '/genrouter/') === 0 && $mode === 'fail') {
    http_response_code(402);
    echo '{"error":{"message":"Insufficient balance"}}';
} elseif (substr($path, -7) === '/models') {
    $models = [
        ['id' => 'vendor/test', 'name' => 'Fixture Vision', 'architecture' => ['input_modalities' => ['text', 'image']]],
        ['id' => 'plain-model'],
        ['id' => 'vendor/free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
        ['id' => '../invalid'],
    ];
    if (strpos($path, '/openrouter/') === 0 && $mode === 'large') {
        for ($i = 0; $i < 550; $i++) $models[] = ['id' => 'vendor/model-' . $i];
    }
    echo json_encode(['data' => $models]);
} else {
    $text = isset($body['response_format']) ? '{"ok":true}' : (strpos($path, '/genrouter/') === 0 ? 'GENROUTER_OK' : 'OPENROUTER_OK');
    echo json_encode(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]);
}
