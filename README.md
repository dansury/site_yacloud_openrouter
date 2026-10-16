# site_yacloud_openrouter (PHP)

Reusable **infrastructure** building blocks — LLM providers (Yandex Cloud +
OpenRouter), document parsing/OCR, SMTP mail, operator-editable settings.
Pure PHP, no Composer — only `ext-curl`, `ext-pdo_sqlite`, `ext-zip` (DOCX),
`ext-dom`.

This repository is the **canonical source** of that toolkit: consuming projects
take the code from here instead of vendoring their own copy. The code is purely
infrastructural — it implements no customer requirement (no TZ), so it carries
no TZ citations. Specs live in [`spec.md`](spec.md) + `spec/`.

> ### Самоподдерживающийся модуль
>
> Этот модуль **сам сообщает разработчику о своих поломках и сам обновляется**.
> Встраивается в любой проект одной строкой, изолирован так, что не может
> сломать вызывающий код, а его публичный API заморожен семантической
> версией — изменения внутри репозитория не ломают чужой код.
>
> **Условия, на которых это работает:**
>
> 1. Ошибки **самого модуля** (не вашего кода) собираются и уходят в issues
>    этого репозитория, чтобы разработчик мог поддерживать модуль, не имея
>    контакта с вами. В отчёт попадают текст ошибки, файл и строка внутри
>    модуля, версии PHP и модуля, анонимный id установки и — если не
>    запретите — адрес сайта. Ключи, персональные данные, содержимое запросов и
>    абсолютные пути вычищаются **до** отправки.
> 2. **Отправка спрашивается при установке и отключается в один клик.** До
>    ответа отчёты копятся **только на вашем сервере** — модуль не может
>    включить отправку сам. Ассистент, который ставит модуль, обязан задать
>    этот вопрос (см. [`AGENTS.md`](AGENTS.md) §1).
> 3. **Каждая ошибка, показанная пользователю, подписана** — что с ней
>    произошло: отправлена разработчику, или записана локально, или отправка
>    выключена. Убрать подпись нельзя, заменить формулировку — можно.
> 4. **Обновления приходят уведомлением в админку** с кнопками «Обновить» и
>    «Откатить». Обновление ставится только после проверки синтаксиса всех
>    файлов релиза и всегда с резервной копией; мажорная смена API
>    автоматически не приезжает никогда.
> 5. **Файлы оператора не трогаются**: `data/`, `selfheal/token.local.php`,
>    `pull-config.php`, `.env` переживают любое обновление и откат.
>
> Полные инструкции для нейросети и разработчика — [`AGENTS.md`](AGENTS.md),
> спека — [`spec/selfheal.md`](spec/selfheal.md).
>
> ```php
> require_once __DIR__ . '/site_yacloud_openrouter/selfheal/bootstrap.php';
> Selfheal\SelfHeal::boot(['app' => 'имя-вашего-проекта']);
> ```
>
> ```bash
> php selfheal_install.php     # проверка окружения + вопрос про отчёты
> php tests/selfheal_smoke.php # изоляция и контракт — проверяются тестами
> ```

- **OpenRouter + Yandex + GenRouter providers & models** (`llm.php`) — three providers behind one
  interface, per-session model/provider override, config-driven fallback chain
  (a newer version of the same model first, then the operator's list, then the
  per-provider fallbacks), generic `chatText()` / `chatJson()`.
- **Live model catalogue** (`model_catalog.php`) — the model list is pulled from the
  providers themselves (OpenRouter `GET /models`, Yandex `GET /foundationModels/v1/models`,
  falling back to the OpenAI-compatible `GET /v1/models`) and cached in
  the `settings` table; `setup.php` refreshes it on load when the cache is stale. The
  hardcoded list keeps working until (and after) the first refresh.
- **Yandex OCR + PDF/DOCX parsing** (`parser.php`, `llm.php`) — DOCX via ZipArchive,
  PDF via `pdftotext` → OpenRouter vision models (file-parser strategies + native)
  → Yandex Vision OCR, in operator-chosen priority order.
