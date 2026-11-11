import {
  createMcpHandler,
  getOAuthProtectedResourceMetadataUrl,
  hostHeaderValidationResponse,
  oauthMetadataResponse,
  originValidationResponse,
  requireBearerAuth,
  type McpHttpHandler,
  type OAuthMetadata,
  type OAuthTokenVerifier,
} from '@modelcontextprotocol/server';
import type { AuditSink } from './audit.js';
import type { SearchControlClient } from './control-client.js';
import { principalFromAuthInfo } from './security.js';
import { createSearchOpsServer } from './server.js';
import type { SiteRegistry } from './site-registry.js';
import { scopes } from './types.js';

export interface RemoteMcpConfiguration {
  resourceUrl: URL;
  allowedHostnames: string[];
  allowedOriginHostnames: string[];
  serviceDocumentationUrl?: URL;
  dangerouslyAllowInsecureIssuerUrl?: boolean;
}

export interface RemoteMcpDependencies {
  configuration: RemoteMcpConfiguration;
  oauthMetadata: OAuthMetadata;
  verifier: OAuthTokenVerifier;
  registry: SiteRegistry;
  control: SearchControlClient;
  audit: AuditSink;
  onerror?: (error: Error) => void;
}

export interface RemoteMcpApplication {
  fetch(request: Request): Promise<Response>;
  close(): Promise<void>;
}

function withSecurityHeaders(response: Response): Response {
  const headers = new Headers(response.headers);
  headers.set('Cache-Control', 'no-store');
  headers.set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
  headers.set('Referrer-Policy', 'no-referrer');
  headers.set('X-Content-Type-Options', 'nosniff');
  headers.set('X-Frame-Options', 'DENY');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

export function createRemoteMcpApplication(dependencies: RemoteMcpDependencies): RemoteMcpApplication {
  const { configuration } = dependencies;
  const metadataOptions = {
    oauthMetadata: dependencies.oauthMetadata,
    resourceServerUrl: configuration.resourceUrl,
    scopesSupported: [...scopes],
    resourceName: 'Starfiniti Search Operations',
    ...(configuration.serviceDocumentationUrl ? { serviceDocumentationUrl: configuration.serviceDocumentationUrl } : {}),
    ...(configuration.dangerouslyAllowInsecureIssuerUrl ? { dangerouslyAllowInsecureIssuerUrl: true } : {}),
  };
  const resourceMetadataUrl = getOAuthProtectedResourceMetadataUrl(configuration.resourceUrl);
  const requireAuth = requireBearerAuth({ verifier: dependencies.verifier, resourceMetadataUrl });
  const handler: McpHttpHandler = createMcpHandler(({ authInfo }) => createSearchOpsServer({
    principal: principalFromAuthInfo(authInfo),
    registry: dependencies.registry,
    control: dependencies.control,
    audit: dependencies.audit,
  }), {
    legacy: 'reject',
    ...(dependencies.onerror ? { onerror: dependencies.onerror } : {}),
  });

  return {
    async fetch(request: Request): Promise<Response> {
      const hostRejected = hostHeaderValidationResponse(request, configuration.allowedHostnames);
      if (hostRejected) return withSecurityHeaders(hostRejected);
      const originRejected = originValidationResponse(request, configuration.allowedOriginHostnames);
      if (originRejected) return withSecurityHeaders(originRejected);

      const metadata = oauthMetadataResponse(request, metadataOptions);
      if (metadata) return withSecurityHeaders(metadata);

      const url = new URL(request.url);
      if (url.pathname === '/.well-known/oauth-protected-resource') {
        const canonical = new URL(resourceMetadataUrl);
        const canonicalRequest = new Request(canonical, { method: request.method, headers: request.headers });
        const compatibleMetadata = oauthMetadataResponse(canonicalRequest, metadataOptions);
        return withSecurityHeaders(compatibleMetadata ?? new Response('Not found.', { status: 404 }));
      }
      if (url.pathname !== configuration.resourceUrl.pathname) return withSecurityHeaders(new Response('Not found.', { status: 404 }));

      const auth = await requireAuth(request);
      if (auth instanceof Response) return withSecurityHeaders(auth);
      return withSecurityHeaders(await handler.fetch(request, { authInfo: auth }));
    },
    close: () => handler.close(),
  };
}

function endpoint(value: unknown, label: string, allowInsecure: boolean): string {
  if (typeof value !== 'string' || value.length > 2048) throw new Error(`${label} is missing.`);
  const url = new URL(value);
  const local = ['127.0.0.1', 'localhost', '::1'].includes(url.hostname);
  if (url.username || url.password || url.hash || (url.protocol !== 'https:' && !(allowInsecure && local && url.protocol === 'http:'))) {
    throw new Error(`${label} violates transport policy.`);
  }
  return url.toString();
}

export async function fetchAuthorizationServerMetadata(issuer: URL, allowInsecure = false, fetcher: typeof fetch = fetch): Promise<OAuthMetadata> {
  const discovery = new URL('.well-known/openid-configuration', issuer);
  const response = await fetcher(discovery, {
    headers: { Accept: 'application/json' },
    redirect: 'error',
    signal: AbortSignal.timeout(5000),
  });
  if (!response.ok || !String(response.headers.get('content-type')).toLowerCase().includes('application/json')) throw new Error('Authorization server discovery failed.');
  const bytes = new Uint8Array(await response.arrayBuffer());
  if (bytes.byteLength > 256 * 1024) throw new Error('Authorization server metadata is too large.');
  const parsed = JSON.parse(new TextDecoder().decode(bytes)) as unknown;
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Authorization server metadata is invalid.');
  const metadata = parsed as Record<string, unknown>;
  if (endpoint(metadata.issuer, 'Authorization server issuer', allowInsecure) !== issuer.toString()) throw new Error('Authorization server issuer does not match configuration.');
  endpoint(metadata.authorization_endpoint, 'Authorization endpoint', allowInsecure);
  endpoint(metadata.token_endpoint, 'Token endpoint', allowInsecure);
  endpoint(metadata.jwks_uri, 'JWKS endpoint', allowInsecure);
  endpoint(metadata.registration_endpoint, 'Dynamic client registration endpoint', allowInsecure);
  const responseTypes = metadata.response_types_supported;
  const pkce = metadata.code_challenge_methods_supported;
  if (!Array.isArray(responseTypes) || !responseTypes.includes('code') || !Array.isArray(pkce) || !pkce.includes('S256')) {
    throw new Error('Authorization server does not advertise authorization code with PKCE S256.');
  }
  return metadata as OAuthMetadata;
}
