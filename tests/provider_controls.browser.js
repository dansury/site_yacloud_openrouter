// Run through playwright-cli run-code --filename after opening the local fixture UI.
async page => {
  const assert = (ok, message) => { if (!ok) throw new Error(message); };
  const card = page.locator('.provider-card[data-provider="genrouter"]');
  const toggle = card.locator('[name="enabled"]');
  const status = card.locator('[role="status"]');
  if (!await toggle.isChecked()) {
    await toggle.check();
    await status.filter({hasText: 'Включён'}).waitFor();
  }
  await page.reload();
  assert(await toggle.isChecked(), 'Toggle must persist after reload');
  assert(await card.locator('fieldset').isVisible(), 'Enabled controls must be visible');
  const priority = page.locator('[name="LLM_PROVIDER_PRIORITY"]');
  await priority.fill('genrouter,openrouter,yandex');
  await card.locator('[name="GENROUTER_API_KEY"]').fill('fixture-ui-saved-key');
  await card.locator('button[value="save"]').click();
  await status.filter({hasText: 'Настройки сохранены'}).waitFor();
  await card.locator('button[value="refresh"]').click();
  await status.filter({hasText: 'Каталог обновлён: 3'}).waitFor();
  const options = await card.locator('[name="GENROUTER_MODEL"] option').evaluateAll(list => list.map(o => o.value));
  assert(options.includes('plain-model') && !options.includes('anthropic/claude-opus-5'), 'Picker must use live catalog');
  assert(await priority.inputValue() === 'genrouter,openrouter,yandex', 'Catalog update lost an unsaved edit');
  await card.locator('[name="GENROUTER_MODEL"]').selectOption('vendor/test');
  await card.locator('button[value="save"]').click();
  await status.filter({hasText: 'Настройки сохранены'}).waitFor();
  await card.locator('button[value="test"]').click();
  await status.filter({hasText: 'ответ получен'}).waitFor();
  await toggle.uncheck();
  await status.filter({hasText: 'Выключен'}).waitFor();
  assert(!await card.locator('fieldset').isVisible(), 'Disabled controls must be hidden');
  assert(await page.locator('select[name="LLM_DEFAULT_MODEL"] option[data-provider="genrouter"]').count() === 0, 'Disabled models remain in global picker');
  assert(await priority.inputValue() === 'genrouter,openrouter,yandex', 'Toggle lost unrelated edits');
  await page.reload();
  assert(!await toggle.isChecked(), 'Disabled state did not persist');
  await toggle.check();
  await status.filter({hasText: 'Включён'}).waitFor();
  await page.reload();
  assert(await card.locator('[name="GENROUTER_MODEL"]').inputValue() === 'vendor/test', 'Disable erased saved model');
  const placeholder = await card.locator('[name="GENROUTER_API_KEY"]').getAttribute('placeholder');
  assert(placeholder && !placeholder.includes('fixture-ui-saved-key'), 'Saved key must be masked');
  const before = await toggle.isChecked();
  await page.route('**/setup.php', async route => {
    if (route.request().method() === 'POST') await route.fulfill({status: 500, body: 'fixture failure'});
    else await route.continue();
  });
  try {
    // click() permits the application to restore state before the action returns.
    await toggle.click();
    await status.filter({hasText: 'HTTP 500'}).waitFor();
    assert(await toggle.isChecked() === before, 'Failed save did not restore checkbox');
    assert(await card.locator('fieldset').isVisible(), 'Failed save hid enabled controls');
  } finally {
    await page.unroute('**/setup.php');
  }
  const csrfResult = await page.evaluate(async () => {
    const body = new URLSearchParams({provider_action: 'toggle', provider: 'genrouter', enabled: '0', csrf: 'invalid'});
    return (await fetch('setup.php', {method: 'POST', body})).json();
  });
  assert(!csrfResult.ok, 'Invalid CSRF token accepted');
  await page.reload();
  assert(await toggle.isChecked(), 'Rejected CSRF changed enabled state');
  for (const width of [1280, 390]) {
    await page.setViewportSize({width, height: 900});
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow at ' + width);
    assert(await toggle.isVisible(), 'Toggle not visible at ' + width);
  }
  await page.screenshot({path: 'work/provider-controls-mobile.png', fullPage: true});
  await page.setViewportSize({width: 1280, height: 900});
  await page.screenshot({path: 'work/provider-controls-desktop.png', fullPage: true});
  console.log('Provider browser checks passed: persistence, catalog, probes, masking, edits, failure rollback, CSRF, desktop/mobile');
}
