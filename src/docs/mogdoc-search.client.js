(function () {
  'use strict';

  const input = document.querySelector('.docs-search input[name="q"]');
  const hits = document.getElementById('mogdoc-hits');
  if (!input || !hits || typeof Moogle === 'undefined') {
    return;
  }

  const CACHE_KEY = 'moggi-moogle-index';
  let rows = [];
  let searchTimer = 0;
  let indexError = '';

  function cacheKey(data) {
    return String(data.version ?? 0) + ':' + String(data.revision ?? '');
  }

  function escapeHtml(text) {
    return text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function docSnippet(text, maxLen) {
    if (!text || text.length <= maxLen) {
      return text || '';
    }
    return text.slice(0, maxLen);
  }

  function showMessage(text) {
    hits.innerHTML = '';
    if (!text) {
      return;
    }
    const li = document.createElement('li');
    li.className = 'meta';
    li.textContent = text;
    hits.appendChild(li);
  }

  function loadRows(data) {
    rows = Array.isArray(data.search) ? data.search : [];
    indexError = '';
    try {
      sessionStorage.setItem(CACHE_KEY, JSON.stringify({
        key: cacheKey(data),
        search: rows,
      }));
    } catch (_) {
      // ignore quota / private mode
    }
  }

  function readCache() {
    try {
      const raw = sessionStorage.getItem(CACHE_KEY);
      if (!raw) {
        return null;
      }
      const data = JSON.parse(raw);
      if (!Array.isArray(data.search)) {
        return null;
      }
      return data;
    } catch (_) {
      return null;
    }
  }

  function runSearch(q) {
    hits.innerHTML = '';
    if (indexError) {
      showMessage(indexError);
      return;
    }
    if (!q) {
      return;
    }
    const top = Moogle.search(rows, q, 30);
    if (top.length === 0) {
      showMessage('No results.');
      return;
    }
    for (const hit of top) {
      const r = hit.entity;
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.href = r.href || '#';
      a.innerHTML = '<code>' + escapeHtml((r.module || '') + '.' + (r.name || '')) + '</code>';
      li.appendChild(a);
      if (r.signature) {
        const span = document.createElement('span');
        span.className = 'sig';
        span.textContent = r.signature;
        li.appendChild(document.createElement('br'));
        li.appendChild(span);
      }
      if (r.doc) {
        const doc = document.createElement('span');
        doc.className = 'doc';
        doc.textContent = docSnippet(r.doc, 120);
        li.appendChild(document.createElement('br'));
        li.appendChild(doc);
      }
      hits.appendChild(li);
    }
  }

  function scheduleSearch(q) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runSearch(q), 120);
  }

  function fetchIndex() {
    return fetch('search-index.json')
      .then(r => {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.json();
      });
  }

  function maybeRefresh(data) {
    if (cacheKey(data) !== (readCache()?.key ?? '')) {
      loadRows(data);
    }
  }

  const cached = readCache();
  let indexReady;
  if (cached) {
    rows = cached.search;
    indexReady = fetchIndex()
      .then(maybeRefresh)
      .catch(() => {});
  } else {
    indexReady = fetchIndex()
      .then(loadRows)
      .catch(() => {
        indexError = 'Could not load search index.';
        showMessage(indexError);
      });
  }

  indexReady.then(() => {
    const params = new URLSearchParams(window.location.search);
    const q = params.get('q');
    if (q) {
      input.value = q;
      runSearch(q);
    }
  });

  input.addEventListener('input', () => {
    indexReady.then(() => scheduleSearch(input.value.trim()));
  });
})();
