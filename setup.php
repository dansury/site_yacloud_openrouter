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
require_once __DIR__ . '/llm.php';            // model list + auto-fallback preview
require_once __DIR__ . '/model_catalog.php';  // live provider catalogue

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
    if (isset($_POST['model_catalog'])) {
        // Keys just typed into the form are saved before the fetch — otherwise
        // the catalogue would be pulled with the previous credentials.
        foreach (['OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') $store->setSetting($k, $v);
        }
        $cfg_live = require __DIR__ . '/config.php';
        if ((string) $_POST['model_catalog'] === 'forget') {
            ModelCatalog::forget($store);
            $messages[] = ['ok' => true, 'text' => '✅ Живой каталог забыт — в списках остались вшитые модели.'];
        } else {
            try {
                $rep = ModelCatalog::refresh($cfg_live, $store);
                $messages[] = ['ok' => true, 'text' => '✅ Каталог обновлён: ' . (int) $rep['rows'] . ' моделей'
                    . ' (OpenRouter: ' . (is_int($rep['openrouter']) ? $rep['openrouter'] : '⛔ ' . $rep['openrouter'])
                    . ', Yandex: ' . (is_int($rep['yandex']) ? $rep['yandex'] : '⛔ ' . $rep['yandex']) . ').'];
            } catch (Throwable $e) {
                $messages[] = ['ok' => false, 'text' => '⚠️ Каталог моделей не получен: ' . $e->getMessage()];
            }
        }
    } elseif (isset($_POST['smtp_test'])) {
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
            'LLM_FALLBACK_MODE', 'LLM_FALLBACK_MODELS', 'MODEL_CATALOG_TTL_MIN',
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

// The model catalogue refreshes when this page is opened: a cache older than
// MODEL_CATALOG_TTL_MIN minutes is refetched from the providers, otherwise
// nothing happens. A network failure never breaks the page — the previous list
// stays and the reason is rendered below.
ModelCatalog::maybeRefresh(require __DIR__ . '/config.php', $store);

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
  <label><span>Модель по умолчанию (каталог: вшитый список + то, что отдал провайдер)</span>
    <?php
    $model_cur = $eff('LLM_DEFAULT_MODEL');
    $groups = [];
    foreach ((array) ($cfg['AVAILABLE_MODELS'] ?? []) as $mdl) {
        if (empty($mdl['ocr_only'])) $groups[(string) ($mdl['group'] ?? 'Модели')][] = $mdl;
    }
    // Price hint per 1M tokens: RUB for hardcoded rows, USD for live ones
    // (that is how the provider reports it — no invented exchange rate).
    $price = static function (array $m): string {
        $num = static function (float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); };
        $in = (float) ($m['price_in'] ?? 0); $out = (float) ($m['price_out'] ?? 0);
        if ($in > 0 || $out > 0) return sprintf(' · ~%s/%s ₽ за 1k', $num($in), $num($out));
        $ui = (float) ($m['price_usd_in'] ?? 0); $uo = (float) ($m['price_usd_out'] ?? 0);
        if ($ui > 0 || $uo > 0) return sprintf(' · $%s/$%s за 1M', $num($ui), $num($uo));
        return !empty($m['free']) ? ' · бесплатно' : '';
    };
    ?>
    <select name="LLM_DEFAULT_MODEL">
      <?php foreach ($groups as $gname => $grows): ?>
        <optgroup label="<?= $h((string) $gname) ?>">
          <?php foreach ($grows as $mdl): ?>
            <option value="<?= $h($mdl['id']) ?>" <?= $model_cur === $mdl['id'] ? 'selected' : '' ?>>
              <?= $h($mdl['label']) ?> — <?= $h($mdl['provider']) ?>/<?= $h($mdl['full_id']) ?><?= $h($price($mdl)) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </label>
  <?php
  $live_rows   = ModelCatalog::decode((string) ($cfg['MODEL_CATALOG_MODELS'] ?? ''));
  $live_synced = (string) ($cfg['MODEL_CATALOG_SYNCED_AT'] ?? '');
  $live_error  = (string) ($cfg['MODEL_CATALOG_ERROR'] ?? '');
  $live_ttl    = (int) ($cfg['MODEL_CATALOG_TTL_MIN'] ?? ModelCatalog::TTL_MIN);
  ?>
  <p class="lede" style="margin:-4px 0 10px">
    Список тянется прямо у провайдеров (OpenRouter <code>GET /models</code>, Yandex <code>GET /v1/models</code>)
    и кэшируется в настройках. Каталог обновляется сам при заходе на эту страницу, если кэш старше
    <?= $live_ttl ?> мин; кнопка ниже обновляет сразу. Пока обновления не было — работает вшитый список.
    <?php if ($live_rows): ?>
      <br>Сейчас живых моделей: <b><?= count($live_rows) ?></b><?= $live_synced !== '' ? ', обновлено ' . $h($live_synced) : '' ?>.
    <?php else: ?>
      <br>Живого каталога пока нет — в списках только вшитые модели.
    <?php endif; ?>
    <?php if ($live_error !== ''): ?>
      <br><span style="color:#ff4560">Последняя попытка: <?= $h(mb_substr($live_error, 0, 200)) ?></span>
    <?php endif; ?>
  </p>
  <label><span>Срок годности кэша каталога, мин</span><input type="text" name="MODEL_CATALOG_TTL_MIN" placeholder="<?= $h((string) $live_ttl) ?>"></label>
  <div class="row">
    <button type="submit" name="model_catalog" value="refresh" formnovalidate>Обновить каталог моделей</button>
    <?php if ($live_rows): ?>
      <button type="submit" name="model_catalog" value="forget" formnovalidate>Забыть живой каталог</button>
    <?php endif; ?>
  </div>

  <?php
  // What 'auto' resolves to for the currently chosen model — shown right here,
  // because an irregular slug (yandexgpt, gpt-4o) carries no readable version.
  LLM::init($cfg);
  $auto_rows = LLM::autoFallbackRows($model_cur);
  ?>
  <label style="margin-top:14px"><span>Запасная модель: что пробовать после выбранной</span>
    <select name="LLM_FALLBACK_MODE">
      <option value="auto" <?= $eff('LLM_FALLBACK_MODE') !== 'manual' ? 'selected' : '' ?>>авто — более новая версия той же модели, затем список</option>
      <option value="manual" <?= $eff('LLM_FALLBACK_MODE') === 'manual' ? 'selected' : '' ?>>только список ниже</option>
    </select>
  </label>
  <p class="lede" style="margin:-4px 0 8px">
    «Авто» ищет в каталоге ту же модель более новой версии (<code>gpt-4.1</code> → <code>gpt-5.1</code>,
    <code>claude-sonnet-4</code> → <code>claude-sonnet-4.5</code>), поэтому обновлённый каталог сразу даёт свежий запас.
    <?php if ($auto_rows): ?>
      Для выбранной модели это: <b><?= $h(implode(', ', array_map(static function ($r) { return (string) $r['full_id']; }, array_slice($auto_rows, 0, 3)))) ?></b>.
    <?php else: ?>
      У выбранной модели версия в слаге не читается или новее ничего нет — сработает список ниже.
    <?php endif; ?>
  </p>
  <label><span>Запасные модели (короткие id через запятую; пробуются после выбранной)</span><input type="text" name="LLM_FALLBACK_MODELS" placeholder="<?= $h($eff('LLM_FALLBACK_MODELS') ?: 'например, yandexgpt-lite,gpt-4o') ?>"></label>
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
