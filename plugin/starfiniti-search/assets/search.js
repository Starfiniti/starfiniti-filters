(() => {
  'use strict';

  const config = window.StarfinitiSearchConfig || {};
  const text = (value) => document.createTextNode(String(value ?? ''));
  const message = (key, fallback) => config.messages?.[key] || fallback;
  const safeUrl = (value) => {
    try {
      const raw = String(value || '').trim();
      if (!raw) return '#';
      const url = new URL(raw, window.location.href);
      return ['http:', 'https:'].includes(url.protocol) ? url.href : '#';
    } catch (_) { return '#'; }
  };
  const safeInternalUrl = (value) => {
    try {
      const raw = String(value || '');
      if (!raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) return null;
      const url = new URL(raw, window.location.origin);
      return url.origin === window.location.origin ? url.href : null;
    } catch (_) { return null; }
  };
  const emit = (root, name, detail) => root.dispatchEvent(new CustomEvent(`starfiniti:${name}`, { bubbles: true, detail }));
  let activeOverlayClose = null;
  let scrollState = null;
  const lockScroll = () => {
    if (scrollState) return;
    const body = document.body;
    scrollState = { y: window.scrollY, position: body.style.position, top: body.style.top, width: body.style.width, overflow: body.style.overflow };
    body.style.position = 'fixed'; body.style.top = `-${scrollState.y}px`; body.style.width = '100%'; body.style.overflow = 'hidden';
    document.documentElement.classList.add('sfs-mobile-search-open');
  };
  const unlockScroll = () => {
    if (!scrollState) return;
    const body = document.body; const restore = scrollState; scrollState = null;
    body.style.position = restore.position; body.style.top = restore.top; body.style.width = restore.width; body.style.overflow = restore.overflow;
    document.documentElement.classList.remove('sfs-mobile-search-open');
    window.scrollTo({ top: restore.y, left: 0, behavior: 'auto' });
  };

  const suggestionCache = new Map();
  const initialize = (scope = document) => {
    if (!scope || typeof scope.querySelectorAll !== 'function') return;
    const components = (selector) => [...(typeof scope.matches === 'function' && scope.matches(selector) ? [scope] : []), ...scope.querySelectorAll(selector)];
    components('[data-starfiniti-search]').forEach((root) => {
    if (root.dataset.starfinitiSearchInitialized === 'true') return;
    root.dataset.starfinitiSearchInitialized = 'true';
    const input = root.querySelector('[role="combobox"]');
    const list = root.querySelector('[role="listbox"]');
    const status = root.querySelector('[role="status"]');
    const closeButton = root.querySelector('[data-sfs-close]');
    const mobileMedia = window.matchMedia('(max-width: 42rem)');
    let timer = 0;
    let controller = null;
    let active = -1;
    let sequence = 0;
    let composing = false;
    let suppressOverlayOpen = false;

    const setState = (state) => { root.dataset.state = state; };
    const close = () => {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
      setState('closed');
    };
    const closeOverlay = (restoreFocus = true) => {
      if (root.dataset.mobileOverlay !== 'open') return;
      close(); controller?.abort(); sequence += 1;
      delete root.dataset.mobileOverlay;
      root.setAttribute('role', 'search'); root.removeAttribute('aria-modal'); root.removeAttribute('aria-label');
      if (closeButton) closeButton.hidden = true;
      if (activeOverlayClose === closeOverlay) { activeOverlayClose = null; unlockScroll(); }
      emit(root, 'mobile-overlay-closed', {});
      if (restoreFocus) {
        suppressOverlayOpen = true; input.focus({ preventScroll: true });
        window.setTimeout(() => { suppressOverlayOpen = false; }, 0);
      }
    };
    const openOverlay = () => {
      if (config.mobileMode !== 'overlay' || !mobileMedia.matches || root.dataset.mobileOverlay === 'open') return;
      if (activeOverlayClose && activeOverlayClose !== closeOverlay) activeOverlayClose(false);
      activeOverlayClose = closeOverlay; lockScroll();
      root.dataset.mobileOverlay = 'open'; root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true');
      root.setAttribute('aria-label', message('mobileSearch', 'Product search'));
      if (closeButton) closeButton.hidden = false;
      emit(root, 'mobile-overlay-opened', {});
    };
    const activate = (index) => {
      const options = [...list.querySelectorAll('[role="option"]')];
      if (!options.length) return;
      active = (index + options.length) % options.length;
      options.forEach((option, i) => option.setAttribute('aria-selected', i === active ? 'true' : 'false'));
      input.setAttribute('aria-activedescendant', options[active].id);
      options[active].scrollIntoView({ block: 'nearest' });
      status.textContent = options[active].textContent || '';
    };
    const render = (payload) => {
      list.replaceChildren();
      const hits = Array.isArray(payload.hits) ? payload.hits : [];
      hits.forEach((hit, index) => {
        const identity = hit.projection?.identity || {};
        const option = document.createElement('li');
        option.id = `${list.id}-option-${index}`;
        option.className = 'sfs-search__option';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        const link = document.createElement('a');
        link.href = safeUrl(identity.url);
        link.append(text(identity.title));
        option.append(link);
        list.append(option);
      });
      status.textContent = hits.length ? `${hits.length} results` : message('empty', 'No products found.');
      list.hidden = hits.length === 0;
      input.setAttribute('aria-expanded', hits.length ? 'true' : 'false');
      active = -1;
      setState(hits.length ? 'success' : 'no_results');
      emit(root, 'suggestions-returned', { queryId: payload.query_id, count: hits.length, provider: payload.provider });
    };
    const search = async () => {
      const query = input.value.trim();
      if (query.length < 2) { close(); setState(query ? 'focused_empty' : 'idle'); return; }
      const requestSequence = ++sequence;
      const cacheKey = `${config.locale || ''}|${config.currency || ''}|${query}`;
      if (suggestionCache.has(cacheKey)) { render(suggestionCache.get(cacheKey)); return; }
      controller?.abort();
      controller = new AbortController();
      status.textContent = message('loading', 'Searching…');
      setState('loading');
      try {
        const url = new URL(config.endpoint, window.location.href);
        url.searchParams.set('q', query);
        url.searchParams.set('size', '8');
        const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('search_failed');
        const payload = await response.json();
        if (requestSequence !== sequence) return;
        suggestionCache.set(cacheKey, payload);
        if (suggestionCache.size > 20) suggestionCache.delete(suggestionCache.keys().next().value);
        render(payload);
      } catch (error) {
        if (error.name !== 'AbortError' && requestSequence === sequence) {
          close();
          status.textContent = message('error', 'Search is unavailable. Submit the form for normal product search.');
          setState('error');
        }
      }
    };
    const queue = () => {
      if (composing) return;
      window.clearTimeout(timer);
      setState('debouncing');
      timer = window.setTimeout(search, 180);
    };
    input.addEventListener('compositionstart', () => { composing = true; });
    input.addEventListener('compositionend', () => { composing = false; queue(); });
    input.addEventListener('input', queue);
    input.addEventListener('focus', () => {
      if (!suppressOverlayOpen) openOverlay();
      setState(input.value ? 'debouncing' : 'focused_empty');
      if (!suppressOverlayOpen && input.value.trim().length >= 2) queue();
    });
    input.addEventListener('keydown', (event) => {
      const length = list.querySelectorAll('[role="option"]').length;
      if (event.key === 'ArrowDown') { event.preventDefault(); activate(active + 1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); activate(active - 1); }
      else if (event.key === 'Home' && length && input.getAttribute('aria-expanded') === 'true') { event.preventDefault(); activate(0); }
      else if (event.key === 'End' && length && input.getAttribute('aria-expanded') === 'true') { event.preventDefault(); activate(length - 1); }
      else if (event.key === 'Escape') { event.preventDefault(); if (root.dataset.mobileOverlay === 'open') closeOverlay(); else close(); }
      else if (event.key === 'Tab' && root.dataset.mobileOverlay !== 'open') close();
      else if (event.key === 'Enter' && active >= 0) {
        const link = list.querySelectorAll('[role="option"]')[active]?.querySelector('a');
        if (link) { event.preventDefault(); link.click(); }
      }
    });
    root.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && root.dataset.mobileOverlay === 'open') { event.preventDefault(); closeOverlay(); return; }
      if (event.key !== 'Tab' || root.dataset.mobileOverlay !== 'open') return;
      const focusable = [...root.querySelectorAll('button:not([hidden]):not([disabled]),input:not([type="hidden"]),a[href]')].filter((element) => !element.hidden);
      if (!focusable.length) return;
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    closeButton?.addEventListener('click', () => closeOverlay());
    mobileMedia.addEventListener?.('change', (event) => { if (!event.matches) closeOverlay(false); });
    document.addEventListener('click', (event) => { if (!root.contains(event.target)) close(); });
    setState('idle');
  });

  components('[data-starfiniti-discovery]').forEach((root) => {
    if (root.dataset.starfinitiDiscoveryInitialized === 'true') return;
    root.dataset.starfinitiDiscoveryInitialized = 'true';
    const form = root.querySelector('form');
    const queryInput = form.querySelector('input[name="s"]');
    const sortInput = form.querySelector('select[name="sfs_sort"]');
    const categoryOptions = form.querySelector('[data-sfs-category-options]');
    const results = root.querySelector('[data-sfs-results]');
    const status = root.querySelector('[data-sfs-status]');
    const pagination = root.querySelector('.sfs-discovery__pagination');
    const previous = root.querySelector('[data-sfs-previous]');
    const next = root.querySelector('[data-sfs-next]');
    const pageLabel = root.querySelector('[data-sfs-page]');
    const bindings = globalThis.StarfinitiDiscoveryState;
    const decimals = Math.max(0, Math.min(6, Number(config.currencyMinorUnit) || 0));
    const definitions = [...form.querySelectorAll('[data-sfs-filter]')].map((element) => ({
      id: element.dataset.sfsFilter, field: element.dataset.sfsField,
      parameter: element.dataset.sfsParameter, type: element.dataset.sfsType, label: element.querySelector('legend').textContent, element,
    }));
    const facets = [...new Set(['inventory.stock_status', 'classification.category_paths', ...definitions.filter((item) => item.type !== 'range').map((item) => item.field)])];
    let controller = null;
    let sequence = 0;
    const emptyState = () => ({ query: '', stock: [], categories: [], sort: 'relevance', page: 1, configured: bindings.read(definitions, new URLSearchParams(), decimals) });
    let state = emptyState();
    const panel = root.querySelector('[data-sfs-panel]');
    const openFilters = root.querySelector('[data-sfs-open-filters]');
    const closeFilters = root.querySelector('[data-sfs-close-filters]');
    const panelStatus = root.querySelector('[data-sfs-panel-status]');
    const selection = root.querySelector('[data-sfs-selection]');
    const chips = root.querySelector('[data-sfs-chips]');
    const clear = root.querySelector('[data-sfs-clear]');
    const notice = root.querySelector('[data-sfs-notice]');
    const noticeText = root.querySelector('[data-sfs-notice-text]');
    const retry = root.querySelector('[data-sfs-retry]');
    const reset = root.querySelector('[data-sfs-reset]');
    let compact = null;
    let panelLocked = false;
    const closePanel = () => {
      if (!compact) return;
      panel.close(); openFilters.setAttribute('aria-expanded', 'false');
      if (panelLocked) { unlockScroll(); panelLocked = false; }
      openFilters.focus({ preventScroll: true });
    };
    const resizePanel = () => {
      const nextCompact = root.getBoundingClientRect().width < 800;
      if (compact === nextCompact) return;
      const previousFocus = document.activeElement;
      const focusInside = panel.contains(document.activeElement);
      compact = nextCompact; root.dataset.compact = String(compact);
      if (compact || panel.matches(':modal')) panel.close();
      if (panelLocked) { unlockScroll(); panelLocked = false; }
      if (!compact && !panel.open) { panel.show(); if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true }); }
      panel.setAttribute('role', compact ? 'dialog' : 'region');
      openFilters.hidden = !compact; closeFilters.hidden = !compact;
      openFilters.setAttribute('aria-expanded', 'false');
      if (focusInside && compact) openFilters.focus({ preventScroll: true });
    };
    if (typeof panel.showModal === 'function') {
      openFilters.addEventListener('click', () => {
        activeOverlayClose?.(false);
        panel.showModal(); openFilters.setAttribute('aria-expanded', 'true');
        if (!panelLocked) { lockScroll(); panelLocked = true; }
        closeFilters.focus({ preventScroll: true });
      });
      closeFilters.addEventListener('click', closePanel);
      root.querySelector('[data-sfs-show-results]').addEventListener('click', () => { if (validatePrices(true)) closePanel(); });
      panel.addEventListener('cancel', (event) => { event.preventDefault(); closePanel(); });
      panel.addEventListener('keydown', (event) => {
        if (!compact || !panel.open || event.key !== 'Tab') return;
        const controls = [...panel.querySelectorAll('button, input, select, a[href], [tabindex]')]
          .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
        const first = controls[0]; const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      });
      panel.addEventListener('click', (event) => {
        const bounds = panel.getBoundingClientRect();
        if (compact && event.target === panel && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) closePanel();
      });
      resizePanel();
      new ResizeObserver(resizePanel).observe(root);
    }
    const valueLabel = (field, value) => {
      if (field === 'inventory.stock_status') return ({ instock: message('inStock', 'In stock'), outofstock: message('outOfStock', 'Out of stock'), onbackorder: message('onBackorder', 'On backorder') })[value] || value;
      if (field === 'quality.featured') return value === '1' ? message('featured', 'Featured') : value === '0' ? message('notFeatured', 'Not featured') : value;
      return value;
    };
    const validatePrices = (focus = false) => {
      let invalid = null;
      definitions.filter((item) => item.type === 'range').forEach(({ element }) => {
        const minimum = element.querySelector('[data-sfs-min]'); const maximum = element.querySelector('[data-sfs-max]');
        const error = element.querySelector('[data-sfs-range-error]');
        let valid = minimum.validity.valid && maximum.validity.valid;
        try { if (minimum.value || maximum.value) bindings.range(`${minimum.value}-${maximum.value}`, decimals); } catch (_) { valid = false; }
        error.hidden = valid; error.textContent = valid ? '' : message('priceError', 'Enter valid prices with the minimum no greater than the maximum.');
        [minimum, maximum].forEach((input) => {
          if (valid) { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); }
          else { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', error.id); }
        });
        if (!valid) invalid ||= minimum;
      });
      if (invalid && focus) invalid.focus();
      return !invalid;
    };

    const fromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        query: (params.get('s') || '').slice(0, 512),
        stock: params.getAll('sfs_stock').filter((value) => ['instock', 'outofstock', 'onbackorder'].includes(value)).slice(0, 3),
        categories: params.getAll('sfs_category').map((value) => value.slice(0, 191)).slice(0, 16),
        sort: ['relevance', 'price_asc', 'price_desc', 'title_asc'].includes(params.get('sfs_sort')) ? params.get('sfs_sort') : 'relevance',
        page: Math.max(1, Math.min(10000, Number.parseInt(params.get('sfs_page') || '1', 10) || 1)),
        configured: bindings.read(definitions, params, decimals),
      };
    };
    const syncControls = () => {
      queryInput.value = state.query;
      sortInput.value = state.sort;
      form.querySelectorAll('input[name="sfs_stock"]').forEach((input) => { input.checked = state.stock.includes(input.value); });
      form.querySelectorAll('input[name="sfs_category"]').forEach((input) => { input.checked = state.categories.includes(input.value); });
      definitions.forEach(({ id, type, element }) => {
        const selected = state.configured[id] || [];
        if (type === 'range') {
          element.querySelector('[data-sfs-min]').value = selected[0] || '';
          element.querySelector('[data-sfs-max]').value = selected[1] || '';
        } else if (type === 'select') element.querySelector('select').value = selected[0] || '';
        else element.querySelectorAll('input').forEach((input) => { input.checked = selected.includes(input.value) || (type === 'radio' && !selected.length && input.value === ''); });
      });
    };
    const updateUrl = () => {
      const url = new URL(window.location.href);
      ['s', 'sfs_stock', 'sfs_category', 'sfs_sort', 'sfs_page', 'post_type'].forEach((key) => url.searchParams.delete(key));
      if (state.query) url.searchParams.set('s', state.query);
      state.stock.forEach((value) => url.searchParams.append('sfs_stock', value));
      state.categories.forEach((value) => url.searchParams.append('sfs_category', value));
      if (state.sort !== 'relevance') url.searchParams.set('sfs_sort', state.sort);
      if (state.page > 1) url.searchParams.set('sfs_page', String(state.page));
      bindings.write(definitions, state.configured, url.searchParams, decimals);
      window.history.pushState({ starfinitiDiscovery: true }, '', url);
    };
    const filter = () => {
      const nodes = [];
      if (state.stock.length) nodes.push({ field: 'inventory.stock_status', op: 'in', value: state.stock });
      if (state.categories.length) nodes.push({ field: 'classification.category_paths', op: 'in', value: state.categories });
      nodes.push(...bindings.nodes(definitions, state.configured, decimals));
      return nodes.length === 0 ? null : (nodes.length === 1 ? nodes[0] : { and: nodes });
    };
    const sort = () => ({
      price_asc: [{ field: 'pricing.active_min_minor', direction: 'asc' }],
      price_desc: [{ field: 'pricing.active_min_minor', direction: 'desc' }],
      title_asc: [{ field: 'identity.title', direction: 'asc' }],
    }[state.sort] || []);
    const renderChoices = (container, field, type, name, selected, available) => {
      if (!container) return;
      const options = new Map((Array.isArray(available) ? available : []).slice(0, 100).map((item) => [String(item.value), Number(item.count) || 0]));
      selected.forEach((value) => { if (!options.has(value)) options.set(value, 0); });
      const ordered = [...options].sort((a, b) => valueLabel(field, a[0]).localeCompare(valueLabel(field, b[0])));
      const group = container.closest('fieldset');
      group.hidden = ordered.length === 0;
      if (type !== 'checkboxes') ordered.unshift(['', null]);
      const focused = container.contains(document.activeElement) ? document.activeElement : null;
      const existing = new Map([...container.children].map((row) => [row.dataset.value, row]));
      const retained = new Set();
      ordered.forEach(([value, count], index) => {
        let row = existing.get(value);
        if (!row) {
          row = document.createElement(type === 'select' ? 'option' : 'label'); row.dataset.value = value;
          if (type !== 'select') {
            const input = document.createElement('input'); input.type = type === 'radio' ? 'radio' : 'checkbox'; input.value = value; input.name = name;
            row.append(input, document.createElement('span'), document.createElement('span'));
            row.lastChild.className = 'sfs-discovery__count';
          } else row.value = value;
        }
        retained.add(row);
        const label = value === '' ? message('all', 'All') : valueLabel(field, value);
        if (type === 'select') row.textContent = count === null ? label : `${label} (${count})`;
        else {
          row.children[1].textContent = label; row.children[2].textContent = count === null ? '' : String(count);
          row.firstChild.checked = selected.includes(value) || (type === 'radio' && !selected.length && value === '');
        }
        if (container.children[index] !== row) container.insertBefore(row, container.children[index] || null);
      });
      [...container.children].forEach((row) => { if (!retained.has(row)) row.remove(); });
      if (type === 'select') container.value = selected[0] || '';
      if (focused?.isConnected && document.activeElement !== focused) focused.focus({ preventScroll: true });
    };
    const renderFacets = (responseFacets) => {
      renderChoices(categoryOptions, 'classification.category_paths', 'checkboxes', 'sfs_category', state.categories, responseFacets['classification.category_paths']);
      renderChoices(form.querySelector('[data-sfs-stock-options]'), 'inventory.stock_status', 'checkboxes', 'sfs_stock', state.stock, responseFacets['inventory.stock_status']);
      definitions.filter((item) => item.type !== 'range').forEach(({ id, field, type, element }) => {
        renderChoices(element.querySelector('[data-sfs-filter-options]'), field, type, `${root.getAttribute('aria-labelledby')}-${id}`, state.configured[id] || [], responseFacets[field]);
      });
    };
    const renderSelection = () => {
      const entries = [];
      const add = (key, label, remove) => entries.push({ key, label, remove });
      state.stock.forEach((value) => add(`stock:${value}`, valueLabel('inventory.stock_status', value), () => { state.stock = state.stock.filter((item) => item !== value); }));
      state.categories.forEach((value) => add(`category:${value}`, value, () => { state.categories = state.categories.filter((item) => item !== value); }));
      definitions.forEach(({ id, label, field, type }) => {
        const selected = state.configured[id] || [];
        if (type === 'range') {
          if (selected.some(Boolean)) add(id, `${label}: ${selected[0] || '0'} – ${selected[1] || '…'} ${config.currency || ''}`, () => { state.configured[id] = ['', '']; });
        } else selected.forEach((value) => add(`${id}:${value}`, `${label}: ${valueLabel(field, value)}`, () => { state.configured[id] = selected.filter((item) => item !== value); }));
      });
      chips.replaceChildren();
      entries.forEach(({ key, label, remove }) => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'sfs-discovery__chip'; button.dataset.key = key;
        button.setAttribute('aria-label', message('removeFilter', 'Remove %s').replace('%s', label));
        button.append(text(label)); const cross = document.createElement('span'); cross.setAttribute('aria-hidden', 'true'); cross.textContent = '×'; button.append(cross);
        button.addEventListener('click', () => { remove(); state.page = 1; syncControls(); validatePrices(); renderSelection(); (chips.querySelector('button') || (compact ? openFilters : queryInput)).focus(); load(); });
        chips.append(button);
      });
      selection.hidden = entries.length === 0;
      openFilters.textContent = `${message('filters', 'Filters')}${entries.length ? ` (${entries.length})` : ''}`;
    };
    const priceText = (pricing) => {
      const minor = pricing?.active_min_minor;
      if (!Number.isInteger(minor)) return '';
      const divisor = 10 ** Math.max(0, Math.min(6, Number(config.currencyMinorUnit) || 0));
      try { return new Intl.NumberFormat((config.locale || 'en').replace('_', '-'), { style: 'currency', currency: pricing.currency || config.currency || 'USD' }).format(minor / divisor); }
      catch (_) { return `${minor / divisor} ${pricing.currency || config.currency || ''}`.trim(); }
    };
    const renderResults = (payload) => {
      results.replaceChildren();
      (Array.isArray(payload.hits) ? payload.hits : []).forEach((hit, index) => {
        const projection = hit.projection || {};
        const identity = projection.identity || {};
        const article = document.createElement('article'); article.className = 'sfs-product-card';
        const imageUrl = safeUrl(projection.media?.thumbnail_url || projection.media?.primary_image_url);
        if (imageUrl !== '#') { const image = document.createElement('img'); image.src = imageUrl; image.alt = projection.media?.alt || ''; image.loading = 'lazy'; image.width = 320; image.height = 320; image.addEventListener('error', () => image.remove(), { once: true }); article.append(image); }
        const heading = document.createElement('h3'); const link = document.createElement('a'); link.href = safeUrl(identity.url); link.append(text(identity.title)); heading.append(link); article.append(heading);
        const price = priceText(projection.pricing); if (price) { const element = document.createElement('p'); element.className = 'sfs-product-card__price'; element.append(text(price)); article.append(element); }
        const meta = document.createElement('p'); meta.className = 'sfs-product-card__meta'; meta.append(text(valueLabel('inventory.stock_status', projection.inventory?.stock_status || ''))); article.append(meta);
        const detailsId = `${root.getAttribute('aria-labelledby')}-details-${Number(hit.entity_id) || index}`;
        const detailsToggle = document.createElement('button'); detailsToggle.type = 'button'; detailsToggle.className = 'sfs-product-card__details-toggle';
        detailsToggle.setAttribute('aria-expanded', 'false'); detailsToggle.setAttribute('aria-controls', detailsId); detailsToggle.append(text(message('viewDetails', 'View details')));
        const details = document.createElement('div'); details.id = detailsId; details.className = 'sfs-product-card__details'; details.hidden = true;
        details.setAttribute('role', 'region'); details.setAttribute('aria-label', `${message('details', 'Product details')}: ${identity.title || ''}`);
        const summary = String(projection.content?.excerpt || projection.content?.short_description_text || '').trim().slice(0, 1000);
        if (summary) { const description = document.createElement('p'); description.append(text(summary)); details.append(description); }
        const availability = document.createElement('p'); availability.append(text(`${message('availability', 'Availability')}: ${valueLabel('inventory.stock_status', projection.inventory?.stock_status || '')}`)); details.append(availability);
        const productUrl = safeUrl(identity.url); if (productUrl !== '#') { const productLink = document.createElement('a'); productLink.href = productUrl; productLink.append(text(message('viewProduct', 'View product'))); details.append(productLink); }
        detailsToggle.addEventListener('click', () => {
          const opening = details.hidden; details.hidden = !opening; detailsToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
          detailsToggle.replaceChildren(text(message(opening ? 'hideDetails' : 'viewDetails', opening ? 'Hide details' : 'View details')));
          emit(root, opening ? 'details-opened' : 'details-closed', { entityId: Number(hit.entity_id) || null });
        });
        article.append(detailsToggle, details);
        results.append(article);
      });
      const total = Math.max(0, Number(payload.total) || 0);
      status.textContent = message('results', '%d products found.').replace('%d', String(total));
      panelStatus.textContent = status.textContent;
      notice.hidden = total !== 0; noticeText.textContent = message('emptyHint', 'Try removing a filter or changing your search.'); retry.hidden = true; reset.hidden = false;
      pageLabel.textContent = `${state.page} / ${Math.max(1, Math.ceil(total / 12))}`;
      previous.disabled = state.page <= 1;
      next.disabled = !payload.page?.has_more;
      pagination.hidden = total === 0;
      renderFacets(payload.facets || {});
      emit(root, 'results-rendered', { queryId: payload.query_id, total, page: state.page, provider: payload.provider, indexVersion: payload.index_version });
    };
    const load = async (changeUrl = true) => {
      const requestSequence = ++sequence;
      controller?.abort(); controller = new AbortController();
      results.setAttribute('aria-busy', 'true'); status.textContent = message('loading', 'Searching…');
      panelStatus.textContent = status.textContent; notice.hidden = true;
      try {
        const filters = filter();
        if (changeUrl) updateUrl();
        const response = await fetch(config.endpoint, {
          method: 'POST', credentials: 'same-origin', signal: controller.signal,
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ query: state.query, filters, facets, sort: sort(), page: { number: state.page, size: 12 }, options: { suggestion_mode: 'full_results', highlight: false, facet_mode: 'disjunctive' } }),
        });
        if (!response.ok) throw new Error('search_failed');
        const payload = await response.json();
        if (requestSequence !== sequence) return;
        const redirect = safeInternalUrl(payload.redirect?.url);
        if (redirect) { window.location.assign(redirect); return; }
        renderResults(payload);
      } catch (error) {
        if (error.name !== 'AbortError' && requestSequence === sequence) {
          status.textContent = error.message === 'invalid_price' ? message('invalidFilters', 'Check the price range.') : message('error', 'Search is temporarily unavailable.');
          panelStatus.textContent = status.textContent;
          notice.hidden = false; noticeText.textContent = message('unavailableHint', 'Your filters are saved. Try loading the products again.'); retry.hidden = false; reset.hidden = true;
          results.replaceChildren(); pagination.hidden = true;
          emit(root, 'search-error', { code: error.message === 'invalid_price' ? 'invalid_filters' : 'unavailable' });
        }
      } finally { if (requestSequence === sequence) results.setAttribute('aria-busy', 'false'); }
    };
    const readControls = () => {
      state.query = queryInput.value.trim().slice(0, 512);
      if (form.querySelector('[data-sfs-stock-options]')) state.stock = [...form.querySelectorAll('input[name="sfs_stock"]:checked')].map((input) => input.value).slice(0, 3);
      if (categoryOptions) state.categories = [...form.querySelectorAll('input[name="sfs_category"]:checked')].map((input) => input.value).slice(0, 16);
      state.sort = sortInput.value;
      state.page = 1;
      state.configured = Object.fromEntries(definitions.map(({ id, type, element }) => [id, type === 'range'
        ? [element.querySelector('[data-sfs-min]').value, element.querySelector('[data-sfs-max]').value]
        : (type === 'select' ? [element.querySelector('select').value] : [...element.querySelectorAll('input:checked')].map((input) => input.value)).filter(Boolean).slice(0, type === 'checkboxes' ? 16 : 1)]));
    };
    const submitControls = (focus = false) => {
      if (!validatePrices(focus)) {
        ++sequence; controller?.abort(); results.replaceChildren(); results.setAttribute('aria-busy', 'false'); pagination.hidden = true;
        status.textContent = message('invalidFilters', 'Check the price range.'); panelStatus.textContent = status.textContent; return;
      }
      readControls(); renderSelection(); load();
    };
    form.noValidate = true;
    form.addEventListener('submit', (event) => { event.preventDefault(); submitControls(true); });
    form.addEventListener('change', (event) => { if (event.target !== queryInput) submitControls(false); });
    const clearFilters = () => { const { query, sort: selectedSort } = state; state = { ...emptyState(), query, sort: selectedSort }; syncControls(); validatePrices(); renderSelection(); load(); };
    clear.addEventListener('click', () => { clearFilters(); queryInput.focus(); });
    root.querySelector('[data-sfs-panel-clear]').addEventListener('click', clearFilters);
    reset.addEventListener('click', () => { state = emptyState(); syncControls(); validatePrices(); renderSelection(); load(); queryInput.focus(); });
    retry.addEventListener('click', () => { status.tabIndex = -1; status.focus(); load(false); });
    previous.addEventListener('click', () => { if (state.page > 1) { state.page -= 1; load(); root.scrollIntoView({ block: 'start' }); } });
    next.addEventListener('click', () => { state.page += 1; load(); root.scrollIntoView({ block: 'start' }); });
    const restore = () => {
      try { state = fromUrl(); renderFacets({}); syncControls(); validatePrices(); renderSelection(); load(false); }
      catch (_) { ++sequence; controller?.abort(); results.replaceChildren(); pagination.hidden = true; results.setAttribute('aria-busy', 'false'); status.textContent = message('invalidFilters', 'Check the price range.'); panelStatus.textContent = status.textContent; notice.hidden = false; noticeText.textContent = status.textContent; retry.hidden = true; reset.hidden = false; }
    };
    window.addEventListener('popstate', restore);
    restore();
  });
  };

  window.StarfinitiSearch = Object.freeze({ initialize });
  initialize();
})();
