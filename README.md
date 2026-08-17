# site_yacloud_openrouter (PHP)

Reusable **infrastructure** building blocks — LLM providers (Yandex Cloud +
OpenRouter), document parsing/OCR, SMTP mail, operator-editable settings.
Pure PHP, no Composer — only `ext-curl`, `ext-pdo_sqlite`, `ext-zip` (DOCX),
`ext-dom`.

This repository is the **canonical source** of that toolkit: consuming projects
take the code from here instead of vendoring their own copy. The code is purely
infrastructural — it implements no customer requirement (no TZ), so it carries
no TZ citations. Specs live in [`spec.md`](spec.md) + `spec/`.

- **OpenRouter + Yandex providers & models** (`llm.php`) — two providers behind one
  interface, per-session model/provider override, config-driven provider fallback
  chain, generic `chatText()` / `chatJson()`.
- **Yandex OCR + PDF/DOCX parsing** (`parser.php`, `llm.php`) — DOCX via ZipArchive,
  PDF via `pdftotext` → OpenRouter vision models (file-parser strategies + native)
  → Yandex Vision OCR, in operator-chosen priority order.
- **Model / provider selection in settings** (`setup.php`, `settings_store.php`,
  `config.php`) — admin page writes to a `settings` table; `config.php` overlays
  whitelisted keys on every request.
- **Email sending** (`mailer.php`) — pure-PHP SMTP (AUTH LOGIN, implicit TLS 465 /
  STARTTLS 587), HTML+plain, attachments, throttled error notifications, SMTP test.

No app coupling, no hardcoded secrets — everything is env- or `settings`-driven.

## Files

```
site_yacloud_openrouter/
├── config.php          # config: ENV + settings-table overlay, AVAILABLE_MODELS
├── llm.php             # LLM class — OpenRouter + Yandex, fallback, PDF OCR
├── parser.php          # Parser class — DOCX/PDF extraction + normalization
├── mailer.php          # Mailer class — SMTP send (custom/attachment/test/error)
├── settings_store.php  # SettingsStore — key/value SQLite store
├── setup.php           # admin settings page (provider/model/OCR + SMTP)
├── example.php         # CLI usage examples
├── .env.example
├── spec.md             # spec navigation index
├── spec/               # per-module specs (llm, parser, mailer, settings)
└── data/               # SQLite DB + logs (gitignored)
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

If the primary provider/model fails, `dispatch()` walks `LLM_PROVIDER_PRIORITY`
and retries each provider's fallback model. When OpenRouter fails and Yandex
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
