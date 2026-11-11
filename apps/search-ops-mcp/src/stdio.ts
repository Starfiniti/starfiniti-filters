import { readFile } from 'node:fs/promises';
import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { JsonLineAuditSink } from './audit.js';
import { EnvironmentCredentialResolver, SearchControlClient } from './control-client.js';
import { localPrincipalFromEnvironment } from './security.js';
import { createSearchOpsServer } from './server.js';
import { SiteRegistry } from './site-registry.js';

async function main(): Promise<void> {
  const sitesFile = process.env.STARFINITI_MCP_SITES_FILE;
  const auditFile = process.env.STARFINITI_MCP_AUDIT_FILE;
  if (!sitesFile || !auditFile) throw new Error('Required local MCP file configuration is missing.');
  const registrations = JSON.parse((await readFile(sitesFile, 'utf8')).replace(/^\uFEFF/, '')) as unknown;
  const dependencies = {
    principal: localPrincipalFromEnvironment(process.env.STARFINITI_MCP_PRINCIPAL_JSON, process.env.STARFINITI_MCP_LOCAL_TRUST),
    registry: SiteRegistry.parse(registrations),
    control: new SearchControlClient(new EnvironmentCredentialResolver()),
    audit: new JsonLineAuditSink(auditFile),
  };
  serveStdio(() => createSearchOpsServer(dependencies));
}

main().catch(() => {
  process.stderr.write('Starfiniti Search Operations MCP failed to start safely.\n');
  process.exitCode = 1;
});
