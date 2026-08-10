import type { ControlOperation, SiteRegistration } from './types.js';

const paths: Record<Exclude<ControlOperation, 'operation'>, { method: 'GET' | 'POST'; path: string }> = {
  status: { method: 'GET', path: '/control/status' },
  capabilities: { method: 'GET', path: '/control/capabilities' },
  configuration: { method: 'GET', path: '/control/configuration' },
  operations: { method: 'GET', path: '/control/operations?limit=20' },
  audit: { method: 'GET', path: '/control/audit?limit=50' },
  plan: { method: 'POST', path: '/control/operations/plan' },
};

export interface CredentialResolver {
  resolve(reference: string): string;
}

export class EnvironmentCredentialResolver implements CredentialResolver {
  public resolve(reference: string): string {
    const match = /^env:(STARFINITI_[A-Z0-9_]{3,120})$/.exec(reference);
    if (!match) throw new Error('Credential reference is invalid.');
    const value = process.env[match[1] as string];
    if (!value || value.length > 4096) throw new Error('Credential is unavailable.');
    return value;
  }
}

export class ControlApiError extends Error {
  public constructor(public readonly code: string, public readonly retryable: boolean) {
    super('WordPress control API request failed.');
    this.name = 'ControlApiError';
  }
}

export class SearchControlClient {
  public constructor(
    private readonly credentials: CredentialResolver,
    private readonly fetcher: typeof fetch = fetch,
    private readonly timeoutMs = 5000
  ) {
    if (timeoutMs < 100 || timeoutMs > 30000) throw new Error('Control API timeout is invalid.');
  }

  public async call(site: SiteRegistration, operation: ControlOperation, body?: Record<string, unknown>, operationId?: string): Promise<Record<string, unknown>> {
    const route = operation === 'operation'
      ? { method: 'GET' as const, path: `/control/operations/${this.operationId(operationId)}` }
      : paths[operation];
    const encoded = body === undefined ? undefined : JSON.stringify(body);
    if (encoded && Buffer.byteLength(encoded) > 131072) throw new Error('Control API request body is too large.');
    const credential = this.credentials.resolve(site.credentialReference);
    const authorization = site.authType === 'application_password'
      ? `Basic ${Buffer.from(credential, 'utf8').toString('base64')}`
      : `Bearer ${credential}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const response = await this.fetcher(`${site.controlApiBase}${route.path}`, {
        method: route.method,
        headers: {
          Accept: 'application/json',
          Authorization: authorization,
          ...(encoded ? { 'Content-Type': 'application/json' } : {}),
        },
        ...(encoded ? { body: encoded } : {}),
        redirect: 'error',
        signal: controller.signal,
      });
      const declared = Number(response.headers.get('content-length') ?? '0');
      if (declared > 2 * 1024 * 1024) throw new ControlApiError('response_too_large', false);
      const text = await response.text();
      if (Buffer.byteLength(text) > 2 * 1024 * 1024) throw new ControlApiError('response_too_large', false);
      if (!response.ok) throw new ControlApiError(`http_${response.status}`, [429, 502, 503, 504].includes(response.status));
      const parsed = JSON.parse(text) as unknown;
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || (parsed as Record<string, unknown>).contract_version !== '1.0') {
        throw new ControlApiError('invalid_contract', false);
      }
      return parsed as Record<string, unknown>;
    } catch (error) {
      if (error instanceof ControlApiError) throw error;
      throw new ControlApiError('transport_failure', true);
    } finally {
      clearTimeout(timer);
    }
  }

  private operationId(value: string | undefined): string {
    if (!value || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)) {
      throw new Error('Operation ID is invalid.');
    }
    return value.toLowerCase();
  }
}
