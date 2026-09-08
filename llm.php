<?php
/**
 * LLM provider wrapper — OpenRouter + Yandex Foundation Models.
 * spec: spec/llm.md — infrastructural code, no customer TZ behind it.
 * (Extracted from resume-index/llm.php; report-specific logic removed.)
 *
 * Features:
 *  - Two providers behind one interface: OpenRouter (Bearer) and Yandex
 *    (Api-Key + folder, gpt:// model URIs, OpenAI-compatible endpoint).
 *  - Per-session model / provider overrides (LLM::setModelOverride / setProviderOverride).
 *  - Config-driven fallback chain: the chosen model first, then — with
 *    LLM_FALLBACK_MODE=auto (the default) — a NEWER VERSION of the same model
 *    from the catalogue (ModelCatalog::newerSiblings), then the operator's
 *    LLM_FALLBACK_MODELS, and finally each provider's per-provider fallback
 *    model walked in LLM_PROVIDER_PRIORITY order.
 *  - PDF OCR via OpenRouter vision models (file-parser plugin strategies +
 *    native) and Yandex Vision OCR, in operator-chosen priority order.
 *  - Generic chat entry points: chatText() and chatJson().
 *
 * Init once per request: LLM::init($cfg, $optionalStore).
 *   $cfg   — array from config.php
 *   $store — optional object with logLLMCall(...) (no-op if absent)
 */

require_once __DIR__ . '/model_catalog.php';   // model versions for the auto fallback

final class LLM {
    private static ?array $cfg = null;
    private static $store = null;                      // optional logger
    private static ?string $modelOverride = null;      // short id from AVAILABLE_MODELS
    private static ?string $providerOverride = null;   // 'openrouter' | 'yandex' | null

    public static function init(array $cfg, $store = null): void {
        self::$cfg = $cfg;
        self::$store = $store;
    }

    public static function cfg(): array {
        if (self::$cfg === null) throw new RuntimeException('LLM not initialized');
        return self::$cfg;
    }

    /** Per-session model override. Resolved against AVAILABLE_MODELS at call time. */
    public static function setModelOverride(?string $shortId): void {
        $shortId = $shortId !== null ? trim($shortId) : '';
        self::$modelOverride = $shortId !== '' ? $shortId : null;
    }

    /** Per-session provider override ('openrouter' | 'yandex'). NULL → use cfg.LLM_PROVIDER. */
    public static function setProviderOverride(?string $provider): void {
        $provider = $provider !== null ? strtolower(trim($provider)) : '';
        self::$providerOverride = in_array($provider, ['openrouter', 'yandex'], true) ? $provider : null;
    }

    public static function effectiveProvider(): string {
        if (self::$providerOverride !== null) return self::$providerOverride;
        return self::cfg()['LLM_PROVIDER'] ?? 'openrouter';
    }

    /** Ordered provider fallback list from LLM_PROVIDER_PRIORITY. Validates against
     *  known providers, dedupes, and guarantees both appear so a leg is never dropped. */
    private static function providerPriority(): array {
        $known = ['openrouter', 'yandex'];
        $raw = (string) (self::cfg()['LLM_PROVIDER_PRIORITY'] ?? 'openrouter,yandex');
        $list = [];
        foreach (explode(',', $raw) as $p) {
            $p = strtolower(trim($p));
            if (in_array($p, $known, true) && !in_array($p, $list, true)) $list[] = $p;
        }
        if (!$list) $list = $known;
        foreach ($known as $p) {
            if (!in_array($p, $list, true)) $list[] = $p;
        }
        return $list;
    }

    /** Fallback mode: 'auto' — newer version of the same model first, 'manual' — list only. */
    private static function fallbackMode(): string {
        $m = strtolower(trim((string) (self::cfg()['LLM_FALLBACK_MODE'] ?? 'auto')));
        return $m === 'manual' ? 'manual' : 'auto';
    }

    /** Newer versions of the chosen model — the default backup. $shortId omitted
     *  → LLM_DEFAULT_MODEL (setup.php shows the operator what 'auto' resolves to). */
    public static function autoFallbackRows(?string $shortId = null): array {
        $cfg = self::cfg();
        $row = self::findModel($shortId ?? (string) ($cfg['LLM_DEFAULT_MODEL'] ?? ''));
        if ($row === null) return [];
        return ModelCatalog::newerSiblings($row, (array) ($cfg['AVAILABLE_MODELS'] ?? []));
    }

