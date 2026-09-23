'use strict';
// Browser Moogle search — keep in sync with moogle_search.php (parity: tests/docs/moogle/parity_test.php)

function compareNullable(a, b) {
  return a < b ? -1 : a > b ? 1 : 0;
}

function entityMatchesKindFilter(entity, kind) {
  if (kind === 'value') {
    return ['value', 'foreign', 'primop'].includes(entity.kind ?? '');
  }

  return (entity.kind ?? '') === kind;
}

function parseSearchFilters(query) {
  const filters = {};
  const patterns = [
    [/\bis:module\b/i, { kind: 'module' }],
    [/\bis:class\b/i, { kind: 'class' }],
    [/\bis:type\b/i, { kind: 'type' }],
    [/\bis:value\b/i, { kind: 'value' }],
    [/\bmodule:([A-Za-z][A-Za-z0-9_.]*)\b/, null],
  ];

  for (const [pattern, filter] of patterns) {
    if (filter !== null) {
      if (pattern.test(query)) {
        Object.assign(filters, filter);
        query = query.replace(pattern, '').trim();
      }
      continue;
    }

    const m = query.match(pattern);
    if (m) {
      filters.module = m[1];
      query = query.replace(pattern, '').trim();
    }
  }

  return [query, filters];
}

function searchByFilters(entities, filters, limit) {
  const hits = [];
  for (const entity of entities) {
    if (filters.kind !== undefined && !entityMatchesKindFilter(entity, filters.kind)) {
      continue;
    }
    if (filters.module !== undefined && !(entity.module ?? '').startsWith(filters.module)) {
      continue;
    }
    hits.push({ entity, score: 50.0 });
  }

  hits.sort((a, b) => {
    const mod = compareNullable(a.entity.module ?? '', b.entity.module ?? '');
    if (mod !== 0) {
      return mod;
    }
    return compareNullable(a.entity.name ?? '', b.entity.name ?? '');
  });

  return hits.slice(0, limit);
}

function applySearchFilters(hits, filters) {
  if (Object.keys(filters).length === 0) {
    return hits;
  }

  const filtered = [];
  for (const hit of hits) {
    const entity = hit.entity;
    if (filters.kind !== undefined && !entityMatchesKindFilter(entity, filters.kind)) {
      continue;
    }
    if (filters.module !== undefined && !(entity.module ?? '').startsWith(filters.module)) {
      continue;
    }
    filtered.push(hit);
  }

  return filtered;
}

