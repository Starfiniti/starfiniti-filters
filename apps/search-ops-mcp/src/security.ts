import { timingSafeEqual } from 'node:crypto';
import type { ControlOperation, Principal, Scope, SiteRegistration } from './types.js';
import { scopes } from './types.js';

export class AuthorizationError extends Error {
  public constructor(message = 'Authorization failed.') {
    super(message);
    this.name = 'AuthorizationError';
  }
}

function boundedIdentity(value: unknown): string {
  const text = typeof value === 'string' ? value : '';
  if (!/^[A-Za-z0-9_.:@/-]{1,191}$/.test(text)) throw new AuthorizationError();
  return text;
}

function same(left: string, right: string): boolean {
  const a = Buffer.from(left);
  const b = Buffer.from(right);
  return a.length === b.length && timingSafeEqual(a, b);
}

export function authorize(principal: Principal, site: SiteRegistration, required: Scope, operation: ControlOperation): void {
  if (site.status !== 'active' || !same(principal.tenantId, site.tenantId) || !principal.scopes.has(required) || !site.allowedOperations.includes(operation)) {
    throw new AuthorizationError();
  }
  if (principal.expiresAt <= Math.floor(Date.now() / 1000)) throw new AuthorizationError('Authorization expired.');
}

export interface TokenValidationPolicy {
  issuer: string;
  audience: string;
  maximumLifetimeSeconds: number;
  isRevoked(tokenId: string): boolean;
}

/** Validates claims after cryptographic JWT verification by the remote transport middleware. */
export function validateVerifiedClaims(claims: Record<string, unknown>, policy: TokenValidationPolicy, now = Math.floor(Date.now() / 1000)): Principal {
  const issuer = boundedIdentity(claims.iss);
  const audienceClaim = claims.aud;
  const audiences = typeof audienceClaim === 'string' ? [audienceClaim] : (Array.isArray(audienceClaim) ? audienceClaim.filter((value): value is string => typeof value === 'string') : []);
  const subject = boundedIdentity(claims.sub);
  const clientId = boundedIdentity(claims.client_id ?? claims.azp);
  const tenantId = boundedIdentity(claims.tenant_id);
  const tokenId = boundedIdentity(claims.jti);
  const expiresAt = Number(claims.exp);
  const issuedAt = Number(claims.iat);
  const notBefore = claims.nbf === undefined ? issuedAt : Number(claims.nbf);
  if (!same(issuer, policy.issuer) || !audiences.some((value) => same(value, policy.audience)) || !Number.isInteger(expiresAt) || !Number.isInteger(issuedAt) || !Number.isInteger(notBefore)) {
    throw new AuthorizationError();
  }
  if (notBefore > now + 30 || expiresAt <= now - 30 || expiresAt - issuedAt > policy.maximumLifetimeSeconds || issuedAt > now + 30 || policy.isRevoked(tokenId)) {
    throw new AuthorizationError();
  }
  const scopeClaim = Array.isArray(claims.scope) ? claims.scope : (typeof claims.scope === 'string' ? claims.scope.split(/\s+/) : []);
  const allowed = new Set<string>(scopes);
  const granted = new Set(scopeClaim.filter((value): value is Scope => typeof value === 'string' && allowed.has(value)));
  if (granted.size === 0) throw new AuthorizationError();
  return { tenantId, subject, clientId, scopes: granted, issuer, audience: policy.audience, expiresAt, tokenId, localProcessTrust: false };
}

export function localPrincipalFromEnvironment(value: string | undefined, localTrust: string | undefined): Principal {
  if (localTrust !== '1' || !value) throw new AuthorizationError('Local stdio trust is not enabled.');
  const parsed = JSON.parse(value) as Record<string, unknown>;
  const tenantId = boundedIdentity(parsed.tenant_id);
  const subject = boundedIdentity(parsed.subject);
  const clientId = boundedIdentity(parsed.client_id);
  const allowed = new Set<string>(scopes);
  const granted = new Set((Array.isArray(parsed.scopes) ? parsed.scopes : []).filter((scope): scope is Scope => typeof scope === 'string' && allowed.has(scope)));
  if (granted.size === 0) throw new AuthorizationError();
  return {
    tenantId,
    subject,
    clientId,
    scopes: granted,
    issuer: 'local-process-trust',
    audience: 'stdio',
    expiresAt: Math.floor(Date.now() / 1000) + 3600,
    tokenId: `local-${process.pid}`,
    localProcessTrust: true,
  };
}
