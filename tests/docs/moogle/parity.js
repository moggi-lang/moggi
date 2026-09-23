#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const jsPath = process.argv[2];
const rowsPath = process.argv[3];
const queriesPath = process.argv[4];

if (!jsPath || !rowsPath || !queriesPath) {
  process.stderr.write('usage: moogle-parity.js <moogle.js> <rows.json> <queries.json>\n');
  process.exit(2);
}

const Moogle = require(path.resolve(jsPath));
const rows = JSON.parse(fs.readFileSync(rowsPath, 'utf8'));
const queries = JSON.parse(fs.readFileSync(queriesPath, 'utf8'));

let failures = 0;

for (const case_ of queries) {
  const query = case_.query;
  const limit = case_.limit ?? 5;
  const hits = Moogle.search(rows, query, limit);
  const actual = hits.map(h => ({
    name: h.entity.name,
    score: h.score,
  }));
  const expected = case_.expected;

  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    ++failures;
    process.stderr.write(
      `FAIL ${query}\n  expected: ${JSON.stringify(expected)}\n  actual:   ${JSON.stringify(actual)}\n`,
    );
  }
}

process.exit(failures === 0 ? 0 : 1);
