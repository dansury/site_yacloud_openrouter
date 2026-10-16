/* Connection cards save without replacing the page or unrelated form edits. */
(() => {
  const cards = [...document.querySelectorAll('.provider-card')];
  const labels = {openrouter: 'OpenRouter', yandex: 'Yandex Cloud', genrouter: 'GenRouter'};
  const enabled = p => !!cards.find(c => c.dataset.provider === p)?.elements.enabled.checked;

  function update(models) {
    document.querySelectorAll('[data-provider-controls]').forEach(el => {
      el.hidden = !enabled(el.dataset.providerControls);
    });
    document.querySelectorAll('select').forEach(select => {
      const name = select.name;
      const isModel = name === 'LLM_DEFAULT_MODEL' || name === 'LLM_VISION_MODEL'
        || name === 'GENROUTER_MODEL' || name === 'YANDEX_FALLBACK_MODEL'
        || name === 'LLM_FALLBACK_MODEL' || /^LLM_FALLBACK_MODEL_[123]$/.test(name);
      if (name !== 'LLM_PROVIDER' && !isModel) return;
      const current = select.value;
      const saved = [...select.options].find(o => o.value === current)?.cloneNode(true);
      const blank = [...select.options].find(o => o.value === '')?.cloneNode(true);
      select.replaceChildren();
      if (blank) select.append(blank);
      if (name === 'LLM_PROVIDER') {
        Object.entries(labels).filter(([p]) => enabled(p)).forEach(([p, label]) => select.add(new Option(label, p)));
      } else {
        const provider = select.closest('.provider-card')?.dataset.provider
          || ({LLM_FALLBACK_MODEL: 'openrouter', YANDEX_FALLBACK_MODEL: 'yandex', GENROUTER_MODEL: 'genrouter'})[name];
        models.filter(m => enabled(m.provider) && !m.ocr_only && (!provider || provider === m.provider)
          && (name !== 'LLM_VISION_MODEL' || m.vision)).forEach(m => {
          const value = name === 'LLM_VISION_MODEL' ? m.provider + ':' + m.full_id
            : provider ? m.full_id : m.id;
          const price = m.free ? ' · бесплатно'
            : m.price_usd_in > 0 || m.price_usd_out > 0 ? ` · $${m.price_usd_in}/$${m.price_usd_out} за 1M`
            : m.price_in > 0 || m.price_out > 0 ? ` · ~${m.price_in}/${m.price_out} ₽ за 1k` : '';
          const option = new Option(m.label + ' — ' + m.provider + '/' + m.full_id + price, value);
          option.dataset.provider = m.provider;
          select.add(option);
        });
      }
      const savedProvider = saved?.dataset.provider || select.closest('.provider-card')?.dataset.provider
        || (current.startsWith('genrouter:') || current.startsWith('gr-') ? 'genrouter' : '');
      const authoritative = savedProvider === 'genrouter' && models.some(m => m.provider === 'genrouter' && m.live);
      if (saved && current && !authoritative && ![...select.options].some(o => o.value === current)
        && (!savedProvider || enabled(savedProvider))) select.append(saved);
      if ([...select.options].some(o => o.value === current)) select.value = current;
    });
  }

  async function action(card, kind) {
    const toggle = card.elements.enabled;
    const previous = !toggle.checked;
    const status = card.querySelector('[role="status"]');
    const body = new FormData(card);
    body.set('provider_action', kind);
    body.set('enabled', toggle.checked ? '1' : '0');
    const controls = [...document.querySelectorAll('.provider-card button, .provider-card input[name="enabled"]')];
    controls.forEach(c => c.disabled = true);
    status.textContent = 'Сохраняем…';
    try {
      const response = await fetch('setup.php', {method: 'POST', body, credentials: 'same-origin'});
      if (!response.ok) throw new Error('Не удалось сохранить: HTTP ' + response.status);
      const result = await response.json();
      if (!result.ok) throw new Error(result.note);
      if (kind === 'toggle' && toggle.checked) {
        const priority = document.querySelector('[name="LLM_PROVIDER_PRIORITY"]');
        const order = priority.value.split(',').map(s => s.trim()).filter(Boolean);
        if (!order.includes(card.dataset.provider)) priority.value = [...order, card.dataset.provider].join(',');
      }
      update(result.models);
      status.textContent = result.note;
    } catch (error) {
      if (kind === 'toggle') toggle.checked = previous;
      status.textContent = error.message || 'Не удалось выполнить действие';
    } finally {
      controls.forEach(c => c.disabled = false);
    }
  }

  cards.forEach(card => {
    card.elements.enabled.addEventListener('change', () => action(card, 'toggle'));
    card.addEventListener('submit', event => {
      event.preventDefault();
      action(card, event.submitter?.value || 'save');
    });
  });
})();