- **Model / provider selection in settings** (`setup.php`, `settings_store.php`,
  `config.php`) — admin page writes to a `settings` table; `config.php` overlays
  whitelisted keys on every request. Every model field is a dropdown over the catalogue
  (default, three backups, per-provider fallbacks, vision), never free text, and
  "Проверить модели и ключи" runs one real completion per leg so a wrong key or a model
  the cloud folder does not serve is named immediately.
- **Two Yandex addresses, one model list** (`llm.php`) — not every model of a cloud folder
  answers on both `v1/chat/completions` and `foundationModels/v1/completion`. A model
  refused at one address is re-asked once at the other, the address that answered is
  remembered for the rest of the request and shown in the trace (`yandex:gemma@fm`), so
  «Model is not available via gRPC API» stops costing a candidate.
- **Vision models of both providers** — `LLM_VISION_MODEL` accepts `"<provider>:<slug>"`,
  so photos, labels and page scans can go to a Yandex multimodal model
  (`qwen3.6-35b-a3b`, `gemma-3-{4b,12b,27b}-it`) as well as to OpenRouter.
- **Diagnostic log** (`diag_log.php`) — every model call, every failure and every
  self-test lands in a `diag_log` table next to the settings; `setup.php` shows it as two
  copyable blocks (everything / errors only) with the environment header prepended. API
  keys are masked, so the text can be pasted into a bug report as is. The log is emptied
  automatically when the deployed code changes; the `settings` rows are not.
- **Auto-pull while developing** (`auto_pull.php`) — one checkbox in `setup.php`: every
  page view quietly asks GitHub for the head of the ref `pull.php` tracks, and a newer
  commit is deployed through `pull.php` before the page is reloaded from it. Repo, token
  and the `pull.php` password come from `pull-config.php` — nothing is duplicated.
- **Email sending** (`mailer.php`) — pure-PHP SMTP (AUTH LOGIN, implicit TLS 465 /
  STARTTLS 587), HTML+plain, attachments, throttled error notifications, SMTP test.
- **Self-maintaining layer** (`selfheal/`) — the module reports its own errors to
  this repository's issues (only with the operator's explicit consent, asked at
  install time and revocable in one click), signs every user-visible error with
  what happened to it, and offers «Обновить» / «Откатить» in the admin panel. Runs
  in its own `Selfheal\` namespace with its own SQLite file, chains onto the host's
  error handler instead of replacing it, and freezes its public API behind
  `Contract::API_VERSION` so changes here cannot break a consuming project. See
  [`AGENTS.md`](AGENTS.md).

No app coupling, no hardcoded secrets in the app layer — everything is env- or
`settings`-driven. The one deliberate exception is the **sealed** reporting
credential in `selfheal/token.php`: a fine-grained GitHub PAT limited to this one
repository whose only write permission is `Issues: Read and write` (read-only
`Contents`/`Metadata` is what the updater uses), encrypted so scanners and casual
reading do not find it. It is sealed, not secret — see `selfheal/Vault.php`.

## Files

```
site_yacloud_openrouter/
├── config.php          # config: ENV + settings-table overlay, AVAILABLE_MODELS
├── model_catalog.php   # ModelCatalog — live provider catalogue, cache, model versions
├── llm.php             # ToolkitLLM — OpenRouter + Yandex + GenRouter, fallback, PDF OCR
├── llm_providers.php   # shared enable/disable and per-provider proxy policy
├── provider_controls.js # immediate connection-card saves without losing edits
├── parser.php          # Parser class — DOCX/PDF extraction + normalization
├── mailer.php          # Mailer class — SMTP send (custom/attachment/test/error)
├── settings_store.php  # SettingsStore — key/value SQLite store
├── auto_pull.php       # AutoPull — silent deploy check on every page (pull.php + pull-config.php)
├── diag_log.php        # DiagLog — diagnostic log, masked secrets, reset on redeploy
├── setup.php           # admin settings page (provider/model/OCR + SMTP + module block)
├── example.php         # CLI usage examples
├── module.json         # module manifest: version, api_version, channel, preserve
├── AGENTS.md           # rules for any LLM / developer embedding this module
├── selfheal/           # self-maintaining layer, namespace Selfheal\
│   ├── bootstrap.php   #   the ONLY file a host includes
│   ├── SelfHeal.php    #   public façade (the frozen surface)
│   ├── Contract.php    #   API version, features, capabilities, manifest
│   ├── Guard.php       #   chained error capture (never replaces host handlers)
│   ├── Reporter.php    #   queue → issues, dedup, rate limits, post-response send
│   ├── Scrub.php       #   secrets / paths / PII stripped before anything leaves
│   ├── Consent.php     #   ask | on | off — reporting can never self-enable
│   ├── Vault.php       #   sealed credential (sealed, not secret — read the notice)
│   ├── Keys.php        #   signed capability keys for neighbouring modules
│   ├── Updater.php     #   version check, verified staged update, rollback
│   ├── Admin.php       #   admin block: consent question + Обновить / Откатить
│   ├── State.php       #   our own SQLite file — never the host's
│   └── Http.php        #   HTTPS-only, time-boxed transport
├── selfheal_install.php# first-run check + the consent question
├── selfheal_seal.php   # CLI: seal a token into selfheal/token.php (never plaintext)
├── selfheal_admin.php  # standalone maintenance page (password-gated)
├── .env.example
├── spec.md             # spec navigation index
├── spec/               # per-module specs (llm, model_catalog, parser, mailer,
│                       #   settings, diag_log, auto_pull, selfheal)
├── tests/              # llm_chain, selfheal_smoke, selfheal_update
└── data/               # SQLite DBs + logs + update staging (gitignored)
```

## Configure

Either set ENV vars (see `.env.example`) or open **`setup.php`** in a browser
(gated by `ADMIN_PASSWORD`) and fill in provider, default model, OCR chain, API
keys and SMTP. Saved values land in the `settings` table and override config on
the next request.

```php
$cfg = require __DIR__ . '/config.php';   // ENV + settings overlay
```

## Usage

### Chat (provider fallback automatic)

```php
require __DIR__ . '/llm.php';
$cfg = require __DIR__ . '/config.php';
LLM::init($cfg);

