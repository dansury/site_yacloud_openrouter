<?php
/**
 * Operator settings — LLM provider / model / OCR + SMTP.
 * spec: spec/settings.md §3 — infrastructural code, no customer TZ behind it.
 * (Self-contained extraction of resume-index/setup.php; payments/push removed.)
 *
 * Saved values land in the `settings` table and are overlaid by config.php on
 * the next request, so provider / models / API keys / mail can be changed
 * without editing code. Access is gated by ADMIN_PASSWORD (config / ENV).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/mailer.php';

$cfg   = require __DIR__ . '/config.php';
$store = new SettingsStore($cfg['DB_PATH']);

session_start();
header('Cache-Control: no-store');
$ADMIN_PW = (string) ($cfg['ADMIN_PASSWORD'] ?? '');
$login_error = null;

if (($_POST['action'] ?? '') === 'login') {
    $pw = (string) ($_POST['password'] ?? '');
    if ($ADMIN_PW !== '' && hash_equals($ADMIN_PW, $pw)) {
        $_SESSION['admin_authed'] = true;
        header('Location: setup.php');
        exit;
    }
    $login_error = 'Неверный пароль';
}

if (($_GET['logout'] ?? '') !== '') {
    $_SESSION = [];
    session_destroy();
    header('Location: setup.php');
    exit;
}

$h = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['admin_authed'])) {
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>site_yacloud_openrouter — setup (вход)</title>
    <style>
      body { font-family: -apple-system, system-ui, sans-serif; background: #0c1018; color: #edf1f7; padding: 24px; }
      .box { max-width: 340px; margin: 80px auto; background: #131a26; border: 1px solid #1f2735; padding: 28px 26px; }
      h1 { font-size: 20px; margin: 0 0 18px; }
      input { width: 100%; padding: 10px 12px; background: #0c1018; color: #edf1f7; border: 1px solid #1f2735; font: inherit; margin-bottom: 14px; }
      button { background: #00d4e8; color: #0c1018; border: 0; padding: 12px 18px; font-weight: 700; cursor: pointer; width: 100%; }
      .err { color: #ff4560; font-size: 13px; margin: 0 0 12px; }
    </style></head><body>
    <div class="box">
      <h1>ВХОД В НАСТРОЙКИ</h1>
      <?php if ($login_error): ?><p class="err"><?= $h($login_error) ?></p><?php endif; ?>
      <?php if ($ADMIN_PW === ''): ?><p class="err">ADMIN_PASSWORD не задан (config.php / ENV).</p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" autofocus required placeholder="Пароль администратора">
        <button type="submit">Войти</button>
      </form>
    </div></body></html><?php
    exit;
}

$messages = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (isset($_POST['smtp_test'])) {
        // Test letter via the CURRENT saved settings (re-read overlay).
        $cfg_live = require __DIR__ . '/config.php';
        $test_to = trim((string) ($_POST['smtp_test_to'] ?? '')) ?: (string) ($cfg_live['ADMIN_EMAIL'] ?? '');
        try {
            Mailer::sendTest($cfg_live, $test_to);
            $messages[] = ['ok' => true, 'text' => '✅ Тестовое письмо отправлено на ' . $test_to
                . ' (host=' . ($cfg_live['SMTP_HOST'] ?? '') . ':' . ($cfg_live['SMTP_PORT'] ?? '') . ').'];
        } catch (Throwable $e) {
            $messages[] = ['ok' => false, 'text' => '⚠️ SMTP-тест не прошёл: ' . $e->getMessage()];
        }
    } elseif (($_POST['action'] ?? 'save') === 'save') {
        $edited = [];
        // String settings. Empty values never overwrite existing.
        $map = [
            'LLM_PROVIDER', 'LLM_PROVIDER_PRIORITY', 'LLM_DEFAULT_MODEL',
            'LLM_VISION_MODEL', 'LLM_FALLBACK_MODEL', 'YANDEX_FALLBACK_MODEL',
            'LLM_OCR_MODELS', 'YANDEX_OCR_MODEL',
            'OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID',
            'ADMIN_EMAIL', 'ERROR_EMAIL',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME',
            'ADMIN_PASSWORD',
        ];
        foreach ($map as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') { $store->setSetting($k, $v); $edited[] = $k; }
        }
        // Checkbox: Yandex Vision OCR. Always written.
        $store->setSetting('YANDEX_OCR_ENABLED', isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $edited[] = 'YANDEX_OCR_ENABLED=' . (isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $messages[] = ['ok' => true, 'text' => '✅ Сохранено: ' . implode(', ', $edited)];
    }
}

// Re-load config so freshly-saved overlay values render in the "current" hints.
$cfg = require __DIR__ . '/config.php';
$current = $store->allSettings();
$mask = static fn (?string $v) => $v ? str_repeat('•', max(4, min(strlen((string) $v), 16))) : '';
$mask_key = static function (?string $v): string {
    $v = (string) $v;
    if ($v === '') return '';
    return strlen($v) > 10 ? substr($v, 0, 4) . '…' . substr($v, -4) : str_repeat('•', strlen($v));
};
// Effective value: saved overlay first, then config fallback.
$eff = static function (string $k) use ($cfg, $current): string {
    if (isset($current[$k]) && $current[$k] !== '') return (string) $current[$k];
    $v = $cfg[$k] ?? '';
    return is_array($v) ? implode(',', $v) : (string) $v;
};
$ocr_models_eff = $eff('LLM_OCR_MODELS');
?><!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>site_yacloud_openrouter — настройки</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; background: #0c1018; color: #edf1f7; padding: 24px; max-width: 720px; margin: 0 auto; }
  h1 { font-size: 22px; } h2 { font-size: 16px; margin: 26px 0 10px; color: #00d4e8; }
  .lede { color: #9fb0c8; font-size: 14px; }
  label { display: block; margin: 12px 0; font-size: 14px; }
  label span { display: block; color: #9fb0c8; margin-bottom: 4px; }
  input, select { width: 100%; padding: 9px 11px; background: #131a26; color: #edf1f7; border: 1px solid #1f2735; font: inherit; }
  button { background: #00d4e8; color: #0c1018; border: 0; padding: 12px 20px; font-weight: 700; cursor: pointer; }
  .msg { padding: 10px 12px; margin: 8px 0; border: 1px solid #1f2735; font-size: 14px; }
  .msg.ok { border-color: #1f6f4f; } .msg.bad { border-color: #ff4560; }
  .row { display: flex; gap: 12px; } .row > * { flex: 1; }
  a { color: #00d4e8; }
</style></head><body>
<h1>site_yacloud_openrouter — настройки <a href="?logout=1" style="font-size:13px;float:right">выйти</a></h1>
<p class="lede">Заполните только нужные поля — пустые значения не перезаписывают существующие. Значения сразу применяются ко всему сервису (overlay через таблицу <code>settings</code>).</p>

<?php foreach ($messages as $m): ?>
  <div class="msg <?= $m['ok'] ? 'ok' : 'bad' ?>"><?= $h($m['text']) ?></div>
<?php endforeach; ?>

<form method="post" autocomplete="off">
  <input type="hidden" name="action" value="save">

  <h2>Провайдер и модели</h2>
  <div class="row">
    <label><span>Провайдер по умолчанию</span>
      <select name="LLM_PROVIDER">
        <option value="openrouter" <?= $eff('LLM_PROVIDER') === 'openrouter' ? 'selected' : '' ?>>openrouter (fallback → yandex)</option>
        <option value="yandex" <?= $eff('LLM_PROVIDER') === 'yandex' ? 'selected' : '' ?>>yandex</option>
      </select>
    </label>
    <label><span>Приоритет провайдеров</span>
      <select name="LLM_PROVIDER_PRIORITY">
        <option value="openrouter,yandex" <?= $eff('LLM_PROVIDER_PRIORITY') === 'openrouter,yandex' ? 'selected' : '' ?>>openrouter → yandex</option>
        <option value="yandex,openrouter" <?= $eff('LLM_PROVIDER_PRIORITY') === 'yandex,openrouter' ? 'selected' : '' ?>>yandex → openrouter</option>
      </select>
    </label>
  </div>
  <label><span>Модель по умолчанию (из AVAILABLE_MODELS)</span>
    <?php $model_cur = $eff('LLM_DEFAULT_MODEL'); $models = (array) ($cfg['AVAILABLE_MODELS'] ?? []); ?>
    <select name="LLM_DEFAULT_MODEL">
      <?php foreach ($models as $mdl): if (!empty($mdl['ocr_only'])) continue; ?>
        <option value="<?= $h($mdl['id']) ?>" <?= $model_cur === $mdl['id'] ? 'selected' : '' ?>>
          <?= $h($mdl['label']) ?> — <?= $h($mdl['provider']) ?>/<?= $h($mdl['full_id']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span>Vision-модель для PDF OCR (OpenRouter full_id)</span><input type="text" name="LLM_VISION_MODEL" placeholder="<?= $h($eff('LLM_VISION_MODEL') ?: 'google/gemini-2.0-flash-001') ?>"></label>
  <label><span>OpenRouter fallback-модель</span><input type="text" name="LLM_FALLBACK_MODEL" placeholder="<?= $h($eff('LLM_FALLBACK_MODEL') ?: 'openrouter/auto') ?>"></label>
  <label><span>Yandex fallback-модель (full_id без gpt://)</span><input type="text" name="YANDEX_FALLBACK_MODEL" placeholder="<?= $h($eff('YANDEX_FALLBACK_MODEL') ?: 'deepseek-r1') ?>"></label>
  <label><span>OCR-модели OpenRouter (через запятую, по порядку)</span><input type="text" name="LLM_OCR_MODELS" placeholder="<?= $h($ocr_models_eff ?: 'google/gemini-2.5-flash,google/gemini-2.0-flash-001') ?>"></label>
  <label><span>Yandex Vision OCR модель</span><input type="text" name="YANDEX_OCR_MODEL" placeholder="<?= $h($eff('YANDEX_OCR_MODEL') ?: 'page') ?>"></label>
  <label style="display:flex;align-items:center;gap:8px;">
    <input type="checkbox" name="YANDEX_OCR_ENABLED" value="1" style="width:auto;" <?= $eff('YANDEX_OCR_ENABLED') === '1' ? 'checked' : '' ?>>
    <span style="margin:0;">Включить Yandex Vision OCR в цепочку PDF-распознавания</span>
  </label>

  <h2>API-ключи</h2>
  <label><span>OpenRouter API Key</span><input type="password" name="OPENROUTER_API_KEY" placeholder="<?= $h($mask_key($eff('OPENROUTER_API_KEY'))) ?>"></label>
  <label><span>Yandex API Key</span><input type="password" name="YANDEX_API_KEY" placeholder="<?= $h($mask_key($eff('YANDEX_API_KEY'))) ?>"></label>
  <label><span>Yandex Folder ID</span><input type="text" name="YANDEX_FOLDER_ID" placeholder="<?= $h($eff('YANDEX_FOLDER_ID')) ?>"></label>

  <h2>Почта (SMTP)</h2>
  <div class="row">
    <label><span>SMTP host</span><input type="text" name="SMTP_HOST" placeholder="<?= $h($eff('SMTP_HOST') ?: 'smtp.yandex.ru') ?>"></label>
    <label><span>SMTP port</span><input type="text" name="SMTP_PORT" placeholder="<?= $h($eff('SMTP_PORT') ?: '465') ?>"></label>
  </div>
  <label><span>SMTP user</span><input type="text" name="SMTP_USER" placeholder="<?= $h($eff('SMTP_USER')) ?>"></label>
  <label><span>SMTP password</span><input type="password" name="SMTP_PASS" placeholder="<?= $h($mask($eff('SMTP_PASS'))) ?>"></label>
  <div class="row">
    <label><span>SMTP from</span><input type="text" name="SMTP_FROM" placeholder="<?= $h($eff('SMTP_FROM')) ?>"></label>
    <label><span>From name</span><input type="text" name="SMTP_FROM_NAME" placeholder="<?= $h($eff('SMTP_FROM_NAME') ?: 'site_yacloud_openrouter') ?>"></label>
  </div>
  <label><span>ADMIN_EMAIL (получатель копий/вложений)</span><input type="text" name="ADMIN_EMAIL" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <label><span>ERROR_EMAIL (уведомления об ошибках)</span><input type="text" name="ERROR_EMAIL" placeholder="<?= $h($eff('ERROR_EMAIL')) ?>"></label>

  <h2>Доступ</h2>
  <label><span>Пароль администратора (ADMIN_PASSWORD)</span><input type="password" name="ADMIN_PASSWORD" placeholder="<?= $h($mask($eff('ADMIN_PASSWORD'))) ?>"></label>

  <p><button type="submit">Сохранить</button></p>
</form>

<form method="post" autocomplete="off" style="margin-top:18px;">
  <h2>Тест SMTP</h2>
  <label><span>Кому отправить тестовое письмо</span><input type="text" name="smtp_test_to" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <p><button type="submit" name="smtp_test" value="1">Отправить тестовое письмо</button></p>
</form>
</body></html>
