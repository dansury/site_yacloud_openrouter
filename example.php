<?php
/**
 * site_yacloud_openrouter — usage example (CLI).
 *   php example.php chat   "Привет, кто ты?"
 *   php example.php ocr     /path/to/document.pdf
 *   php example.php parse   /path/to/resume.docx
 *   php example.php email   you@example.com
 * Configure keys via ENV or setup.php first.
 */

require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/mailer.php';

$cfg = require __DIR__ . '/config.php';
LLM::init($cfg);

$mode = $argv[1] ?? 'chat';
$arg  = $argv[2] ?? '';

switch ($mode) {
    case 'chat':
        // Optional: pick a model / provider for this call.
        // LLM::setModelOverride('gpt-4o'); LLM::setProviderOverride('openrouter');
        $text = LLM::chatText('Ты — лаконичный ассистент. Отвечай по-русски.', $arg ?: 'Привет!');
        echo $text . "\n";
        break;

    case 'ocr':
        // PDF OCR over the configured chain (Yandex Vision + OpenRouter vision).
        $text = LLM::ocrPdf($arg);
        echo ($text ?? '(empty)') . "\n";
        break;

    case 'parse':
        // DOCX/PDF → normalized text (PDF falls back to OCR via LLM::ocrPdf).
        $ext = strtolower(pathinfo($arg, PATHINFO_EXTENSION));
        $res = Parser::extract($arg, $ext);
        echo "source={$res['source']} quality={$res['quality']}\n\n";
        echo Parser::normalize($res['raw']) . "\n";
        break;

    case 'email':
        $ok = Mailer::sendCustom(
            $cfg, $arg ?: ($cfg['ADMIN_EMAIL'] ?? ''),
            'site_yacloud_openrouter test',
            Mailer::mdToHtmlEmail("# Привет\n\nЭто **тест** из site_yacloud_openrouter."),
            "Привет\n\nЭто тест из site_yacloud_openrouter."
        );
        echo $ok === null ? "sent\n" : "sent\n";
        break;

    default:
        fwrite(STDERR, "Unknown mode: $mode\n");
        exit(1);
}
