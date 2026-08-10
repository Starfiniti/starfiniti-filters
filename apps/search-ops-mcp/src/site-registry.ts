import type { ControlOperation, Environment, SiteRegistration } from './types.js';

const operationSet = new Set<ControlOperation>(['status', 'capabilities', 'configuration', 'operations', 'operation', 'audit', 'plan']);

function identifier(value: unknown): string {
  if (typeof value !== 'string' || !/^[A-Za-z0-9_.:-]{1,128}$/.test(value)) throw new Error('Invalid site registration identity.');
  return value;
}

function controlBase(value: unknown, environment: Environment): string {
  if (typeof value !== 'string' || value.length > 2048) throw new Error('Invalid control API base.');
  const url = new URL(value);
  const local = ['127.0.0.1', 'localhost', '::1'].includes(url.hostname);
  if ((url.protocol !== 'https:' && !(environment === 'development' && local && url.protocol === 'http:')) || url.username || url.password || url.search || url.hash) {
    throw new Error('Control API base violates transport policy.');
  }
  const pathname = url.pathname.replace(/\/$/, '');
  if (!pathname.endsWith('/wp-json/starfiniti-search/v1')) throw new Error('Control API base is not a Starfiniti versioned endpoint.');
  url.pathname = pathname;
  return url.toString().replace(/\/$/, '');
}

export class SiteRegistry {
  readonly #sites = new Map<string, SiteRegistration>();

  public constructor(registrations: readonly SiteRegistration[]) {
    for (const registration of registrations) {
      const key = this.key(registration.tenantId, registration.siteId);
      if (this.#sites.has(key)) throw new Error('Duplicate site registration.');
      this.#sites.set(key, Object.freeze({ ...registration, allowedOperations: Object.freeze([...registration.allowedOperations]) }));
    }
  }

  public static parse(input: unknown): SiteRegistry {
    if (!Array.isArray(input) || input.length > 1000) throw new Error('Site registration inventory is invalid.');
    return new SiteRegistry(input.map((candidate) => {
      if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) throw new Error('Site registration is invalid.');
      const row = candidate as Record<string, unknown>;
      const environment = row.environment as Environment;
      if (!['development', 'staging', 'production'].includes(environment)) throw new Error('Site environment is invalid.');
      const allowedOperations = Array.isArray(row.allowed_operations) ? row.allowed_operations : [];
      if (allowedOperations.length === 0 || allowedOperations.some((operation) => typeof operation !== 'string' || !operationSet.has(operation as ControlOperation))) {
        throw new Error('Site allowed operations are invalid.');
      }
      const credentialReference = String(row.credential_reference ?? '');
      if (!/^env:STARFINITI_[A-Z0-9_]{3,120}$/.test(credentialReference)) throw new Error('Site credential reference is invalid.');
      const authType = row.auth_type;
      if (authType !== 'application_password' && authType !== 'bearer') throw new Error('Site authentication type is invalid.');
      if (row.control_contract_version !== '1.0' || (row.status !== 'active' && row.status !== 'disabled')) throw new Error('Site contract or status is invalid.');
      return {
        tenantId: identifier(row.tenant_id),
        siteId: identifier(row.site_id),
        displayName: String(row.display_name ?? '').slice(0, 191),
        environment,
        controlApiBase: controlBase(row.control_api_base, environment),
        credentialReference,
        authType,
        allowedOperations: [...new Set(allowedOperations as ControlOperation[])],
        installationUuid: identifier(row.installation_uuid),
        controlContractVersion: '1.0',
        status: row.status,
      } satisfies SiteRegistration;
    }));
  }

  public get(tenantId: string, siteId: string): SiteRegistration {
    const site = this.#sites.get(this.key(tenantId, siteId));
    if (!site) throw new Error('Registered site was not found.');
    return site;
  }

  public listForTenant(tenantId: string): SiteRegistration[] {
    return [...this.#sites.values()].filter((site) => site.tenantId === tenantId).map((site) => ({ ...site, credentialReference: '[redacted]' }));
  }

  private key(tenantId: string, siteId: string): string {
    return `${tenantId}\u0000${siteId}`;
  }
}
