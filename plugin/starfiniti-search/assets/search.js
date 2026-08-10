(() => {
  'use strict';

  const config = window.StarfinitiSearchConfig || {};
  const text = (value) => document.createTextNode(String(value ?? ''));
  const message = (key, fallback) => config.messages?.[key] || fallback;
  const safeUrl = (value) => {
    try {
      const url = new URL(String(value || ''), window.location.href);
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
    const status = root.querySelector('[role="status"]');
    const pagination = root.querySelector('.sfs-discovery__pagination');
    const previous = root.querySelector('[data-sfs-previous]');
    const next = root.querySelector('[data-sfs-next]');
    const pageLabel = root.querySelector('[data-sfs-page]');
    let controller = null;
    let sequence = 0;
    let state = { query: '', stock: [], categories: [], sort: 'relevance', page: 1 };

    const fromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        query: (params.get('s') || '').slice(0, 512),
        stock: params.getAll('sfs_stock').filter((value) => ['instock', 'outofstock', 'onbackorder'].includes(value)).slice(0, 3),
        categories: params.getAll('sfs_category').map((value) => value.slice(0, 191)).slice(0, 16),
        sort: ['relevance', 'price_asc', 'price_desc', 'title_asc'].includes(params.get('sfs_sort')) ? params.get('sfs_sort') : 'relevance',
        page: Math.max(1, Math.min(10000, Number.parseInt(params.get('sfs_page') || '1', 10) || 1)),
      };
    };
    const syncControls = () => {
      queryInput.value = state.query;
      sortInput.value = state.sort;
      form.querySelectorAll('input[name="sfs_stock"]').forEach((input) => { input.checked = state.stock.includes(input.value); });
      form.querySelectorAll('input[name="sfs_category"]').forEach((input) => { input.checked = state.categories.includes(input.value); });
    };
    const updateUrl = () => {
      const url = new URL(window.location.href);
      ['s', 'sfs_stock', 'sfs_category', 'sfs_sort', 'sfs_page', 'post_type'].forEach((key) => url.searchParams.delete(key));
      if (state.query) url.searchParams.set('s', state.query);
      state.stock.forEach((value) => url.searchParams.append('sfs_stock', value));
      state.categories.forEach((value) => url.searchParams.append('sfs_category', value));
      if (state.sort !== 'relevance') url.searchParams.set('sfs_sort', state.sort);
      if (state.page > 1) url.searchParams.set('sfs_page', String(state.page));
      window.history.pushState({ starfinitiDiscovery: true }, '', url);
    };
    const filter = () => {
      const nodes = [];
      if (state.stock.length) nodes.push({ field: 'inventory.stock_status', op: 'in', value: state.stock });
      if (state.categories.length) nodes.push({ field: 'classification.category_paths', op: 'in', value: state.categories });
      return nodes.length === 0 ? null : (nodes.length === 1 ? nodes[0] : { and: nodes });
    };
    const sort = () => ({
      price_asc: [{ field: 'pricing.active_min_minor', direction: 'asc' }],
      price_desc: [{ field: 'pricing.active_min_minor', direction: 'desc' }],
      title_asc: [{ field: 'identity.title', direction: 'asc' }],
    }[state.sort] || []);
    const renderFacets = (facets) => {
      const values = Array.isArray(facets?.['classification.category_paths']) ? facets['classification.category_paths'] : [];
      const all = [...values];
      state.categories.forEach((selected) => { if (!all.some((item) => item.value === selected)) all.push({ value: selected, count: 0 }); });
      categoryOptions.replaceChildren();
      all.slice(0, 30).forEach((item, index) => {
        const label = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'checkbox'; input.name = 'sfs_category'; input.value = String(item.value); input.checked = state.categories.includes(String(item.value));
        input.id = `${root.getAttribute('aria-labelledby')}-category-${index}`;
        label.append(input, text(` ${item.value} (${Number(item.count) || 0})`));
        categoryOptions.append(label);
      });
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
        const meta = document.createElement('p'); meta.className = 'sfs-product-card__meta'; meta.append(text([identity.sku, projection.inventory?.stock_status].filter(Boolean).join(' · '))); article.append(meta);
        const detailsId = `${root.getAttribute('aria-labelledby')}-details-${Number(hit.entity_id) || index}`;
        const detailsToggle = document.createElement('button'); detailsToggle.type = 'button'; detailsToggle.className = 'sfs-product-card__details-toggle';
        detailsToggle.setAttribute('aria-expanded', 'false'); detailsToggle.setAttribute('aria-controls', detailsId); detailsToggle.append(text(message('viewDetails', 'View details')));
        const details = document.createElement('div'); details.id = detailsId; details.className = 'sfs-product-card__details'; details.hidden = true;
        details.setAttribute('role', 'region'); details.setAttribute('aria-label', `${message('details', 'Product details')}: ${identity.title || ''}`);
        const summary = String(projection.content?.excerpt || projection.content?.short_description_text || '').trim().slice(0, 1000);
        if (summary) { const description = document.createElement('p'); description.append(text(summary)); details.append(description); }
        const availability = document.createElement('p'); availability.append(text(`${message('availability', 'Availability')}: ${projection.inventory?.stock_status || ''}`)); details.append(availability);
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
      pageLabel.textContent = `${state.page} / ${Math.max(1, Math.ceil(total / 12))}`;
      previous.disabled = state.page <= 1;
      next.disabled = !payload.page?.has_more;
      pagination.hidden = total === 0;
      renderFacets(payload.facets || {});
      emit(root, 'results-rendered', { queryId: payload.query_id, total, page: state.page, provider: payload.provider, indexVersion: payload.index_version });
    };
    const load = async (changeUrl = true) => {
      if (changeUrl) updateUrl();
      const requestSequence = ++sequence;
      controller?.abort(); controller = new AbortController();
      results.setAttribute('aria-busy', 'true'); status.textContent = message('loading', 'Searching…');
      try {
        const response = await fetch(config.endpoint, {
          method: 'POST', credentials: 'same-origin', signal: controller.signal,
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ query: state.query, filters: filter(), facets: ['inventory.stock_status', 'classification.category_paths'], sort: sort(), page: { number: state.page, size: 12 }, options: { suggestion_mode: 'full_results', highlight: false } }),
        });
        if (!response.ok) throw new Error('search_failed');
        const payload = await response.json();
        if (requestSequence !== sequence) return;
        const redirect = safeInternalUrl(payload.redirect?.url);
        if (redirect) { window.location.assign(redirect); return; }
        renderResults(payload);
      } catch (error) {
        if (error.name !== 'AbortError' && requestSequence === sequence) { status.textContent = message('error', 'Search is temporarily unavailable.'); emit(root, 'search-error', { code: 'unavailable' }); }
      } finally { if (requestSequence === sequence) results.setAttribute('aria-busy', 'false'); }
    };
    const readControls = () => {
      state.query = queryInput.value.trim().slice(0, 512);
      state.stock = [...form.querySelectorAll('input[name="sfs_stock"]:checked')].map((input) => input.value).slice(0, 3);
      state.categories = [...form.querySelectorAll('input[name="sfs_category"]:checked')].map((input) => input.value).slice(0, 16);
      state.sort = sortInput.value;
      state.page = 1;
    };
    form.addEventListener('submit', (event) => { event.preventDefault(); readControls(); load(); });
    form.addEventListener('change', () => { readControls(); load(); });
    form.querySelector('[data-sfs-clear]').addEventListener('click', () => { state = { query: '', stock: [], categories: [], sort: 'relevance', page: 1 }; syncControls(); load(); queryInput.focus(); });
    previous.addEventListener('click', () => { if (state.page > 1) { state.page -= 1; load(); root.scrollIntoView({ block: 'start' }); } });
    next.addEventListener('click', () => { state.page += 1; load(); root.scrollIntoView({ block: 'start' }); });
    window.addEventListener('popstate', () => { state = fromUrl(); syncControls(); load(false); });
    state = fromUrl(); syncControls(); load(false);
  });
  };

  window.StarfinitiSearch = Object.freeze({ initialize });
  initialize();
})();
