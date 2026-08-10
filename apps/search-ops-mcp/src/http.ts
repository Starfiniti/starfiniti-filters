import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { once } from 'node:events';
import { readFile } from 'node:fs/promises';
import type { OAuthMetadata } from '@modelcontextprotocol/server';
import { JsonLineAuditSink } from './audit.js';
import { EnvironmentCredentialResolver, SearchControlClient } from './control-client.js';
import { createRemoteMcpApplication, fetchAuthorizationServerMetadata } from './remote.js';
import { JsonFileRevocationStore, RemoteJwtVerifier } from './security.js';
import { SiteRegistry } from './site-registry.js';

interface HttpConfiguration {
  bindAddress: string;
  port: number;
  publicUrl: URL;
  issuer: URL;
  tenantClaim: string;
  sitesFile: string;
  auditFile: string;
  revocationFile: string;
  allowedHostnames: string[];
  allowedOriginHostnames: string[];
  adminHostnames: string[];
  trustedProxyAddresses: Set<string>;
  maximumBodyBytes: number;
  maximumConcurrency: number;
  requestsPerMinute: number;
  allowInsecure: boolean;
}

class RequestControls {
  #inFlight = 0;
  readonly #requests = new Map<string, { window: number; count: number }>();

  public constructor(private readonly config: HttpConfiguration) {}

