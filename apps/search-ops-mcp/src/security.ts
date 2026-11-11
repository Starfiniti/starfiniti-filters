import { timingSafeEqual } from 'node:crypto';
import { open } from 'node:fs/promises';
import {
  OAuthError,
  OAuthErrorCode,
  type AuthInfo,
  type OAuthTokenVerifier,
} from '@modelcontextprotocol/server';
import { createRemoteJWKSet, jwtVerify, type JWTVerifyGetKey } from 'jose';
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
  if (!/^[A-Za-z0-9_.:@/|-]{1,191}$/.test(text)) throw new AuthorizationError();
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

export interface RevocationStore {
  refresh(): Promise<void>;
  isRevoked(tokenId: string): boolean;
}

export class JsonFileRevocationStore implements RevocationStore {
  #fingerprint = '';
  #snapshotLoads = 0;
  #revoked = new Set<string>();

  public constructor(private readonly path: string) {
    if (!path || path.length > 2048) throw new Error('Revocation store path is invalid.');
  }

  public async refresh(): Promise<void> {
    const handle = await open(this.path, 'r');
    try {
      const metadata = await handle.stat({ bigint: true });
      if (!metadata.isFile() || metadata.size > BigInt(1024 * 1024)) throw new Error('Revocation store is invalid or too large.');
      const fingerprint = `${metadata.dev}:${metadata.ino}:${metadata.size}:${metadata.mtimeNs}:${metadata.ctimeNs}`;
      if (fingerprint === this.#fingerprint) return;
      const text = await handle.readFile('utf8');
      const parsed = JSON.parse(text.replace(/^\uFEFF/, '')) as unknown;
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Revocation store is invalid.');
      const ids = (parsed as Record<string, unknown>).revoked_token_ids;
      if (!Array.isArray(ids) || ids.length > 100000 || ids.some((id) => typeof id !== 'string' || !/^[A-Za-z0-9_.:@/-]{1,191}$/.test(id))) {
        throw new Error('Revocation store token IDs are invalid.');
      }
      this.#revoked = new Set(ids);
      this.#fingerprint = fingerprint;
      this.#snapshotLoads += 1;
    } finally {
      await handle.close();
    }
  }

  public isRevoked(tokenId: string): boolean {
    return this.#revoked.has(tokenId);
  }

  public get snapshotLoads(): number { return this.#snapshotLoads; }
}

export interface RemoteTokenPolicy {
  issuer: string;
  audience: string;
  jwksUrl: URL;
  tenantClaim: string;
  maximumLifetimeSeconds: number;
}

interface PrincipalAuthInfo extends Record<string, unknown> {
  starfiniti_principal: true;
  tenant_id: string;
  subject: string;
  issuer: string;
  audience: string;
  token_id: string;
}

export class RemoteJwtVerifier implements OAuthTokenVerifier {
  readonly #key: JWTVerifyGetKey;

  public constructor(
    private readonly policy: RemoteTokenPolicy,
    private readonly revocations: RevocationStore,
    key?: JWTVerifyGetKey
  ) {
    if (policy.maximumLifetimeSeconds < 60 || policy.maximumLifetimeSeconds > 900) throw new Error('Remote token lifetime policy is invalid.');
    this.#key = key ?? createRemoteJWKSet(policy.jwksUrl, { timeoutDuration: 5000, cooldownDuration: 30000, cacheMaxAge: 600000 });
  }

  public async verifyAccessToken(token: string): Promise<AuthInfo> {
    try {
      await this.revocations.refresh();
      const verified = await jwtVerify(token, this.#key, {
        algorithms: ['RS256'],
        issuer: this.policy.issuer,
        audience: this.policy.audience,
        clockTolerance: 30,
        maxTokenAge: this.policy.maximumLifetimeSeconds,
        requiredClaims: ['iss', 'aud', 'sub', 'client_id', 'iat', 'exp', 'jti'],
      });
      const typ = verified.protectedHeader.typ;
      if (typ !== 'at+jwt') throw new AuthorizationError();
      const claims = { ...verified.payload, tenant_id: verified.payload[this.policy.tenantClaim] };
      const principal = validateVerifiedClaims(claims, {
        issuer: this.policy.issuer,
        audience: this.policy.audience,
        maximumLifetimeSeconds: this.policy.maximumLifetimeSeconds,
        isRevoked: (tokenId) => this.revocations.isRevoked(tokenId),
      });
      const extra: PrincipalAuthInfo = {
        starfiniti_principal: true,
        tenant_id: principal.tenantId,
        subject: principal.subject,
        issuer: principal.issuer,
        audience: principal.audience,
        token_id: principal.tokenId,
      };
      return {
        token,
        clientId: principal.clientId,
        scopes: [...principal.scopes],
        expiresAt: principal.expiresAt,
        resource: new URL(principal.audience),
        extra,
      };
    } catch {
      throw new OAuthError(OAuthErrorCode.InvalidToken, 'The access token is invalid.');
    }
  }
}

export function principalFromAuthInfo(auth: AuthInfo | undefined): Principal {
  const extra = auth?.extra;
  if (!auth || !extra || extra.starfiniti_principal !== true || typeof auth.expiresAt !== 'number' || !(auth.resource instanceof URL)) throw new AuthorizationError();
  const tenantId = boundedIdentity(extra.tenant_id);
  const subject = boundedIdentity(extra.subject);
  const issuer = boundedIdentity(extra.issuer);
  const audience = String(extra.audience ?? '');
  const tokenId = boundedIdentity(extra.token_id);
  const clientId = boundedIdentity(auth.clientId);
  if (!same(auth.resource.toString(), audience) || auth.expiresAt <= Math.floor(Date.now() / 1000)) throw new AuthorizationError();
  const allowed = new Set<string>(scopes);
  const granted = new Set(auth.scopes.filter((scope): scope is Scope => allowed.has(scope)));
  if (granted.size === 0) throw new AuthorizationError();
  return { tenantId, subject, clientId, scopes: granted, issuer, audience, expiresAt: auth.expiresAt, tokenId, localProcessTrust: false };
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
