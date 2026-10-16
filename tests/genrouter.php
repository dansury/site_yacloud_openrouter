<?php
require_once dirname(__DIR__) . '/llm.php';
require_once dirname(__DIR__) . '/settings_store.php';
$tmp = sys_get_temp_dir() . '/toolkit-genrouter-' . getmypid();
$server = null;
register_shutdown_function(static function () use (&$server, $tmp) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($tmp . '*') as $file) @unlink($file);
});
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$env = getenv();
$env['TOOLKIT_TEST_PREFIX'] = $tmp;
file_put_contents($tmp . '.mode', 'ok');
file_put_contents($tmp . '.requests', '');
$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/providers.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '.server', 'a'], 2 => ['file', $tmp . '.server', 'a']], $pipes, dirname(__DIR__), $env);
fclose($pipes[0]);
for ($i = 0; $i < 100; $i++) {
    $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
    if ($probe) { fclose($probe); break; }
    usleep(20000);
}
$cfg = array_replace(require dirname(__DIR__) . '/config.php', [
    'DB_PATH' => $tmp . '.db', 'LLM_PROVIDER' => 'genrouter',
    'LLM_DEFAULT_MODEL' => 'genrouter:vendor/test', 'LLM_ENABLED_GENROUTER' => 1,
    'LLM_ENABLED_YANDEX' => 0, 'LLM_PROVIDER_PRIORITY' => 'genrouter,openrouter',
    'GENROUTER_API_KEY' => 'fixture-genrouter-key', 'GENROUTER_MODEL' => 'vendor/test',
    'GENROUTER_BASE_URL' => 'http://' . $address . '/genrouter/v1/',
    'OPENROUTER_API_KEY' => 'fixture-openrouter-key', 'LLM_FALLBACK_MODEL' => 'vendor/backup',
    'OPENROUTER_URL' => 'http://' . $address . '/openrouter/v1/chat/completions',
    'LLM_FALLBACK_MODE' => 'manual', 'LLM_FALLBACK_MODELS' => '', 'LLM_TIMEOUT_SEC' => 3,
    'LLM_PROXY' => '', 'LLM_VISION_MODEL' => 'genrouter:vendor/test',
]);
$fail = 0;
function checkGr(string $label, bool $ok): void { global $fail; echo ($ok ? 'ok ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }
function requestsGr(): array { global $tmp; return array_map(static function ($line) { return json_decode($line, true); }, array_filter(explode("\n", file_get_contents($tmp . '.requests')))); }
function throwsGr(callable $fn, string $message): bool { try { $fn(); } catch (RuntimeException $e) { return strpos($e->getMessage(), $message) !== false; } return false; }
ToolkitLLM::init($cfg);
checkGr('GenRouter text', ToolkitLLM::chatText('system', 'user') === 'GENROUTER_OK');
checkGr('GenRouter JSON', ToolkitLLM::chatJson('system', 'user') === ['ok' => true]);
checkGr('GenRouter images', ToolkitLLM::visionJson('system', 'image', ['data:image/png;base64,AA==']) === ['ok' => true]);
$calls = requestsGr();
checkGr('Own Bearer key and endpoint', $calls[0]['auth'] === 'Bearer fixture-genrouter-key' && $calls[0]['path'] === '/genrouter/v1/chat/completions');
checkGr('Exact model and JSON request', $calls[1]['body']['model'] === 'vendor/test' && $calls[1]['body']['response_format']['type'] === 'json_object');
checkGr('Images retain OpenAI format', $calls[2]['body']['messages'][1]['content'][1]['type'] === 'image_url');
$spec = ['step' => 'pair', 'system' => 'system', 'user' => 'user', 'temp' => 0.0, 'json' => true];
checkGr('Parallel GenRouter calls', ToolkitLLM::dispatchPair($spec, $spec, null) === [['ok' => true], ['ok' => true]]);
$before = count(requestsGr());
checkGr('Provider test only asks its chosen leg', count(ToolkitLLM::probe('genrouter')) === 1 && count(requestsGr()) === $before + 1);
$store = new SettingsStore($tmp . '.db');
$report = ModelCatalog::refresh($cfg, $store);
$cached = ModelCatalog::decode((string) $store->getSetting(ModelCatalog::KEY_MODELS));
$gen = array_values(array_filter($cached, static function ($r) { return $r['provider'] === 'genrouter'; }));
checkGr('Live catalog accepts plain slugs, rejects traversal', count($gen) === 3 && in_array('plain-model', array_column($gen, 'full_id'), true));
checkGr('Catalog IDs preserve punctuation without collisions', count(array_filter($gen, static function ($r) { return $r['id'] === 'genrouter:' . $r['full_id']; })) === count($gen));
checkGr('Unknown price is not free', empty($gen[array_search('plain-model', array_column($gen, 'full_id'), true)]['free']));
checkGr('Explicit zero price is free', !empty($gen[array_search('vendor/free', array_column($gen, 'full_id'), true)]['free']));
$merged = ModelCatalog::merge($cfg['AVAILABLE_MODELS'], $cached);
checkGr('GenRouter live catalog is authoritative', !in_array('anthropic/claude-opus-5', array_column(array_filter($merged, static function ($r) { return $r['provider'] === 'genrouter'; }), 'full_id'), true));
file_put_contents($tmp . '.mode', 'fail');
checkGr('402 falls back to OpenRouter', ToolkitLLM::chatText('system', 'user') === 'OPENROUTER_OK');
$calls = requestsGr();
checkGr('Fallback uses independent key', end($calls)['auth'] === 'Bearer fixture-openrouter-key');
checkGr('Balance errors are explained', strpos(ToolkitLLM::failureReason([['candidate' => 'genrouter:test', 'result' => 'HTTP 402']]), 'средств') !== false);
ModelCatalog::refresh($cfg, $store);
checkGr('Partial refresh keeps failing provider cache', count(array_filter(ModelCatalog::decode((string) $store->getSetting(ModelCatalog::KEY_MODELS)), static function ($r) { return $r['provider'] === 'genrouter'; })) === 3);
checkGr('Explicit failed refresh retains cache', throwsGr(static function () use ($cfg, $store) { ModelCatalog::refresh($cfg, $store, 'genrouter'); }, '402'));
file_put_contents($tmp . '.mode', 'large');
ModelCatalog::refresh($cfg, $store);
$cached = ModelCatalog::decode((string) $store->getSetting(ModelCatalog::KEY_MODELS));
checkGr('Large OpenRouter catalog does not crowd GenRouter out', count($cached) === 503);
ModelCatalog::forget($store, 'genrouter');
checkGr('Independent forget preserves OpenRouter', count(ModelCatalog::decode((string) $store->getSetting(ModelCatalog::KEY_MODELS))) === 500);
ToolkitLLM::setProviderOverride('openrouter');
checkGr('Provider override uses its own model slug', ToolkitLLM::candidateChain(false)['primary']['full_id'] === 'vendor/backup');
ToolkitLLM::setProviderOverride(null);
$disabled = array_replace($cfg, ['LLM_ENABLED_GENROUTER' => 0]);
ToolkitLLM::init($disabled);
checkGr('Disabled primary switches to enabled provider', ToolkitLLM::candidateChain(false)['primary']['provider'] === 'openrouter');
$before = count(requestsGr());
checkGr('Disabled explicit model rejected', throwsGr(static function () { ToolkitLLM::setModelOverride('genrouter:vendor/test'); }, 'отключён'));
checkGr('Disabled explicit provider rejected', throwsGr(static function () { ToolkitLLM::setProviderOverride('genrouter'); }, 'отключён'));
checkGr('Disabled refresh rejected', throwsGr(static function () use ($disabled, $store) { ModelCatalog::refresh($disabled, $store, 'genrouter'); }, 'отключён'));
checkGr('Disabled explicit test rejected', throwsGr(static function () { ToolkitLLM::probe('genrouter'); }, 'отключён'));
checkGr('Disabled actions do no network', count(requestsGr()) === $before);
ToolkitLLM::init(array_replace($disabled, ['LLM_ENABLED_OPENROUTER' => 0]));
checkGr('All disabled produces empty candidate list', ToolkitLLM::candidateChain(false)['candidates'] === []);
checkGr('All disabled generation has no network', throwsGr(static function () { ToolkitLLM::chatText('s', 'u'); }, 'failed') && count(requestsGr()) === $before);
checkGr('All disabled probe has no network', ToolkitLLM::probe() === [] && count(requestsGr()) === $before);
file_put_contents($tmp . '.pdf', '%PDF-fixture');
checkGr('All disabled OCR has no network', throwsGr(static function () use ($tmp) { ToolkitLLM::ocrPdf($tmp . '.pdf'); }, 'PDF OCR failed') && count(requestsGr()) === $before);
checkGr('GenRouter proxy defaults direct', LLMProviders::proxyOptions(array_replace($cfg, ['LLM_PROXY' => 'http://proxy.fixture:3128']), 'genrouter') === []);
$proxy = LLMProviders::proxyOptions(array_replace($cfg, ['LLM_PROXY' => 'http://proxy.fixture:3128', 'LLM_PROXY_GENROUTER' => 1, 'LLM_PROXY_AUTH' => 'fixture:secret']), 'genrouter');
checkGr('Independent proxy includes credentials only in curl', $proxy[CURLOPT_PROXY] === 'http://proxy.fixture:3128' && $proxy[CURLOPT_PROXYUSERPWD] === 'fixture:secret');
echo 'GenRouter checks failed: ' . $fail . "\n";
exit($fail ? 1 : 0);