  public enter(client: string): (() => void) | undefined {
    const window = Math.floor(Date.now() / 60000);
    const current = this.#requests.get(client);
    const next = current?.window === window ? { window, count: current.count + 1 } : { window, count: 1 };
    this.#requests.set(client, next);
    if (this.#requests.size > 10000) {
      for (const [key, value] of this.#requests) if (value.window < window) this.#requests.delete(key);
    }
    if (next.count > this.config.requestsPerMinute || this.#inFlight >= this.config.maximumConcurrency) return undefined;
    this.#inFlight += 1;
    return () => { this.#inFlight -= 1; };
  }

  public get inFlight(): number { return this.#inFlight; }
}

class Metrics {
  requests = 0;
  rejected = 0;
  errors = 0;

  public render(inFlight: number): string {
    return [
      '# TYPE starfiniti_mcp_http_requests_total counter',
      `starfiniti_mcp_http_requests_total ${this.requests}`,
      '# TYPE starfiniti_mcp_http_rejected_total counter',
      `starfiniti_mcp_http_rejected_total ${this.rejected}`,
      '# TYPE starfiniti_mcp_http_errors_total counter',
      `starfiniti_mcp_http_errors_total ${this.errors}`,
      '# TYPE starfiniti_mcp_http_in_flight gauge',
      `starfiniti_mcp_http_in_flight ${inFlight}`,
      '',
    ].join('\n');
  }
}

function required(environment: NodeJS.ProcessEnv, name: string): string {
  const value = environment[name];
  if (!value) throw new Error(`Required configuration ${name} is missing.`);
  return value;
}

function integer(environment: NodeJS.ProcessEnv, name: string, fallback: number, minimum: number, maximum: number): number {
  const value = Number(environment[name] ?? fallback);
  if (!Number.isInteger(value) || value < minimum || value > maximum) throw new Error(`${name} is invalid.`);
  return value;
}

function list(value: string | undefined): string[] {
  return [...new Set((value ?? '').split(',').map((item) => item.trim()).filter(Boolean))];
}

function loadConfiguration(environment: NodeJS.ProcessEnv): HttpConfiguration {
  const allowInsecure = environment.STARFINITI_MCP_DANGEROUSLY_ALLOW_INSECURE_HTTP === '1';
  const publicUrl = new URL(required(environment, 'STARFINITI_MCP_PUBLIC_URL'));
  const issuer = new URL(required(environment, 'STARFINITI_MCP_AUTH_ISSUER'));
  const localPublic = ['127.0.0.1', 'localhost', '::1'].includes(publicUrl.hostname);
  const localIssuer = ['127.0.0.1', 'localhost', '::1'].includes(issuer.hostname);
  if (publicUrl.username || publicUrl.password || publicUrl.search || publicUrl.hash || publicUrl.pathname !== '/mcp' ||
      (publicUrl.protocol !== 'https:' && !(allowInsecure && localPublic && publicUrl.protocol === 'http:')) ||
      (issuer.protocol !== 'https:' && !(allowInsecure && localIssuer && issuer.protocol === 'http:'))) {
    throw new Error('Public MCP or authorization issuer URL violates transport policy.');
  }
  if (!issuer.pathname.endsWith('/')) issuer.pathname = `${issuer.pathname}/`;
  const allowedHostnames = list(environment.STARFINITI_MCP_ALLOWED_HOSTS);
  if (!allowedHostnames.includes(publicUrl.hostname)) allowedHostnames.push(publicUrl.hostname);
  const allowedOriginHostnames = list(environment.STARFINITI_MCP_ALLOWED_ORIGINS);
  if (!allowedOriginHostnames.includes(publicUrl.hostname)) allowedOriginHostnames.push(publicUrl.hostname);
  return {
    bindAddress: environment.STARFINITI_MCP_BIND_ADDRESS ?? '127.0.0.1',
    port: integer(environment, 'STARFINITI_MCP_PORT', 3100, 1, 65535),
    publicUrl,
    issuer,
    tenantClaim: environment.STARFINITI_MCP_TENANT_CLAIM ?? 'https://starfiniti.com/tenant_id',
    sitesFile: required(environment, 'STARFINITI_MCP_SITES_FILE'),
    auditFile: required(environment, 'STARFINITI_MCP_AUDIT_FILE'),
    revocationFile: required(environment, 'STARFINITI_MCP_REVOCATION_FILE'),
    allowedHostnames,
    allowedOriginHostnames,
    adminHostnames: list(required(environment, 'STARFINITI_MCP_ADMIN_HOSTS')),
    trustedProxyAddresses: new Set(list(environment.STARFINITI_MCP_TRUSTED_PROXY_ADDRESSES)),
    maximumBodyBytes: integer(environment, 'STARFINITI_MCP_MAX_BODY_BYTES', 262144, 1024, 1048576),
    maximumConcurrency: integer(environment, 'STARFINITI_MCP_MAX_CONCURRENCY', 32, 1, 256),
    requestsPerMinute: integer(environment, 'STARFINITI_MCP_REQUESTS_PER_MINUTE', 60, 1, 10000),
    allowInsecure,
  };
}

function hostname(request: IncomingMessage): string {
  const host = request.headers.host ?? '';
  try { return new URL(`http://${host}`).hostname; } catch { return ''; }
}

function clientAddress(request: IncomingMessage, config: HttpConfiguration): string {
  const remote = request.socket.remoteAddress ?? 'unknown';
  if (!config.trustedProxyAddresses.has(remote)) return remote;
  const forwarded = request.headers['x-forwarded-for'];
  const first = (Array.isArray(forwarded) ? forwarded[0] : forwarded)?.split(',')[0]?.trim();
  return first && /^[A-Fa-f0-9:.]{2,64}$/.test(first) ? first : remote;
}

async function readJson(request: IncomingMessage, maximumBytes: number): Promise<unknown> {
  const declared = Number(request.headers['content-length'] ?? 0);
  if (Number.isFinite(declared) && declared > maximumBytes) throw new Error('request_too_large');
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const raw of request) {
    const chunk = Buffer.isBuffer(raw) ? raw : Buffer.from(raw as Uint8Array);
    size += chunk.length;
    if (size > maximumBytes) throw new Error('request_too_large');
    chunks.push(chunk);
  }
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new Error('invalid_json'); }
}

async function writeResponse(response: Response, output: ServerResponse): Promise<void> {
  const headers: Record<string, string> = {};
  response.headers.forEach((value, key) => { headers[key] = value; });
  output.writeHead(response.status, headers);
  if (!response.body) {
    output.end();
    return;
  }
  const reader = response.body.getReader();
  const cancel = (): void => { void reader.cancel(); };
  output.once('close', cancel);
  try {
    while (!output.destroyed) {
      const { done, value } = await reader.read();
      if (done) break;
      if (!output.write(value)) await once(output, 'drain');
    }
  } finally {
    output.off('close', cancel);
    reader.releaseLock();
  }
  output.end();
}

function toWebRequest(request: IncomingMessage, parsedBody: unknown): Request {
  const headers = new Headers();
  for (const [name, raw] of Object.entries(request.headers)) {
    if (Array.isArray(raw)) for (const value of raw) headers.append(name, value);
    else if (raw !== undefined) headers.set(name, raw);
  }
  const method = request.method ?? 'GET';
  const url = new URL(request.url ?? '/', `http://${request.headers.host ?? 'invalid'}`);
  return new Request(url, {
    method,
    headers,
    ...(method === 'POST' ? { body: JSON.stringify(parsedBody) } : {}),
  });
}

async function main(): Promise<void> {
  const config = loadConfiguration(process.env);
  const registrations = JSON.parse((await readFile(config.sitesFile, 'utf8')).replace(/^\uFEFF/, '')) as unknown;
  const registry = SiteRegistry.parse(registrations);
  const revocations = new JsonFileRevocationStore(config.revocationFile);
  await revocations.refresh();
  const oauthMetadata: OAuthMetadata = await fetchAuthorizationServerMetadata(config.issuer, config.allowInsecure);
  const verifier = new RemoteJwtVerifier({
    issuer: config.issuer.toString(),
    audience: config.publicUrl.toString(),
    jwksUrl: new URL(String(oauthMetadata.jwks_uri)),
    tenantClaim: config.tenantClaim,
    maximumLifetimeSeconds: 900,
  }, revocations);
  const metrics = new Metrics();
  const controls = new RequestControls(config);
  const application = createRemoteMcpApplication({
    configuration: {
      resourceUrl: config.publicUrl,
      allowedHostnames: config.allowedHostnames,
      allowedOriginHostnames: config.allowedOriginHostnames,
      ...(config.allowInsecure ? { dangerouslyAllowInsecureIssuerUrl: true } : {}),
    },
    oauthMetadata,
    verifier,
    registry,
    control: new SearchControlClient(new EnvironmentCredentialResolver()),
    audit: new JsonLineAuditSink(config.auditFile),
    onerror: () => { metrics.errors += 1; },
  });

  const server = createServer(async (request, response) => {
    try {
      const url = new URL(request.url ?? '/', `http://${request.headers.host ?? 'invalid'}`);
      if (['/livez', '/readyz', '/metrics'].includes(url.pathname)) {
        if (!config.adminHostnames.includes(hostname(request))) {
          response.writeHead(404).end('Not found.');
          return;
        }
        if (request.method !== 'GET') {
          response.writeHead(405, { Allow: 'GET', 'Cache-Control': 'no-store' }).end('Method not allowed.');
          return;
        }
        if (url.pathname === '/metrics') {
          response.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4', 'Cache-Control': 'no-store' }).end(metrics.render(controls.inFlight));
        } else {
          response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }).end(JSON.stringify({ status: 'ok' }));
        }
        return;
      }

      metrics.requests += 1;
      const leave = controls.enter(clientAddress(request, config));
      if (!leave) {
        metrics.rejected += 1;
        response.writeHead(429, { 'Retry-After': '60', 'Cache-Control': 'no-store' }).end('Request limit exceeded.');
        return;
      }
      try {
        let parsedBody: unknown;
        if (request.method === 'POST') {
          const contentType = String(request.headers['content-type'] ?? '').split(';', 1)[0]?.trim().toLowerCase();
          if (contentType !== 'application/json') {
            response.writeHead(415, { 'Cache-Control': 'no-store' }).end('Unsupported media type.');
            return;
          }
          parsedBody = await readJson(request, config.maximumBodyBytes);
        }
        const webRequest = toWebRequest(request, parsedBody);
        await writeResponse(await application.fetch(webRequest), response);
      } finally {
        leave();
      }
    } catch (error) {
      metrics.errors += 1;
      const code = error instanceof Error ? error.message : '';
      const status = code === 'request_too_large' ? 413 : (code === 'invalid_json' ? 400 : 500);
      if (!response.headersSent) response.writeHead(status, { 'Cache-Control': 'no-store' }).end(status === 500 ? 'Internal server error.' : 'Invalid request.');
      else response.end();
    }
  });

  const close = async (): Promise<void> => {
    server.close();
    await application.close();
  };
  process.once('SIGINT', () => { void close(); });
  process.once('SIGTERM', () => { void close(); });
  server.listen(config.port, config.bindAddress, () => {
    process.stderr.write(`Starfiniti Search Operations MCP listening on ${config.bindAddress}:${config.port}.\n`);
  });
}

main().catch(() => {
  process.stderr.write('Starfiniti Search Operations HTTP MCP failed to start safely.\n');
  process.exitCode = 1;
});
