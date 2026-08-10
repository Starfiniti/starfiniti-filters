export const scopes = [
  'search.read',
  'search.diagnostics.read',
  'search.analytics.read',
  'search.config.read',
  'search.config.write',
  'search.index.plan',
  'search.index.execute',
  'search.index.activate',
  'search.index.rollback',
  'search.secrets.rotate',
  'search.audit.read',
] as const;

export type Scope = (typeof scopes)[number];
export type Environment = 'development' | 'staging' | 'production';
export type ControlOperation =
  | 'status'
  | 'capabilities'
  | 'configuration'
  | 'operations'
  | 'operation'
  | 'audit'
  | 'plan';

export interface Principal {
  tenantId: string;
  subject: string;
  clientId: string;
  scopes: ReadonlySet<Scope>;
  issuer: string;
  audience: string;
  expiresAt: number;
  tokenId: string;
  localProcessTrust: boolean;
}

export interface SiteRegistration {
  tenantId: string;
  siteId: string;
  displayName: string;
  environment: Environment;
  controlApiBase: string;
  credentialReference: string;
  authType: 'application_password' | 'bearer';
  allowedOperations: readonly ControlOperation[];
  installationUuid: string;
  controlContractVersion: '1.0';
  status: 'active' | 'disabled';
}

export interface AuditRecord {
  timestamp: string;
  tenantId: string;
  siteId: string;
  callerIdentity: string;
  clientIdentity: string;
  tool: string;
  scope: Scope;
  argumentsHash: string;
  safeArgumentSummary: Record<string, string | number | boolean | null>;
  operationId: string | null;
  resultCode: string;
  approvalIdentity: string | null;
  correlationId: string;
  durationMs: number;
}