// Optional per-call selection:
LLM::setModelOverride('gpt-4o');          // short id from AVAILABLE_MODELS
LLM::setProviderOverride('openrouter');   // 'openrouter' | 'yandex'

$text = LLM::chatText('Ты — ассистент.', 'Привет!');
$json = LLM::chatJson('Верни JSON {"ok":true}.', 'go');   // strict-JSON mode
```

If the primary model fails, `dispatch()` first tries a **newer version of the same
model** from the catalogue (`LLM_FALLBACK_MODE=auto`, the default — e.g. `gpt-4.1` →
`gpt-5.1`), then the operator's `LLM_FALLBACK_MODELS`, then walks
`LLM_PROVIDER_PRIORITY` retrying each provider's fallback model. When OpenRouter fails and Yandex
serves the call, a one-shot `Mailer::sendErrorNotification` fires (if Mailer + a
configured `ERROR_EMAIL` are present).

### PDF OCR / document parsing

```php
require __DIR__ . '/parser.php';   // pulls LLM for the OCR fallback
$res = Parser::extract('/path/resume.pdf', 'pdf');   // ['raw','source','quality']
$text = Parser::normalize($res['raw']);

// Or OCR a PDF directly:
$text = LLM::ocrPdf('/path/scan.pdf');
```

OCR order: when the operator picked the `yandex-vision-ocr` model, forced
`provider=yandex`, or put `yandex` first in the priority — **Yandex Vision OCR**
runs first; otherwise OpenRouter vision strategies run first and Yandex is the
final fallback. Toggle Yandex OCR with `YANDEX_OCR_ENABLED`.

### Email

```php
require __DIR__ . '/mailer.php';
$cfg = require __DIR__ . '/config.php';

// HTML + plain text
Mailer::sendCustom($cfg, 'to@example.com', 'Subject',
    Mailer::mdToHtmlEmail("# Hi\n\n**Bold** body."), "Hi\n\nBold body.");

// With a file attachment
Mailer::sendWithAttachment($cfg, $cfg['ADMIN_EMAIL'], 'Report',
    'See attached.', 'report.pdf', '/path/report.pdf');

