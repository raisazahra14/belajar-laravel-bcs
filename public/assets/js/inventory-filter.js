(function () {
  'use strict';

  function initializeInventoryFilter() {
    const form = document.getElementById('inventory-filter-form');
    const region = document.getElementById('inventory-results-region');
    const content = document.getElementById('inventory-results-content');
    const feedback = document.getElementById('inventory-filter-feedback');
    if (!form || !region || !content || !feedback || form.dataset.initialized === 'true') return;

    form.dataset.initialized = 'true';
    const search = form.elements.search;
    const selects = Array.from(form.querySelectorAll('select'));
    let debounceTimer = null;
    let composing = false;
    let request = null;
    let requestSequence = 0;
    let displayedQuery = normalizedQuery(new URL(window.location.href).searchParams);

    function normalizedQuery(params) {
      const normalized = new URLSearchParams();
      ['search', 'kategori', 'status', 'sort', 'page'].forEach(function (name) {
        const value = (params.get(name) || '').trim();
        if (value && !(name === 'page' && value === '1')) normalized.set(name, value);
      });
      return normalized.toString();
    }

    function formQuery() {
      const params = new URLSearchParams(new FormData(form));
      params.delete('page');
      return normalizedQuery(params);
    }

    function pageUrl(query) {
      const url = new URL(form.action, window.location.origin);
      url.search = query;
      return url;
    }

    function syncForm(url) {
      const params = new URL(url, window.location.origin).searchParams;
      ['search', 'kategori', 'status', 'sort'].forEach(function (name) {
        if (form.elements[name]) form.elements[name].value = params.get(name) || '';
      });
    }

    function setLoading(loading) {
      region.classList.toggle('is-loading', loading);
      region.setAttribute('aria-busy', String(loading));
      if (loading) content.style.minHeight = content.getBoundingClientRect().height + 'px';
      else content.style.minHeight = '';
    }

    function showFeedback(message, isError) {
      feedback.textContent = message;
      feedback.classList.toggle('is-error', isError);
      feedback.hidden = false;
    }

    function loadResults(targetUrl, historyMode) {
      const target = new URL(targetUrl, window.location.origin);
      const query = normalizedQuery(target.searchParams);
      if (historyMode !== 'none' && query === displayedQuery) return;
      if (request) request.abort();

      const controller = new AbortController();
      const sequence = ++requestSequence;
      request = controller;
      setLoading(true);
      showFeedback('Memperbarui daftar barang…', false);
      const endpoint = new URL(form.dataset.resultsEndpoint, window.location.origin);
      endpoint.search = query;

      fetch(endpoint, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: controller.signal })
        .then(function (response) { if (!response.ok) throw new Error('Hasil barang tidak tersedia'); return response.text(); })
        .then(function (html) {
          if (sequence !== requestSequence) return;
          content.innerHTML = html;
          displayedQuery = query;
          const visibleUrl = pageUrl(query);
          if (historyMode === 'push') window.history.pushState({ inventoryQuery: query }, '', visibleUrl);
          else if (historyMode === 'replace') window.history.replaceState({ inventoryQuery: query }, '', visibleUrl);
          showFeedback('Daftar barang berhasil diperbarui.', false);
        })
        .catch(function (error) {
          if (error.name !== 'AbortError') showFeedback('Daftar barang belum dapat diperbarui. Gunakan tombol Terapkan untuk mencoba kembali.', true);
        })
        .finally(function () {
          if (request !== controller) return;
          request = null;
          setLoading(false);
        });
    }

    function scheduleSearch() {
      window.clearTimeout(debounceTimer);
      if (composing) return;
      debounceTimer = window.setTimeout(function () { loadResults(pageUrl(formQuery()), 'push'); }, 400);
    }

    search.addEventListener('compositionstart', function () { composing = true; window.clearTimeout(debounceTimer); });
    search.addEventListener('compositionend', function () { composing = false; scheduleSearch(); });
    search.addEventListener('input', scheduleSearch);
    selects.forEach(function (select) { select.addEventListener('change', function () { loadResults(pageUrl(formQuery()), 'push'); }); });
    form.addEventListener('submit', function (event) { event.preventDefault(); window.clearTimeout(debounceTimer); loadResults(pageUrl(formQuery()), 'push'); });
    form.addEventListener('click', function (event) {
      const clear = event.target.closest('[data-clear-filters]');
      if (!clear) return;
      event.preventDefault();
      form.reset();
      loadResults(form.action, 'push');
    });

    region.addEventListener('click', function (event) {
      const link = event.target.closest('[data-remove-filter], [data-clear-filters], [data-inventory-pagination] a');
      if (!link) return;
      event.preventDefault();
      const target = new URL(link.href, window.location.origin);
      syncForm(target);
      loadResults(target, 'push');
    });

    window.addEventListener('popstate', function () {
      window.clearTimeout(debounceTimer);
      syncForm(window.location.href);
      loadResults(window.location.href, 'none');
    });
    window.addEventListener('pagehide', function () { if (request) request.abort(); }, { once: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeInventoryFilter, { once: true });
  else initializeInventoryFilter();
}());
