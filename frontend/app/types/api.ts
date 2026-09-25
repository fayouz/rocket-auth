export interface Tracked {
  createdAt: string
  updatedAt: string
  createdBy: string | null
  updatedBy: string | null
}

export interface UserSummary {
  id: string
  email: string
  firstName: string | null
  lastName: string | null
  displayName: string
}

export interface User extends UserSummary, Tracked {
  roles: string[]
  source: 'local' | 'ldap' | 'oidc'
  authenticationServerName: string | null
  ldapDn: string | null
  ldapSyncedAt: string | null
  enabled: boolean
}

export interface Me {
  user: User | null
  application: { id: string, name: string } | null
  roles: string[]
}

export interface Application extends Tracked {
  id: string
  name: string
  description: string | null
  tokenHint: string
  plainToken?: string
  canImpersonate: boolean
  enabled: boolean
  lastUsedAt: string | null
}

/** A "{{ name }}" placeholder of a template. */

/** unknown: network check not run yet (LDAP, OpenID Connect providers: every 5 minutes). */
export type ServiceStatus = 'operational' | 'degraded' | 'down' | 'disabled' | 'unknown'

/** Last result of a background network check. */
export interface ServiceCheckResult {
  status: 'operational' | 'down' | 'unknown'
  detail?: string
  latencyMs?: number | null
  checkedAt?: string
  lastOkAt?: string | null
  failingSince?: string | null
}

/** One element of a service checked item by item (e.g. each OpenID Connect provider). */
export interface ServiceItemHealth {
  id: string
  name: string
  status: 'operational' | 'down' | 'unknown'
  url?: string
  check?: ServiceCheckResult | null
}

export interface ServiceHealth {
  id: string
  label: string
  status: ServiceStatus
  detail: string
  latencyMs?: number
  queued?: number
  failedMessages?: number
  users?: number
  lastSyncAt?: string | null
  usagePercent?: number | null
  freeBytes?: number | null
  /** ldap: last network check. */
  check?: ServiceCheckResult | null
  /** sso and other per-item services */
  total?: number
  failing?: number
  items?: ServiceItemHealth[]
}

/** An event of the activity timeline; each module describes its own events. */
export interface DashboardActivity {
  type: string
  at: string
  title: string
  actor: string | null
  link: string | null
  icon: string
  label: string
  /** Tailwind classes of the icon badge. */
  color: string
}

export interface DashboardKpi {
  id: string
  label: string
  value: number | null
  format: 'number' | 'percent' | 'bytes'
  icon: string
  /** Tailwind classes of the icon badge. */
  tone: string
  detail?: string
  /** Value over the previous period, for the trend. */
  previous?: number
  /** Key of a daily series drawn as a sparkline. */
  series?: string
  /** 0-100: drawn as a progress bar. */
  progress?: number
  legend?: { label: string, color: string }[]
}

export interface DashboardSeries {
  key: string
  label: string
  /** Tailwind background class. */
  color: string
}

export interface DashboardRecentItem {
  id: string
  title: string
  subtitle: string
  at: string | null
  badge?: string
  badgeColor?: 'success' | 'error' | 'warning' | 'info' | 'neutral' | 'primary'
  link?: string
}

export interface DashboardApplication {
  id: string
  name: string
  enabled: boolean
  canImpersonate: boolean
  lastUsedAt: string | null
}

/** GET /api/dashboard: platform-wide for admins, the user's own activity otherwise. */
export interface Dashboard {
  scope: 'platform' | 'user'
  generatedAt: string
  days: number
  kpis: DashboardKpi[]
  series: DashboardSeries[]
  daily: ({ date: string } & Record<string, number | string>)[]
  recent: { title: string, link: string | null, empty: string, items: DashboardRecentItem[] } | null
  quickActions: { label: string, icon: string, to: string, tone: string }[]
  activity: DashboardActivity[]
  health: { status: Exclude<ServiceStatus, 'disabled' | 'unknown'>, services?: ServiceHealth[] }
  users?: { total: number, enabled: number, local: number, ldap: number, oidc: number }
  applications?: DashboardApplication[]
}

/** A JSON-LD collection page (Accept: application/ld+json), for paginated lists. */
export interface Collection<T> {
  member: T[]
  totalItems: number
}

export interface AuthenticationServer extends Tracked {
  id: string
  name: string
  type: 'ldap' | 'oidc'
  enabled: boolean
  /** LDAP: directory URL. OpenID Connect: issuer. */
  url: string
  /** OpenID Connect: URL used by the API to reach the issuer (empty: the issuer itself). */
  internalUrl: string
  clientId: string
  hasClientSecret: boolean
  scopes: string
  /** LDAP: administrators group DN. OpenID Connect: value of the "groups" claim granting the administrator role. */
  adminGroupDn: string
  linkExistingAccounts: boolean
}

/** POST /api/authentication_servers/oidc/test */
export interface OidcTestResult {
  ok: boolean
  message: string
  issuer?: string
  authorizationEndpoint?: string
  scopesSupported?: string[]
}

export interface AuthenticationServerDiscoveryCandidate {
  url: string
  reachable: boolean
  latencyMs: number | null
}

export interface LdapAttributes {
  email: string
  firstName: string
  lastName: string
  groups: string
}

/** GET /api/ldap/config: the directory settings (the bind password is never returned). */
export interface LdapConfig {
  enabled: boolean
  url: string
  startTls: boolean
  baseDn: string
  bindDn: string
  hasBindPassword: boolean
  userFilter: string
  adminGroupDn: string
  attributes: LdapAttributes
  /** "environment": the LDAP_* variables of the .env (nothing saved yet). */
  source: 'database' | 'environment'
  defaults: { attributes: LdapAttributes }
}

export interface LdapTestResult {
  ok: boolean
  message: string
  count: number
  sample: { dn: string, email: string, firstName: string | null, lastName: string | null, admin: boolean | null }[]
}

/** GET /api/system/version */
export interface AppVersionInfo {
  /** "0.7.0", "0.7.0+3 (abc1234)" (commits after a release), or "dev" / a branch name. */
  version: string
  release: string | null
}

export interface ReleaseInfo {
  version: string
  /** Git tag ("v0.7.0"). */
  tag: string
  name: string
  url: string
  publishedAt: string | null
  /** Release notes (Markdown). */
  notes: string | null
}

export type UpdateMethod = 'docker' | 'script' | 'manual'

export interface UpdateRun {
  id: string
  method: UpdateMethod
  /** requested: waiting for the scheduled task (script); started: sent to Watchtower (docker). */
  status: 'requested' | 'running' | 'started' | 'succeeded' | 'failed' | 'cancelled'
  fromVersion: string
  target: string | null
  log?: string
  requestedAt: string | null
  requestedBy: string | null
  startedAt: string | null
  finishedAt: string | null
}

/** GET /api/system/update (administrators) */
export interface UpdateStatus {
  current: AppVersionInfo & { raw: string }
  latest: ReleaseInfo | null
  /** null: this build has no version number to compare (development build, branch image). */
  updateAvailable: boolean | null
  checkEnabled: boolean
  repositoryUrl: string | null
  error: string | null
  method: UpdateMethod
  defaultMethod: UpdateMethod
  /** environment: no choice saved, UPDATE_METHOD (or automatic). */
  methodSource: 'database' | 'environment'
  methods: {
    docker: { configured: boolean }
    script: { script: string, installed: boolean, schedulerAlive: boolean, heartbeatAt: string | null }
  }
  run: UpdateRun | null
  history: UpdateRun[]
}
