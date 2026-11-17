/**
 * Accuracy regression tests (reviewer "accuracy", docs/review-accuracy.md): realistic uploads from accuracy-cases.js,
 * run through the full chain the UI uses (accuracy-pipeline.js), checked against the craft rules of research 04.
 * Inspect one case by hand: node tests/analysis/accuracy-pipeline.js <caseId>
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { CASES } from './accuracy-cases.js';
import { runCase, digest, check } from './accuracy-pipeline.js';

for (const c of CASES) {
  // knownGap: a documented limitation (review-accuracy.md). Reported as TODO, does not fail the suite.
  test(`accuracy: ${c.id}`, c.knownGap ? { todo: c.knownGap } : {}, async () => {
    const d = digest(await runCase(c));
    const broken = check(c.expect, d);
    assert.deepEqual(broken, [], `${c.note}\n${JSON.stringify(d)}`);
  });
}
