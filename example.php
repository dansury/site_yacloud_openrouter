<?php
/**
 * site_yacloud_openrouter — usage example (CLI).
 *   php example.php chat   "Привет, кто ты?"
 *   php example.php ocr     /path/to/document.pdf
 *   php example.php parse   /path/to/resume.docx
 *   php example.php email   you@example.com
 *   php example.php selfheal              — self-maintaining layer, see AGENTS.md
 * Configure keys via ENV or setup.php first.
 */

require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/selfheal/bootstrap.php';   // the one line a host needs

$cfg = require __DIR__ . '/config.php';
LLM::init($cfg);

// Self-maintaining layer. Booting it installs chained error handlers and
// returns; it prints nothing and cannot change how this script behaves.
Selfheal\SelfHeal::boot([
    'app'     => 'site_yacloud_openrouter/example.php',
    'secrets' => [(string) ($cfg['OPENROUTER_API_KEY'] ?? ''), (string) ($cfg['YANDEX_API_KEY'] ?? '')],
]);

$mode = $argv[1] ?? 'chat';
$arg  = $argv[2] ?? '';

switch ($mode) {
    case 'chat':
        // Optional: pick a model / provider for this call.
        // LLM::setModelOverride('gpt-4o'); LLM::setProviderOverride('openrouter');
        try {
            $text = LLM::chatText('Ты — лаконичный ассистент. Отвечай по-русски.', $arg ?: 'Привет!');
            echo $text . "\n";
        } catch (Throwable $e) {
            // userMessage() = capture() + the mandatory signature: the user is
            // always told what happened to their error (AGENTS.md §3).
            echo Selfheal\SelfHeal::userMessage($e, 'Не удалось получить ответ модели.') . "\n";
        }
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

    case 'selfheal':
        // Status of the self-maintaining layer, as the admin panel sees it.
        foreach (Selfheal\SelfHeal::diagnostics() as $k => $v) {
            printf("%-16s %s\n", $k, is_array($v) ? implode(',', $v) : var_export($v, true));
        }
        echo "\nОтчёты: " . Selfheal\SelfHeal::reporting()
            . " (ask — копятся локально; спросить пользователя: php selfheal_install.php)\n";
        $upd = Selfheal\SelfHeal::updateStatus();
        echo 'Версия: ' . $upd['current'] . ', в репозитории: ' . ($upd['remote'] ?: '—')
            . ($upd['error'] !== '' ? ' (' . $upd['error'] . ')' : '') . "\n";

        // Capability key for a neighbouring module / your own shell.
        $key = Selfheal\SelfHeal::issueKey(['errors.report', 'updates.read'], 3600);
        echo 'Ключ на час: ' . $key . "\n";
        echo 'errors.report → ' . var_export(Selfheal\SelfHeal::grants($key, 'errors.report'), true)
            . ', updates.apply → ' . var_export(Selfheal\SelfHeal::grants($key, 'updates.apply'), true) . "\n";
        break;

    default:
        fwrite(STDERR, "Unknown mode: $mode\n");
        exit(1);
}
