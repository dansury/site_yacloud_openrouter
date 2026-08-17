# config.php · settings_store.php · setup.php — configuration & operator settings

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

## 1. `config.php` — resolution

`require __DIR__ . '/config.php'` returns the config array. Per key, highest priority
first:

1. `settings` DB row (whitelisted keys only, written by `setup.php`)
2. process ENV (`cfg_env()` — empty string counts as absent)
3. hardcoded fallback in `config.php`

`cfg_env(string $key, ?string $default = null): ?string` and
`cfg_settings_whitelist(): array` are declared `function_exists`-guarded, so the file is
safe to `require` more than once per request (`setup.php` re-reads it after saving).

**Overlay** (closure at the end of the file): opens `DB_PATH` read-only-ish via PDO,
`SELECT key, value FROM settings`, and applies rows whose key is whitelisted and whose
value is non-empty. Type handling: `SMTP_PORT` → int; `LLM_OCR_MODELS` → comma-split
into a trimmed array; everything else → string. **Best-effort** — missing DB file,
missing table, locked DB, or any `Throwable` leaves the ENV/hardcoded values in place
and never breaks config loading.

### Whitelist (operator-editable)

`LLM_PROVIDER`, `LLM_DEFAULT_MODEL`, `LLM_PROVIDER_PRIORITY`, `OPENROUTER_API_KEY`,
`LLM_VISION_MODEL`, `LLM_FALLBACK_MODEL`, `LLM_OCR_MODELS`, `YANDEX_FALLBACK_MODEL`,
`YANDEX_API_KEY`, `YANDEX_FOLDER_ID`, `YANDEX_LLM_URL`, `YANDEX_OCR_URL`,
`YANDEX_OCR_MODEL`, `YANDEX_OCR_ENABLED`, `ADMIN_EMAIL`, `ERROR_EMAIL`, `SMTP_HOST`,
`SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`, `ADMIN_PASSWORD`.

Keys outside the whitelist are ENV/code-only (e.g. `OPENROUTER_URL`, `LLM_TIMEOUT_SEC`,
`LLM_MAX_RETRIES`, `DB_PATH`, `LOG_DIR`, `PROMPT_VERSION`, `AVAILABLE_MODELS`).

### Key groups & defaults

| Group | Keys (default) |
|---|---|
| Provider switch | `LLM_PROVIDER` (`openrouter`), `LLM_DEFAULT_MODEL` (`gemini-2.0-flash`), `LLM_PROVIDER_PRIORITY` (`openrouter,yandex`) |
| OpenRouter | `OPENROUTER_API_KEY` (``), `OPENROUTER_URL` (chat/completions), `LLM_VISION_MODEL`, `LLM_FALLBACK_MODEL` (`openrouter/auto`), `LLM_OCR_MODELS` (array, comma-split from ENV) |
| Yandex Cloud | `YANDEX_API_KEY`, `YANDEX_FOLDER_ID`, `YANDEX_LLM_URL` (OpenAI-compatible), `YANDEX_OCR_URL` (`…/ocr/v1/recognizeText`), `YANDEX_OCR_MODEL` (`page`), `YANDEX_OCR_ENABLED` (`1`), `YANDEX_FALLBACK_MODEL` (`deepseek-r1`) |
| Timeouts | `LLM_TIMEOUT_SEC` (120), `LLM_MAX_RETRIES` (2) |
| Admin gate | `ADMIN_PASSWORD` (``) |
| Mail | `ADMIN_EMAIL`, `ERROR_EMAIL`, `SMTP_HOST` (`smtp.yandex.ru`), `SMTP_PORT` (465), `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` (← `SMTP_USER`), `SMTP_FROM_NAME` |
| Storage | `DB_PATH` (`data/app.db`), `LOG_DIR` (`data/logs`), `PROMPT_VERSION` |

No secret ever has a non-empty default in code.

### `AVAILABLE_MODELS`

Row: `['id','label','provider','full_id','price_in','price_out']` (+ `'ocr_only' => true`
for OCR-only entries). `price_in`/`price_out` are approximate RUB per 1k tokens, shown
for operator orientation only — nothing in the code charges by them. `provider` is
`yandex` or `openrouter`; `full_id` is the provider-native id (Yandex: the slug used in
`gpt://<folder>/<full_id>/latest`). The `LLM_DEFAULT_MODEL` dropdown in `setup.php` is
built from this list with `ocr_only` rows filtered out. Adding a model = adding a row.

## 2. `settings_store.php` — `SettingsStore`

```php
new SettingsStore(string $dbPath)          // mkdir -p dirname, PDO sqlite, ERRMODE_EXCEPTION
$store->pdo(): PDO
$store->getSetting(string $key, ?string $default = null): ?string
$store->setSetting(string $key, string $value): void   // UPSERT, updated_at = UTC ISO-8601
$store->allSettings(): array                            // [key => value]
```

Schema (created on construction):

```
TABLE settings
  key         TEXT PRIMARY KEY
  value       TEXT NOT NULL
  updated_at  TEXT NOT NULL      -- gmdate('Y-m-d\TH:i:s\Z')
```

No other table is owned by this repo.

## 3. `setup.php` — operator page

Standalone PHP page (`declare(strict_types=1)`), Russian UI, `display_errors` off +
`log_errors` on, `X-Robots-Tag: noindex, nofollow` + a `robots` meta, `Cache-Control: no-store`.

**Auth.** PHP session; `POST action=login` compares the password with `ADMIN_PASSWORD`
via `hash_equals` and only when that value is non-empty (empty admin password → login
impossible, never open). `?logout=1` clears the session. Unauthenticated requests get
the login form and `exit`.

**Save** (`POST action=save`, default action): each whitelisted string field is trimmed
and written through `setSetting` **only when non-empty** — blanks never overwrite an
existing value (that is what lets the masked key/password placeholders work).
`YANDEX_OCR_ENABLED` is a checkbox and is therefore always written (`'1'` / `'0'`).
The confirmation message lists the keys that were edited.

**SMTP test** (`POST smtp_test`): re-reads `config.php` (so just-saved values apply),
picks `smtp_test_to` or `ADMIN_EMAIL`, calls `Mailer::sendTest` and renders ✅ with
host:port or ⚠️ with the exception message.

**Rendering.** After handling POST the page re-loads `config.php` and `allSettings()`;
`$eff($key)` shows the effective value (saved overlay → config, arrays joined by `,`).
Secrets are never echoed back: `$mask()` renders bullets for passwords, `$mask_key()`
renders `abcd…wxyz` for API keys. All output goes through `htmlspecialchars`.

**Form sections:** Провайдер и модели (provider, priority, default model, vision model,
per-provider fallback models, OpenRouter OCR chain, Yandex OCR model + enable checkbox) ·
API-ключи (OpenRouter key, Yandex key + folder) · Почта (host, port, user, password,
from, from name, `ADMIN_EMAIL`, `ERROR_EMAIL`) · Доступ (`ADMIN_PASSWORD`) · Тест SMTP.

Deployment note: the page must not be reachable without TLS, and `ADMIN_PASSWORD` must
be set before the file is exposed to the web.