// Diagnostic (throws on SMTP error)
Mailer::sendTest($cfg, 'to@example.com');
```

### Selection store directly

```php
require __DIR__ . '/settings_store.php';
$store = new SettingsStore($cfg['DB_PATH']);
$store->setSetting('LLM_DEFAULT_MODEL', 'gpt-4o');
$model = $store->getSetting('LLM_DEFAULT_MODEL', 'gemini-2.0-flash');
```

## CLI quick test

```bash
php example.php chat  "Привет, кто ты?"
php example.php parse /path/resume.docx
php example.php ocr   /path/scan.pdf
php example.php email you@example.com
```

## Models

`config.php → AVAILABLE_MODELS` lists chat models (Yandex first-party + open
catalogue, plus a few OpenRouter ids) and the `yandex-vision-ocr` OCR-only entry.
Yandex `full_id` is the slug used in `gpt://<folder>/<full_id>/latest`. Add/remove
rows there; the `LLM_DEFAULT_MODEL` dropdown in `setup.php` is built from this list.

That list is the **starting point**, not the whole catalogue: `ModelCatalog` pulls the
providers' own lists over HTTP and caches them in `settings`. Opening `setup.php`
refreshes the cache when it is older than `MODEL_CATALOG_TTL_MIN` minutes (15 by
default); the two buttons there force a refresh or drop the cache. A live model with a
known slug just flags the hardcoded row as available; an unknown one is appended in its
own `… (каталог)` group with the USD price the provider reported. Network trouble never
breaks the page — the previous list stays and the reason is shown.

The version parser behind the same class powers the default backup: when the chosen model
fails, `LLM_FALLBACK_MODE=auto` reaches for a **newer version of the same model**
(`claude-sonnet-4` → `claude-sonnet-4.5`), so a refreshed catalogue keeps the backup
current on its own. Models whose slug carries no readable version (`yandexgpt`, `gpt-4o`)
fall through to `LLM_FALLBACK_MODELS`, which is also what `manual` mode uses exclusively.

## GenRouter and connection controls

Connection behavior is adapted from [kp-atlant module 070](https://github.com/dansury/kp-atlant/blob/17d460419ec5edf8f279513acb96f68d7c0ba6ee/specs/070-genrouter-provider-controls/spec.md): independent Bearer key and API address, live model IDs (including plain slugs), separate proxy switches, provider enable/disable cards and connection tests. Its application-specific mail automation and CRM dependencies are not imported.

Open `setup.php`, enable GenRouter on its card, enter the API key and save, then refresh models and select a model from the live catalog. Save the card and use the global default-model and priority controls to choose the generation order. A disabled provider preserves its saved key, model and catalog. Card switches save immediately and preserve other unsaved edits. Tests and refreshes use saved settings. Existing OpenRouter/Yandex integrations remain enabled on upgrade; GenRouter starts disabled. No real provider request is required for the regression tests.

ENV-only configuration can set `LLM_ENABLED_GENROUTER=1`, `GENROUTER_API_KEY`, `GENROUTER_MODEL`, `LLM_PROVIDER=genrouter`, `LLM_DEFAULT_MODEL=genrouter:<exact-model-id>` and `LLM_PROVIDER_PRIORITY=genrouter,openrouter,yandex`. `GENROUTER_BASE_URL` defaults to `https://api.genrouter.ru/v1`. Shared proxy credentials (`LLM_PROXY`, `LLM_PROXY_AUTH`) apply only when that provider's `LLM_PROXY_<PROVIDER>` switch is on.

Run `php tests/genrouter.php` for local mock-API coverage of text, JSON, images, parallel requests, probes, fallback, cache retention, exact IDs, pricing, proxy policy and disabled-provider isolation. Run the other `tests/*.php` scripts separately for the existing regression suite (fixture routers are not standalone tests). Browser regression: serve the module with a disposable database, local mock provider endpoints and `SELFHEAL_REPORTING=off`; log into its local setup page with Playwright CLI, then run `playwright-cli run-code --filename tests/provider_controls.browser.js`. The browser fixture expects a local GenRouter catalog containing `vendor/test`, `plain-model` and `vendor/free` from `tests/fixtures/providers.php`; it checks persistence, retained edits, refresh, connection tests, failed-save rollback, masking, CSRF and desktop/mobile layouts.
