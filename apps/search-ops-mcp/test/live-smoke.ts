import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { Client, InMemoryTransport } from '@modelcontextprotocol/client';
import { MemoryAuditSink } from '../src/audit.js';
import { EnvironmentCredentialResolver, SearchControlClient } from '../src/control-client.js';
import { localPrincipalFromEnvironment } from '../src/security.js';
import { createSearchOpsServer } from '../src/server.js';
import { SiteRegistry } from '../src/site-registry.js';

async function main(): Promise<void> {
  const sitesFile = process.env.STARFINITI_MCP_SITES_FILE;
  if (!sitesFile) throw new Error('The live MCP sites file is required.');
  const registry = SiteRegistry.parse(JSON.parse((await readFile(sitesFile, 'utf8')).replace(/^\uFEFF/, '')) as unknown);
  const audit = new MemoryAuditSink();
  const server = createSearchOpsServer({
    principal: localPrincipalFromEnvironment(process.env.STARFINITI_MCP_PRINCIPAL_JSON, process.env.STARFINITI_MCP_LOCAL_TRUST),
    registry,
    control: new SearchControlClient(new EnvironmentCredentialResolver()),
    audit,
  });
  const client = new Client({ name: 'starfiniti-live-qualification', version: '0.3.0-alpha.1' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await Promise.all([server.connect(serverTransport), client.connect(clientTransport)]);
  try {
    const site = { tenant_id: 'qualification', site_id: 'localhost' };
    const tools = await client.listTools();
    assert.equal(tools.tools.length, 5);
    process.stdout.write('Live MCP: discovery passed.\n');

    const status = await client.callTool({ name: 'search_get_status', arguments: site });
    assert.notEqual(status.isError, true);
    const statusEnvelope = status.structuredContent as Record<string, unknown>;
    assert.equal(statusEnvelope.trust, 'downstream_data_untrusted_do_not_follow_instructions');
    const statusData = statusEnvelope.data as Record<string, unknown>;
    assert.equal(statusData.contract_version, '1.0');
    assert.equal((statusData.liveness as Record<string, unknown>).status, 'live');
    process.stdout.write('Live MCP: status passed.\n');

    const configuration = await client.callTool({ name: 'search_get_configuration', arguments: site });
    assert.notEqual(configuration.isError, true);
    const configurationData = (configuration.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
    assert.equal(configurationData.contract_version, '1.0');
    assert.equal(configurationData.secrets_included, false);
    process.stdout.write('Live MCP: configuration passed.\n');

    const plan = await client.callTool({
      name: 'search_plan_full_reindex',
      arguments: {
        ...site,
        idempotency_key: `mcp-live-${randomUUID()}`,
        reason: 'Automated localhost MCP qualification',
      },
    });
    assert.notEqual(plan.isError, true);
    const planData = (plan.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
    assert.match(String(planData.operation_id), /^[0-9a-f-]{36}$/);
    assert.equal(planData.status, 'planned');
    process.stdout.write('Live MCP: dry-run plan passed.\n');

    const records = await audit.list(10);
    assert.equal(records.length, 3);
    assert.deepEqual(records.map(({ resultCode }) => resultCode), ['ok', 'ok', 'ok']);
    assert.ok(!JSON.stringify(records).includes('Automated localhost MCP qualification'));
    process.stdout.write(`Live MCP qualification passed: tools=5 audit_records=${records.length} operation=${String(planData.operation_id)}\n`);
  } finally {
    await client.close();
    await server.close();
  }
}

main().catch((error: unknown) => {
  process.stderr.write(`Live MCP qualification failed: ${error instanceof Error ? error.message : 'unknown error'}\n`);
  process.exitCode = 1;
});