    /** Operator-picked backup rows from LLM_FALLBACK_MODELS (short ids). */
    public static function configuredFallbackRows(): array {
        $out = [];
        foreach (explode(',', (string) (self::cfg()['LLM_FALLBACK_MODELS'] ?? '')) as $id) {
            $id = trim($id);
            if ($id === '') continue;
            $row = self::findModel($id);
            if ($row !== null && empty($row['ocr_only'])) $out[] = $row;
        }
        return $out;
    }

    /** Look up model row by short id. Returns null if unknown. */
    public static function findModel(string $shortId): ?array {
        foreach ((self::cfg()['AVAILABLE_MODELS'] ?? []) as $row) {
            if (($row['id'] ?? '') === $shortId) return $row;
        }
        return null;
    }

    /** Resolve the active model for the current request, honoring provider override. */
    private static function activeModel(): array {
        $shortId = self::$modelOverride ?: (self::cfg()['LLM_DEFAULT_MODEL'] ?? 'deepseek-r1');
        $effective = self::effectiveProvider();
        $row = self::findModel($shortId);
        // OCR-only models (Yandex Vision OCR) are not chat models.
        if ($row !== null && !empty($row['ocr_only'])) $row = null;
        if ($row !== null && self::$providerOverride !== null && ($row['provider'] ?? '') !== $effective) {
            foreach ((self::cfg()['AVAILABLE_MODELS'] ?? []) as $r) {
                if (($r['id'] ?? '') === $shortId && ($r['provider'] ?? '') === $effective) { $row = $r; break; }
            }
        }
        if ($row !== null) return $row;
        $default = self::findModel(self::cfg()['LLM_DEFAULT_MODEL'] ?? 'deepseek-r1');
        if ($default !== null) {
            if (self::$providerOverride !== null && ($default['provider'] ?? '') !== $effective) {
                $default['provider'] = $effective;
            }
            return $default;
        }
        return [
            'id' => $shortId, 'label' => $shortId, 'provider' => $effective,
            'full_id' => $shortId, 'price_in' => 0.0, 'price_out' => 0.0,
        ];
    }

    /* ──────────── Public chat entry points ──────────── */

    /** Free-form text completion (active model + provider fallback). */
    public static function chatText(string $system, string $user, ?int $sessionId = null, float $temp = 0.7): string {
        return self::callText('chat', $system, $user, $sessionId, $temp);
    }

    /** Strict-JSON completion: response_format=json_object + a stricter re-ask
     *  on malformed JSON. Returns the decoded associative array. */
    public static function chatJson(string $system, string $user, ?int $sessionId = null, float $temp = 0.1): array {
        return self::callJson('chat', $system, $user, $sessionId, $temp);
    }

    /** Multimodal strict-JSON completion: system + text + one or more images
     *  (data: URIs, e.g. "data:image/jpeg;base64,...."). Same fallback chain and
     *  JSON re-ask as chatJson() — pick a vision-capable model (setModelOverride /
     *  LLM_DEFAULT_MODEL) before calling, image-blind models will just fail their
     *  candidate slot and the chain moves on. */
    public static function visionJson(string $system, string $userText, array $imageDataUrls, ?int $sessionId = null, float $temp = 0.1): array {
        return self::callVisionJson('vision', $system, $userText, $imageDataUrls, $sessionId, $temp);
    }

    /* ──────────── PDF OCR ──────────── */

