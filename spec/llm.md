# llm.php — LLM provider layer (OpenRouter + Yandex Cloud)

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

`final class LLM` — static, one instance per request. Two providers behind one
interface, per-request model/provider override, config-driven fallback (newer version of
the same model, then the operator's list, then per-provider fallbacks), vision
(images) and PDF OCR, provider self-test, optional call logging.

## 1. Lifecycle

```php
LLM::init(array $cfg, $store = null): void   // $cfg = require config.php
LLM::cfg(): array                            // throws if init() was not called
```

`$store` — optional duck-typed logger; used only if it exposes
`logLLMCall($sessionId, $step, $model, $promptVersion, $latencyMs, $status, $error, $raw)`.
Absent/failing logger never breaks a call (§7).

## 2. Model & provider resolution

| Function | Contract |
|---|---|
| `setModelOverride(?string $shortId)`   | per-request model; short id from `AVAILABLE_MODELS`; `null` clears |
| `setProviderOverride(?string $p)`      | `'openrouter'` \| `'yandex'`; `null` → `cfg.LLM_PROVIDER` |
| `effectiveProvider(): string`          | override ?: `cfg.LLM_PROVIDER` |
| `findModel(string $shortId): ?array`   | row from `AVAILABLE_MODELS` by `id`, else `null` |
| `resolveModelSpec(string $spec): ?array` | `"yandex:<slug>"` \| short `id` \| bare `full_id` → row. A provider-qualified slug absent from the catalogue still resolves, keeping its stated provider |
| `rowIsVision(array $row): bool`        | row accepts images; missing `vision` key = «maybe» (never skipped) |
| `visionModelRow(): ?array`             | `LLM_VISION_MODEL` through `resolveModelSpec`, `ocr_only` rows rejected |
| `visionModelRows(): array`             | every row with `vision === true` — the vision fallback pool and the admin dropdown |
| `configSummary(): array`               | non-secret snapshot of provider/model/key state, attached to every failure |
| `activeModel(): array` *(private)*     | override model → else `LLM_DEFAULT_MODEL` row → honours provider override |
| `providerPriority(): array` *(private)* | `LLM_PROVIDER_PRIORITY` split/validated against the two known providers |

Model row shape: `['id','label','provider','full_id','price_in','price_out','vision'?,'ocr_only'?,'live'?]`.
`full_id` semantics: OpenRouter — the model slug sent as-is; Yandex — the slug inside
`gpt://<YANDEX_FOLDER_ID>/<full_id>/<version>`, where the version segment is added by
`yandexModelUri()` as `/latest` unless the operator already wrote `/latest`, `/rc` or
`/deprecated`. `vision` — `true` the model takes images, `false` it does not, absent =
unknown (treated as «maybe»).

## 3. Entry points

```php
LLM::chatText(string $system, string $user, ?int $sessionId = null, float $temp = 0.7): string
LLM::chatJson(string $system, string $user, ?int $sessionId = null, float $temp = 0.1): array
LLM::visionJson(string $system, string $userText, array $imageDataUrls, ?int $sessionId = null, float $temp = 0.1): array
LLM::probe(): array                                 // provider self-test, never throws
LLM::lastTrace(): array                             // per-candidate outcome of the last dispatch
LLM::traceText(): string
LLM::dispatchPair(array $specA, array $specB, ?int $sessionId): array   // [parsedA, parsedB]
LLM::ocrPdf(string $pdfPath): ?string
LLM::render(string $tpl, array $vars): string       // {{token}} substitution
LLM::jsonCompact($v): string                        // JSON_UNESCAPED_UNICODE|SLASHES
```

- `chatText` → `callText` → `dispatch(json:false)`, result trimmed.
- `visionJson` → `callVisionJson` → `dispatch(json:true, vision:true)`. The user turn is
  `[{type:text},{type:image_url,image_url:{url}},…]`; images are `data:` URIs. Same JSON
  re-ask as `chatJson`.
- `probe()` — one minimal completion per configured leg (default model, vision model,
  each per-provider fallback). Returns `[{leg,model,ok,text}]`, never throws, writes every
  leg to the diagnostic log (`/spec/diag_log.md`).
- `chatJson` → `callJson` → `dispatch(json:true)` + `parseJson`. On unparseable JSON:
  one stricter re-ask (`step` suffixed `_retry`, `temp=0.0`, extra user turn demanding a
  single JSON object, no markdown). Still unparseable → `RuntimeException`.
- `dispatchPair` — two calls in parallel via `curl_multi` on the **primary** model only
  (spec: `['step','system','user','temp','json']`). Provider not configured → both fall
  back to sequential `callJson`. Per-side transport failure / empty content / unparseable
  JSON → that side is retried through `callJson`, so the fallback chain and the JSON
  re-ask still apply. Statuses logged: `multi_fail`, `multi_no_content`, `ok`.

## 4. Fallback chain — `dispatch()`

`dispatch($step, $messages, $sessionId, $temp, $json, $vision = false)`.

Candidates, in order:

1. The primary model: `activeModel()`, or — when `$vision` — `visionModelRow()` if
   `LLM_VISION_MODEL` resolves, falling back to `activeModel()`.
2. **Newer versions of that same model**, newest first — only when
   `LLM_FALLBACK_MODE` is `auto` (the default). They come from
   `LLM::autoFallbackRows()` → `ModelCatalog::newerSiblings()`
   (`/spec/model_catalog.md` §5), so a refreshed live catalogue immediately supplies a
   fresher backup (`claude-sonnet-4.5` → `claude-sonnet-4.6`). A model whose slug carries
   no readable version (`yandexgpt`, `gpt-4o`) simply contributes nothing here.
3. The operator's `LLM_FALLBACK_MODELS` — comma-separated **short ids** resolved through
   `findModel()` (`LLM::configuredFallbackRows()`; unknown ids and `ocr_only` rows are
   dropped). With `LLM_FALLBACK_MODE=manual` this is the only configured backup.
4. When `$vision`: every `visionModelRows()` row (the whole known-multimodal pool).
5. For each provider in `providerPriority()`, its per-provider fallback model
   (`LLM_FALLBACK_MODEL` for openrouter, `YANDEX_FALLBACK_MODEL` for yandex) — **skipped
   when the provider has live catalogue rows and the slug is not among them**
   (`slugInCatalogue()` / `hasLiveRows()`). A blind fallback the cloud folder does not
   serve answers `Failed to get model` and masks the real reason the chain got that far;
   the skip is recorded in the trace as `пропущен: модели нет в каталоге провайдера`.

When `$vision`, every candidate whose row says `vision === false` is dropped
(`rowIsVision()`), so a photo never burns attempts on text-only models.

Steps 2–4 keep each row's own provider — **a slug never travels to the other provider**.
A provider is skipped when its credentials are absent (openrouter: `OPENROUTER_API_KEY`;
yandex: `YANDEX_API_KEY` **and** `YANDEX_FOLDER_ID`); a candidate identical to the primary
`full_id` on the primary provider is skipped, and the final list is deduplicated by
`provider|full_id` so no pair is attempted twice.

Walk candidates in order; each attempt is logged with `provider:full_id` as the model tag:

| Outcome | Status logged | Action |
|---|---|---|
| HTTP ok, content non-empty | `ok` | return content |
| response not an array | `empty` | next candidate |
| no `choices[0].message.content` | `no_content` | next candidate |
| transport/HTTP ≥ 400 / throw | `exception` | next candidate |

Each attempt also lands in `self::$trace` (`lastTrace()` / `traceText()`):
`['candidate' => 'provider:slug', 'result' => …, 'latency_ms' => …]`.

All candidates exhausted → `RuntimeException` carrying the **whole chain**, not the last
error alone: `"LLM <step> failed (<n> попыток): [1] <provider:slug> → <error>; [2] …"`.
(With `openrouter → yandex`, the last line used to be all the operator saw, so a missing
OpenRouter key read as a Yandex problem.) The same failure is written to the diagnostic
log with `configSummary()` attached — `/spec/diag_log.md`.

**Cross-provider notification.** When the primary provider was `openrouter` and a
`yandex` candidate served the call, `notifyOpenRouterFallback()` fires
`Mailer::sendErrorNotification` once per request (static guard), only if the `Mailer`
class is loaded; any error inside is swallowed.

## 5. HTTP transport

`buildCurl()` builds the handle (shared by `http()` and `dispatchPair`);
`http()` = `buildCurl` + `curl_exec` + decode.

| Provider | URL | Headers | Model string |
|---|---|---|---|
| `openrouter` | `OPENROUTER_URL` | `Authorization: Bearer <OPENROUTER_API_KEY>`, `HTTP-Referer` (`OPENROUTER_REFERER`), `X-Title` (`OPENROUTER_TITLE`) | `full_id` |
| `yandex` | `YANDEX_LLM_URL` (OpenAI-compatible) | `Authorization: Api-Key <YANDEX_API_KEY>`, `x-folder-id` | `yandexModelUri()` → `gpt://<folder>/<full_id>/latest` |

Body: `{model, messages, temperature}` + `response_format={"type":"json_object"}` when
`$jsonMode` + any `$extra` keys merged in (used by OCR strategies). Timeouts:
`CURLOPT_TIMEOUT = LLM_TIMEOUT_SEC`, `CURLOPT_CONNECTTIMEOUT = 15`. Missing credentials →
throws before the request. HTTP ≥ 400 or transport error → `RuntimeException`
`"<PROVIDER> HTTP <code> @ <url> model=<model string>[ json_object]: <curl error | first
400 chars of body>"` — the endpoint and the model string as the provider saw it are part
of the message, because `YANDEX HTTP 400: Failed to get model` alone names no model.

Response helpers: `extractContent()` reads `choices[0].message.content`, joining
`[{text:…}]` parts with `\n`; `parseJson()` strips ```` ```json ```` fences, then tries the
outermost `{…}` slice, then the raw string.

## 6. PDF OCR — `ocrPdf()`

Input file → base64 `data:application/pdf;base64,…` in an OpenAI-style multimodal
message (`type:file`, `filename:document.pdf`) with a "return only raw text" system prompt.

**Yandex-first condition** (`$preferYandexOcr`): the override model row has
`ocr_only`, **or** `effectiveProvider() === 'yandex'`, **or** the first entry of
`LLM_PROVIDER_PRIORITY` is `yandex`.

Order:
1. `tryYandexVisionOcr()` — only when Yandex-first.
2. OpenRouter: for each model of `LLM_OCR_MODELS` (empty → `[LLM_VISION_MODEL]`), three
   strategies in order — file-parser plugin `pdf-text` (free, text PDFs) → file-parser
   plugin `mistral-ocr` (paid, scans) → `native` (no plugin). First non-empty content
   wins; success logged as `[ocrPdf] success via <model>/<strategy> (<chars> chars)`.
   Skipped entirely when `OPENROUTER_API_KEY` is empty.
3. `tryYandexVisionOcr()` — when not Yandex-first (final fallback).

Nothing produced text → `RuntimeException('PDF OCR failed: ' . <all reasons joined by " | ">)`.

`LLM_OCR_MODELS` entries take the same spellings as `LLM_VISION_MODEL` and are resolved
through `resolveModelSpec()`; a non-OpenRouter row is not sent a PDF (Yandex chat models
take images, not files) and is noted in the error list — PDF on Yandex goes through Yandex
Vision OCR.

`tryYandexVisionOcr(string $pdfBytes, array &$errors): ?string` *(private)* — POSTs
`{mimeType:'application/pdf', languageCodes:[ru,en], model:YANDEX_OCR_MODEL, content:<b64>}`
to `YANDEX_OCR_URL` with the Api-Key + folder headers; concatenates per-page
`result.textAnnotation.fullText` (NDJSON or single JSON). Gated by
`YANDEX_OCR_ENABLED === '1'` and non-empty key + folder. Never throws — returns `null`
and appends a reason (`yandex-ocr: disabled` / `creds empty` / HTTP or parse detail).

## 7. Call logging

`logCall()` *(private)* forwards to `$store->logLLMCall(...)` with
`PROMPT_VERSION` from config; no store, no `logLLMCall` method, or a throwing store → no-op.
Statuses used: `ok`, `empty`, `no_content`, `exception`, `multi_fail`, `multi_no_content`.

`diag()` *(private)* additionally writes to `DiagLog` **if that class is loaded**
(`/spec/diag_log.md`) — a consuming project that did not vendor it loses nothing.

## 8. CLI smoke modes (`example.php`)

```
php example.php chat  "<prompt>"    # LLM::chatText
php example.php ocr   <file.pdf>    # LLM::ocrPdf
php example.php parse <file.docx>   # Parser::extract + normalize
php example.php email <address>     # Mailer::sendCustom
```