function looksLikeTypeQuery(query) {
  return query.includes('->')
    || query.includes('=>')
    || /\b[a-z][a-zA-Z0-9_']*\s*(->|=>)/.test(query);
}

function searchNameVariants(query) {
  const variants = [query];
  if (query.startsWith('(') && query.endsWith(')')) {
    variants.push(query.slice(1, -1));
  } else if (query !== '' && !query.includes(' ')) {
    variants.push('(' + query + ')');
  }

  return [...new Set(variants)];
}

function nameScore(name, query, qLower) {
  if (name === query) {
    return 100.0;
  }
  if (name.toLowerCase() === qLower) {
    return 95.0;
  }
  if (name.startsWith(query)) {
    return 80.0;
  }
  if (name.toLowerCase().startsWith(qLower)) {
    return 75.0;
  }
  if (name.toLowerCase().includes(qLower)) {
    return 50.0;
  }

  return 0.0;
}

function searchByName(entities, query, limit) {
  const hits = [];
  const variants = searchNameVariants(query);

  for (const entity of entities) {
    if ((entity.kind ?? '') === 'module') {
      continue;
    }

    let score = 0.0;
    for (const variant of variants) {
      score = Math.max(score, nameScore(entity.name ?? '', variant, variant.toLowerCase()));
    }
    const module = entity.module ?? '';
    if (module === query || module.endsWith('.' + query)) {
      score = Math.max(score, 90.0);
    }
    if (score > 0) {
      hits.push({ entity, score });
    }
  }

  hits.sort((a, b) => {
    const scoreCmp = b.score - a.score;
    if (scoreCmp !== 0) {
      return scoreCmp;
    }
    return compareNullable(a.entity.name ?? '', b.entity.name ?? '');
  });

  return hits.slice(0, limit);
}

function searchByNameAndType(entities, namePart, typePart, limit) {
  let hits = searchByType(entities, typePart, limit * 3);
  if (namePart === '') {
    return hits.slice(0, limit);
  }

  const filtered = [];
  for (const hit of hits) {
    const name = hit.entity.name ?? '';
    if (name.includes(namePart)) {
      filtered.push({
        entity: hit.entity,
        score: hit.score + nameScore(name, namePart, namePart.toLowerCase()),
      });
    }
  }

  if (filtered.length === 0) {
    return searchByName(entities, namePart, limit);
  }

  filtered.sort((a, b) => b.score - a.score);
  return filtered.slice(0, limit);
}

function searchByType(entities, typeQuery, limit) {
  const queryParts = splitTypeParts(typeQuery);
  const hits = [];

  for (const entity of entities) {
    const signature = entity.signature ?? null;
    if (signature === null) {
      continue;
    }
    const sig = extractTypeBody(signature);
    if (sig === null) {
      continue;
    }
    const score = typeMatchScore(queryParts, splitTypeParts(sig));
    if (score > 0) {
      hits.push({ entity, score });
    }
  }

  hits.sort((a, b) => {
    const scoreCmp = b.score - a.score;
    if (scoreCmp !== 0) {
      return scoreCmp;
    }
    return compareNullable(a.entity.name ?? '', b.entity.name ?? '');
  });

  return hits.slice(0, limit);
}

function extractTypeBody(signature) {
  const pos = signature.indexOf(' :: ');
  if (pos === -1) {
    return signature;
  }

  return signature.slice(pos + 4).trim();
}

function splitTypeParts(type) {
  type = type.trim().replace(/\s+/g, ' ');
  if (type.includes('=>')) {
    const parts = type.split('=>', 2);
    type = parts[1].trim();
  }
  if (!type.includes('->')) {
    return [normalizeListSugar(type)];
  }

  const parts = [];
  let depth = 0;
  let buf = '';
  for (let i = 0; i < type.length; ++i) {
    const ch = type[i];
    if (ch === '(') {
      ++depth;
    } else if (ch === ')') {
      --depth;
    }
    if (ch === '-' && i + 1 < type.length && type[i + 1] === '>' && depth === 0) {
      parts.push(buf.trim());
      buf = '';
      ++i;
      continue;
    }
    buf += ch;
  }
  if (buf.trim() !== '') {
    parts.push(normalizeListSugar(buf.trim()));
  }

  return parts.map(normalizeListSugar);
}

function typeMatchScore(queryParts, sigParts) {
  if (queryParts.length === 0 || sigParts.length === 0) {
    return 0.0;
  }

  if (queryParts.length === sigParts.length && queryParts.length <= 4) {
    let best = 0.0;
    for (const perm of permute(queryParts)) {
      const mapping = {};
      let score = 0.0;
      for (let i = 0; i < perm.length; ++i) {
        score += typePartScore(perm[i], sigParts[i], mapping);
      }
      best = Math.max(best, score / queryParts.length);
    }

    return best;
  }

  const mapping = {};
  let score = 0.0;

  if (queryParts.length === 1 && sigParts.length === 1) {
    return typePartScore(queryParts[0], sigParts[0], mapping);
  }

  if (queryParts.length !== sigParts.length) {
    if (queryParts.length < sigParts.length) {
      const offset = sigParts.length - queryParts.length;
      const sigTail = sigParts.slice(offset);
      for (let i = 0; i < queryParts.length; ++i) {
        score += typePartScore(queryParts[i], sigTail[i], mapping);
      }

      return score / queryParts.length;
    }

    return 0.0;
  }

  for (let i = 0; i < queryParts.length; ++i) {
    score += typePartScore(queryParts[i], sigParts[i], mapping);
  }

  return score / queryParts.length;
}

function permute(items) {
  if (items.length <= 1) {
    return [items];
  }

  const result = [];
  for (let i = 0; i < items.length; ++i) {
    const rest = items.slice(0, i).concat(items.slice(i + 1));
    for (const perm of permute(rest)) {
      result.push([items[i], ...perm]);
    }
  }

  return result;
}

function typePartScore(query, sig, mapping) {
  query = query.trim();
  sig = sig.trim();

  if (query === sig) {
    return 100.0;
  }

  if (/^[a-z][a-z0-9]*$/.test(query)) {
    if (mapping[query] === undefined) {
      mapping[query] = sig;
      return 80.0;
    }

    return mapping[query] === sig ? 80.0 : 0.0;
  }

  if (/^[a-z][a-z0-9]*$/.test(sig)) {
    return 60.0;
  }

  if (query.toLowerCase() === sig.toLowerCase()) {
    return 70.0;
  }

  if (normalizeListSugar(query) === normalizeListSugar(sig)) {
    return 90.0;
  }

  return 0.0;
}

function normalizeListSugar(type) {
  type = type.trim();
  if (type === 'String') {
    return 'String';
  }
  let m = type.match(/^\[([^\]]+)\]$/);
  if (m) {
    return 'List ' + m[1];
  }
  if (type === '()') {
    return '()';
  }
  m = type.match(/^\(([^(),]+),\s*([^(),]+)\)$/);
  if (m) {
    return 'Tuple2 ' + m[1] + ' ' + m[2];
  }

  return type;
}

function search(entities, query, limit = 20) {
  query = query.trim();
  if (query === '') {
    return [];
  }

  let filters;
  [query, filters] = parseSearchFilters(query);

  if (query === '' && Object.keys(filters).length > 0) {
    return searchByFilters(entities, filters, limit);
  }

  if (query.includes('::')) {
    const parts = query.split('::', 2).map(s => s.trim());
    return applySearchFilters(searchByNameAndType(entities, parts[0], parts[1], limit), filters);
  }

  if (looksLikeTypeQuery(query)) {
    return applySearchFilters(searchByType(entities, query, limit), filters);
  }

  return applySearchFilters(searchByName(entities, query, limit), filters);
}

const Moogle = { search };
if (typeof module !== 'undefined') {
  module.exports = Moogle;
}
if (typeof globalThis !== 'undefined') {
  globalThis.Moogle = Moogle;
}