    public static function ocrPdf(string $pdfPath): ?string {
        $cfg = self::cfg();
        $bytes = @file_get_contents($pdfPath);
        if ($bytes === false) return null;
        $b64 = base64_encode($bytes);
        $dataUrl = 'data:application/pdf;base64,' . $b64;
        $messages = [
            ['role' => 'system', 'content' => 'Extract the plain text content from this PDF. Return only the raw text, preserving paragraph breaks. No commentary.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Extract all text from this PDF.'],
                ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => $dataUrl]],
            ]],
        ];
        // OCR engines tried in order; first non-empty result wins.
        //   1. file-parser plugin / pdf-text engine — free, text-based PDFs.
        //   2. file-parser plugin / mistral-ocr engine — paid, scanned PDFs.
        //   3. native — no plugin; multimodal models that accept PDFs natively.
        // Yandex Vision OCR is tried first when the operator picked the
        // yandex-vision-ocr model / forced provider=yandex / put yandex first.
        $errors = [];
        $selRow = self::$modelOverride !== null ? self::findModel(self::$modelOverride) : null;
        $priority = self::providerPriority();
        $preferYandexOcr = ($selRow !== null && !empty($selRow['ocr_only']))
            || self::effectiveProvider() === 'yandex'
            || (($priority[0] ?? '') === 'yandex');

        if ($preferYandexOcr) {
            $text = self::tryYandexVisionOcr($bytes, $errors);
            if ($text !== null) return $text;
        }

        $models = $cfg['LLM_OCR_MODELS'] ?? [];
        if (empty($models)) $models = [$cfg['LLM_VISION_MODEL']];
        $strategies = [
            ['label' => 'pdf-text',    'extra' => ['plugins' => [['id' => 'file-parser', 'pdf' => ['engine' => 'pdf-text']]]]],
            ['label' => 'mistral-ocr', 'extra' => ['plugins' => [['id' => 'file-parser', 'pdf' => ['engine' => 'mistral-ocr']]]]],
            ['label' => 'native',      'extra' => []],
        ];
        if (!empty($cfg['OPENROUTER_API_KEY'])) {
            foreach ($models as $model) {
                $row = ['provider' => 'openrouter', 'full_id' => $model];
                foreach ($strategies as $s) {
                    $tag = $model . '/' . $s['label'];
                    try {
                        $resp = self::http($row, $messages, 0.0, false, $s['extra']);
                        if ($resp === null) { $errors[] = $tag . ': empty response'; continue; }
                        $text = self::extractContent($resp);
                        if (is_string($text) && trim($text) !== '') {
                            error_log('[ocrPdf] success via ' . $tag . ' (' . mb_strlen($text) . ' chars)');
                            return $text;
                        }
                        $errors[] = $tag . ': empty content';
                    } catch (Throwable $e) {
                        $errors[] = $tag . ': ' . $e->getMessage();
                    }
                }
            }
        } else {
            $errors[] = 'openrouter: OPENROUTER_API_KEY empty';
        }

        if (!$preferYandexOcr) {
            $text = self::tryYandexVisionOcr($bytes, $errors);
            if ($text !== null) return $text;
        }

        throw new RuntimeException('PDF OCR failed: ' . implode(' | ', $errors));
    }

    /** Yandex Vision OCR (recognizeText). Concatenates per-page fullText.
     *  Gated by YANDEX_OCR_ENABLED + creds. Returns null on any problem
     *  (appending a reason to &$errors), never throws. */
    private static function tryYandexVisionOcr(string $pdfBytes, array &$errors): ?string {
        $cfg = self::cfg();
        if (($cfg['YANDEX_OCR_ENABLED'] ?? '1') !== '1') { $errors[] = 'yandex-ocr: disabled'; return null; }
        $folder = (string) ($cfg['YANDEX_FOLDER_ID'] ?? '');
        $apiKey = (string) ($cfg['YANDEX_API_KEY'] ?? '');
        if ($folder === '' || $apiKey === '') { $errors[] = 'yandex-ocr: creds empty'; return null; }
        $url = (string) ($cfg['YANDEX_OCR_URL'] ?? 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText');
        $body = [
            'mimeType' => 'application/pdf',
            'languageCodes' => ['ru', 'en'],
            'model' => (string) ($cfg['YANDEX_OCR_MODEL'] ?? 'page'),
            'content' => base64_encode($pdfBytes),
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Api-Key ' . $apiKey,
                'x-folder-id: ' . $folder,
                'x-data-logging-enabled: false',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($cfg['LLM_TIMEOUT_SEC'] ?? 120),
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 400) {
            $errors[] = 'yandex-ocr: HTTP ' . $code . ' ' . ($err ?: substr((string) $out, 0, 200));
            return null;
        }
        // recognizeText returns one JSON object per page, NDJSON-style for multi-page.
        $parts = [];
        foreach (preg_split('/\r?\n/', (string) $out) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $j = json_decode($line, true);
            if (!is_array($j)) continue;
            $full = $j['result']['textAnnotation']['fullText'] ?? ($j['textAnnotation']['fullText'] ?? null);
            if (is_string($full) && trim($full) !== '') $parts[] = $full;
        }
        if (!$parts) {
            $j = json_decode((string) $out, true);
            $full = is_array($j) ? ($j['result']['textAnnotation']['fullText'] ?? null) : null;
            if (is_string($full) && trim($full) !== '') $parts[] = $full;
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') { $errors[] = 'yandex-ocr: empty fullText'; return null; }
        error_log('[ocrPdf] success via yandex-vision-ocr (' . mb_strlen($text) . ' chars)');
        return $text;
    }

    /** One-shot (per request) email when OpenRouter failed and Yandex served the call. */
    private static function notifyOpenRouterFallback(string $step, ?int $sessionId, string $usedModel, string $primaryError): void {
        static $notified = false;
        if ($notified || !class_exists('Mailer')) return;
        $notified = true;
        try {
            Mailer::sendErrorNotification(self::cfg(), 'OpenRouter недоступен → fallback на Yandex', $primaryError !== '' ? $primaryError : 'primary openrouter call failed', [
                'step' => $step,
                'session_id' => $sessionId ?? '—',
                'used_model' => $usedModel,
            ]);
        } catch (Throwable $e) { /* never break the pipeline */ }
    }

    /* ──────────── Internals ──────────── */

    /**
     * Run two LLM calls in parallel via curl_multi. Returns [parsed_a, parsed_b].
     * On either side failing, that side falls back to sequential callJson (so the
     * provider fallback chain + JSON retry still apply).
     * Each spec: ['step'=>str, 'system'=>str, 'user'=>str, 'temp'=>float, 'json'=>bool].
     */
    public static function dispatchPair(array $specA, array $specB, ?int $sessionId): array {
        $cfg = self::cfg();
        $primary = self::activeModel();
        $provider = $primary['provider'] ?? self::effectiveProvider();

        $hasOR = !empty($cfg['OPENROUTER_API_KEY']);
        $hasYA = !empty($cfg['YANDEX_API_KEY']) && !empty($cfg['YANDEX_FOLDER_ID']);
        $providerReady = ($provider === 'openrouter' && $hasOR) || ($provider === 'yandex' && $hasYA);
        if (!$providerReady) {
            return [
                self::callJson($specA['step'], $specA['system'], $specA['user'], $sessionId, (float) $specA['temp']),
                self::callJson($specB['step'], $specB['system'], $specB['user'], $sessionId, (float) $specB['temp']),
            ];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach (['A' => $specA, 'B' => $specB] as $key => $spec) {
            $ch = self::buildCurl($primary, self::messages($spec), (float) $spec['temp'], (bool) $spec['json'], []);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = ['ch' => $ch, 'spec' => $spec, 't0' => microtime(true)];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $h) {
            $ch = $h['ch'];
            $spec = $h['spec'];
            $latency = (int) ((microtime(true) - $h['t0']) * 1000);
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $tag = ($primary['provider'] ?? '?') . ':' . ($primary['full_id'] ?? '?') . ':multi';

            if ($body === false || $code >= 400 || $err !== '') {
                self::logCall($sessionId, $spec['step'], $tag, $latency, 'multi_fail', $err ?: ('HTTP ' . $code), null);
                $results[$key] = self::callJson($spec['step'], $spec['system'], $spec['user'], $sessionId, (float) $spec['temp']);
                continue;
            }
            $data = json_decode((string) $body, true);
            $content = is_array($data) ? self::extractContent($data) : null;
            if (!is_string($content) || trim($content) === '') {
                self::logCall($sessionId, $spec['step'], $tag, $latency, 'multi_no_content', null, (string) $body);
                $results[$key] = self::callJson($spec['step'], $spec['system'], $spec['user'], $sessionId, (float) $spec['temp']);
                continue;
            }
            self::logCall($sessionId, $spec['step'], $tag, $latency, 'ok', null, $content);
            $parsed = self::parseJson($content);
            if ($parsed === null) {
                $results[$key] = self::callJson($spec['step'], $spec['system'], $spec['user'], $sessionId, (float) $spec['temp']);
                continue;
            }
            $results[$key] = $parsed;
        }
        curl_multi_close($mh);
        return [$results['A'], $results['B']];
    }

    private static function messages(array $spec): array {
        return [
            ['role' => 'system', 'content' => (string) ($spec['system'] ?? '')],
            ['role' => 'user', 'content' => (string) ($spec['user'] ?? '')],
        ];
    }

    /**
     * gpt://<folder>/<full_id>/<version> — the address every Yandex model takes,
     * gpt-oss included: the version segment is how the provider resolves the
     * model. `full_id` is stored without it, so «latest» is added here unless the
     * operator typed his own version («yandexgpt/rc», «yandexgpt/deprecated»).
     */
    private static function yandexModelUri(string $folder, string $fullId): string {
        $fullId = trim($fullId, " /");
        if (!preg_match('~/(latest|rc|deprecated)$~', $fullId)) $fullId .= '/latest';
        return 'gpt://' . $folder . '/' . $fullId;
    }

    /** Build a configured cURL handle for the active provider/model (no curl_exec). */
    private static function buildCurl(array $modelRow, array $messages, float $temp, bool $jsonMode, array $extra) {
        $cfg = self::cfg();
        $provider = $modelRow['provider'] ?? 'openrouter';
        if ($provider === 'yandex') {
            $url = $cfg['YANDEX_LLM_URL'];
            $folder = $cfg['YANDEX_FOLDER_ID'] ?? '';
            if ($folder === '' || empty($cfg['YANDEX_API_KEY'])) {
                throw new RuntimeException('Yandex LLM not configured (YANDEX_API_KEY / YANDEX_FOLDER_ID empty)');
            }
            $modelStr = self::yandexModelUri($folder, (string) $modelRow['full_id']);
            $headers = [
                'Authorization: Api-Key ' . $cfg['YANDEX_API_KEY'],
                'x-folder-id: ' . $folder,
                'Content-Type: application/json',
            ];
        } else {
            $url = $cfg['OPENROUTER_URL'];
            if (empty($cfg['OPENROUTER_API_KEY'])) {
                throw new RuntimeException('OpenRouter not configured (OPENROUTER_API_KEY empty)');
            }
            $modelStr = $modelRow['full_id'];
            $headers = [
                'Authorization: Bearer ' . $cfg['OPENROUTER_API_KEY'],
                'Content-Type: application/json',
                'HTTP-Referer: ' . ($cfg['OPENROUTER_REFERER'] ?? 'https://example.com'),
                'X-Title: ' . ($cfg['OPENROUTER_TITLE'] ?? 'site_yacloud_openrouter'),
            ];
        }
        $body = ['model' => $modelStr, 'messages' => $messages, 'temperature' => $temp];
        if ($jsonMode) $body['response_format'] = ['type' => 'json_object'];
        foreach ($extra as $k => $v) $body[$k] = $v;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $cfg['LLM_TIMEOUT_SEC'],
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        return $ch;
    }

    private static function callJson(string $step, string $system, string $user, ?int $sessionId, float $temp): array {
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        $raw = self::dispatch($step, $messages, $sessionId, $temp, true);
        $parsed = self::parseJson($raw);
        if ($parsed !== null) return $parsed;

        $retryMessages = $messages;
        $retryMessages[] = ['role' => 'user', 'content' => 'Ответ не распознан как строгий JSON. Верни ровно один JSON-объект по указанной схеме. Без markdown, без комментариев.'];
        $raw2 = self::dispatch($step . '_retry', $retryMessages, $sessionId, 0.0, true);
        $parsed = self::parseJson($raw2);
        if ($parsed !== null) return $parsed;

        throw new RuntimeException("LLM step $step: invalid JSON after retry");
    }

    /** [{type:text}, {type:image_url,image_url:{url}}, ...] — image_url first-class
     *  since GPT-4o/Gemini/Claude-on-OpenRouter all accept it; data: URIs inline. */
    private static function visionContent(string $userText, array $imageDataUrls): array {
        $parts = [];
        if ($userText !== '') $parts[] = ['type' => 'text', 'text' => $userText];
        foreach ($imageDataUrls as $url) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => (string) $url]];
        }
        return $parts;
    }

    private static function callVisionJson(string $step, string $system, string $userText, array $imageDataUrls, ?int $sessionId, float $temp): array {
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => self::visionContent($userText, $imageDataUrls)],
        ];
        $raw = self::dispatch($step, $messages, $sessionId, $temp, true);
        $parsed = self::parseJson($raw);
        if ($parsed !== null) return $parsed;

        $retryMessages = $messages;
        $retryMessages[] = ['role' => 'user', 'content' => 'Ответ не распознан как строгий JSON. Верни ровно один JSON-объект по указанной схеме. Без markdown, без комментариев.'];
        $raw2 = self::dispatch($step . '_retry', $retryMessages, $sessionId, 0.0, true);
        $parsed = self::parseJson($raw2);
        if ($parsed !== null) return $parsed;

        throw new RuntimeException("LLM step $step: invalid JSON after retry");
    }

    private static function callText(string $step, string $system, string $user, ?int $sessionId, float $temp): string {
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        return trim(self::dispatch($step, $messages, $sessionId, $temp, false));
    }

    private static function dispatch(string $step, array $messages, ?int $sessionId, float $temp, bool $json): string {
        $cfg = self::cfg();
        $primary = self::activeModel();
        $primaryProvider = $primary['provider'] ?? 'openrouter';
        // Fallback order: the chosen model, then (in auto mode) newer versions
        // of the same model, then the operator's LLM_FALLBACK_MODELS, and only
        // then each provider's per-provider fallback model.
        $candidates = [$primary];
        $hasOR = !empty($cfg['OPENROUTER_API_KEY']);
        $hasYA = !empty($cfg['YANDEX_API_KEY']) && !empty($cfg['YANDEX_FOLDER_ID']);
        $named = self::fallbackMode() === 'auto'
            ? array_merge(self::autoFallbackRows($primary['id'] ?? null), self::configuredFallbackRows())
            : self::configuredFallbackRows();
        foreach ($named as $row) {
            $prov = $row['provider'] ?? 'openrouter';
            if ($prov === 'openrouter' && !$hasOR) continue;
            if ($prov === 'yandex' && !$hasYA) continue;
            // A slug never travels to the other provider: the row keeps its own.
            if (($row['full_id'] ?? '') === ($primary['full_id'] ?? '') && $prov === $primaryProvider) continue;
            $candidates[] = $row;
        }
        $fallbackModels = [
            'openrouter' => (string) ($cfg['LLM_FALLBACK_MODEL'] ?? 'openrouter/auto'),
            'yandex'     => (string) ($cfg['YANDEX_FALLBACK_MODEL'] ?? 'deepseek-r1'),
        ];
        foreach (self::providerPriority() as $prov) {
            if ($prov === 'openrouter' && !$hasOR) continue;
            if ($prov === 'yandex' && !$hasYA) continue;
            $fbModel = $fallbackModels[$prov] ?? '';
            if ($fbModel === '') continue;
            if ($prov === $primaryProvider && $fbModel === (string) ($primary['full_id'] ?? '')) continue;
            $candidates[] = ['provider' => $prov, 'full_id' => $fbModel, 'id' => 'fallback_' . $prov, 'label' => 'fallback_' . $prov];
        }
        // One attempt per provider+slug pair, in the order built above.
        $seen = [];
        $candidates = array_values(array_filter($candidates, static function (array $row) use (&$seen) {
            $key = ($row['provider'] ?? '?') . '|' . ($row['full_id'] ?? '?');
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
        $lastError = null;
        foreach ($candidates as $idx => $row) {
            $t0 = microtime(true);
            $tag = $row['provider'] . ':' . $row['full_id'];
            try {
                $resp = self::http($row, $messages, $temp, $json);
                $latency = (int) ((microtime(true) - $t0) * 1000);
                if ($resp === null) {
                    $lastError = 'empty response';
                    self::logCall($sessionId, $step, $tag, $latency, 'empty', $lastError, null);
                    continue;
                }
                $content = self::extractContent($resp);
                if (!is_string($content) || trim($content) === '') {
                    $lastError = 'no content field';
                    self::logCall($sessionId, $step, $tag, $latency, 'no_content', $lastError, json_encode($resp));
                    continue;
                }
                self::logCall($sessionId, $step, $tag, $latency, 'ok', null, $content);
                if ($idx > 0 && $primaryProvider === 'openrouter' && ($row['provider'] ?? '') === 'yandex') {
                    self::notifyOpenRouterFallback($step, $sessionId, $tag, (string) $lastError);
                }
                return $content;
            } catch (Throwable $e) {
                $latency = (int) ((microtime(true) - $t0) * 1000);
                $lastError = $e->getMessage();
                self::logCall($sessionId, $step, $tag, $latency, 'exception', $lastError, null);
            }
        }
        throw new RuntimeException("LLM $step failed: $lastError");
    }

    /** $modelRow keys: provider, full_id. $extra is merged into the request body. */
    private static function http(array $modelRow, array $messages, float $temp, bool $jsonMode = false, array $extra = []): ?array {
        $cfg = self::cfg();
        $provider = $modelRow['provider'] ?? 'openrouter';

        if ($provider === 'yandex') {
            $url = $cfg['YANDEX_LLM_URL'];
            $folder = $cfg['YANDEX_FOLDER_ID'] ?? '';
            if ($folder === '' || empty($cfg['YANDEX_API_KEY'])) {
                throw new RuntimeException('Yandex LLM not configured (YANDEX_API_KEY / YANDEX_FOLDER_ID empty)');
            }
            $modelStr = self::yandexModelUri($folder, (string) $modelRow['full_id']);
            $headers = [
                'Authorization: Api-Key ' . $cfg['YANDEX_API_KEY'],
                'x-folder-id: ' . $folder,
                'Content-Type: application/json',
            ];
        } else {
            $url = $cfg['OPENROUTER_URL'];
            if (empty($cfg['OPENROUTER_API_KEY'])) {
                throw new RuntimeException('OpenRouter not configured (OPENROUTER_API_KEY empty)');
            }
            $modelStr = $modelRow['full_id'];
            $headers = [
                'Authorization: Bearer ' . $cfg['OPENROUTER_API_KEY'],
                'Content-Type: application/json',
                'HTTP-Referer: ' . ($cfg['OPENROUTER_REFERER'] ?? 'https://example.com'),
                'X-Title: ' . ($cfg['OPENROUTER_TITLE'] ?? 'site_yacloud_openrouter'),
            ];
        }

        $body = ['model' => $modelStr, 'messages' => $messages, 'temperature' => $temp];
        if ($jsonMode) $body['response_format'] = ['type' => 'json_object'];
        foreach ($extra as $k => $v) $body[$k] = $v;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $cfg['LLM_TIMEOUT_SEC'],
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 400) {
            throw new RuntimeException(strtoupper($provider) . " HTTP $code: " . ($err ?: substr((string) $out, 0, 300)));
        }
        $data = json_decode((string) $out, true);
        return is_array($data) ? $data : null;
    }

    private static function extractContent(array $resp): ?string {
        $c = $resp['choices'][0]['message']['content'] ?? null;
        if (is_string($c)) return $c;
        if (is_array($c)) {
            $parts = [];
            foreach ($c as $p) {
                if (isset($p['text']) && is_string($p['text'])) $parts[] = $p['text'];
            }
            return $parts ? implode("\n", $parts) : null;
        }
        return null;
    }

    private static function parseJson(string $raw): ?array {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($raw, $start, $end - $start + 1);
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) return $parsed;
        }
        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : null;
    }

    /** {{token}} substitution helper for prompt templates. */
    public static function render(string $tpl, array $vars): string {
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{{' . $k . '}}', (string) $v, $tpl);
        }
        return $tpl;
    }

    public static function jsonCompact($v): string {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function logCall(?int $sessionId, string $step, string $model, int $latency, string $status, ?string $error, ?string $raw): void {
        if (self::$store === null || !method_exists(self::$store, 'logLLMCall')) return;
        try {
            self::$store->logLLMCall($sessionId, $step, $model, self::$cfg['PROMPT_VERSION'] ?? 'v1', $latency, $status, $error, $raw);
        } catch (Throwable $e) { /* swallow log errors */ }
    }
}
