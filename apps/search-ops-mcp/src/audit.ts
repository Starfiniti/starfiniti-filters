import { appendFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import type { AuditRecord } from './types.js';

function canonical(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (value && typeof value === 'object') {
    const object = value as Record<string, unknown>;
    return `{${Object.keys(object).sort().map((key) => `${JSON.stringify(key)}:${canonical(object[key])}`).join(',')}}`;
  }
  return JSON.stringify(value) ?? 'null';
}

export function argumentsHash(argumentsValue: unknown): string {
  return createHash('sha256').update(canonical(argumentsValue)).digest('hex');
}

export interface AuditSink {
  append(record: AuditRecord): Promise<void>;
  list(limit: number): Promise<AuditRecord[]>;
}

export class MemoryAuditSink implements AuditSink {
  readonly #records: AuditRecord[] = [];
  public constructor(private readonly maximum = 10000) {}

  public async append(record: AuditRecord): Promise<void> {
    this.#records.push(Object.freeze({ ...record, safeArgumentSummary: Object.freeze({ ...record.safeArgumentSummary }) }));
    if (this.#records.length > this.maximum) this.#records.splice(0, this.#records.length - this.maximum);
  }

  public async list(limit: number): Promise<AuditRecord[]> {
    return this.#records.slice(-Math.max(1, Math.min(100, limit))).map((record) => ({ ...record, safeArgumentSummary: { ...record.safeArgumentSummary } }));
  }
}

export class JsonLineAuditSink implements AuditSink {
  readonly #memory = new MemoryAuditSink();
  public constructor(private readonly path: string) {
    if (!path || path.length > 2048) throw new Error('Audit path is invalid.');
  }

  public async append(record: AuditRecord): Promise<void> {
    await appendFile(this.path, `${JSON.stringify(record)}\n`, { encoding: 'utf8', mode: 0o600, flag: 'a' });
    await this.#memory.append(record);
  }

  public list(limit: number): Promise<AuditRecord[]> {
    return this.#memory.list(limit);
  }
}

export function safeArgumentSummary(argumentsValue: Record<string, unknown>): Record<string, string | number | boolean | null> {
  const summary: Record<string, string | number | boolean | null> = {
    argument_count: Object.keys(argumentsValue).length,
    has_operation_id: typeof argumentsValue.operation_id === 'string',
    has_idempotency_key: typeof argumentsValue.idempotency_key === 'string',
    has_reason: typeof argumentsValue.reason === 'string',
  };
  if (typeof argumentsValue.tenant_id === 'string') summary.tenant_id = argumentsValue.tenant_id.slice(0, 128);
  if (typeof argumentsValue.site_id === 'string') summary.site_id = argumentsValue.site_id.slice(0, 128);
  return summary;
}
