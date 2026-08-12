import assert from 'node:assert/strict';
import { mkdtemp, readFile, readdir, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { JsonLineAuditSink } from '../src/audit.js';
import type { AuditRecord } from '../src/types.js';

test('JSONL audit serializes concurrent writes and bounds retained files', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'starfiniti-audit-'));
  const auditPath = path.join(directory, 'audit.jsonl');
  try {
    const sink = new JsonLineAuditSink(auditPath, 1024, 2);
    const records = Array.from({ length: 30 }, (_, index) => ({
      timestamp: new Date(1_700_000_000_000 + index).toISOString(), tenantId: 'tenant', siteId: 'site', callerIdentity: 'caller', clientIdentity: 'client', tool: 'status', scope: 'search.read' as const, argumentsHash: 'a'.repeat(64), safeArgumentSummary: { index }, operationId: null, resultCode: 'success', approvalIdentity: null, correlationId: `correlation-${index}`, durationMs: 1,
    }) satisfies AuditRecord);
    await Promise.all(records.map((record) => sink.append(record)));
    const files = (await readdir(directory)).filter((name) => name.startsWith('audit.jsonl'));
    assert.ok(files.length <= 3);
    const parsed: AuditRecord[] = [];
    for (const file of files) {
      assert.ok((await stat(path.join(directory, file))).size <= 1024);
      for (const line of (await readFile(path.join(directory, file), 'utf8')).trim().split('\n').filter(Boolean)) parsed.push(JSON.parse(line) as AuditRecord);
    }
    assert.ok(parsed.length > 0 && parsed.length < records.length);
    assert.equal(new Set(parsed.map((record) => record.correlationId)).size, parsed.length);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
