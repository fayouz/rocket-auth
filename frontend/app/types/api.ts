// Types of the Rocket core (users, applications, dashboard…), then those of this application.
import type { Tracked } from '#rocket/types/api'

export type * from '#rocket/types/api'

export type OAuthScope = 'openid' | 'profile' | 'email' | 'groups' | 'offline_access'
export type OAuthGrant = 'authorization_code' | 'refresh_token' | 'client_credentials'

/** An application signing its users in with Rocket Auth (/api/oauth/clients). */
export interface OAuthClient extends Tracked {
  id: string
  name: string
  description: string | null
  clientId: string
  /** Shown in the application switcher of the suite. */
  homeUrl: string | null
  icon: string | null
  secretHint: string | null
  /** Only in the creation response. */
  plainSecret?: string
  confidential: boolean
  redirectUris: string[]
  postLogoutRedirectUris: string[]
  /** OpenID Connect Back-Channel Logout: where the application receives the logout tokens. */
  backchannelLogoutUri: string | null
  allowedScopes: OAuthScope[]
  grantTypes: OAuthGrant[]
  trusted: boolean
  enabled: boolean
  lastUsedAt: string | null
}

export interface Consent {
  id: string
  clientName: string
  clientDescription: string | null
  scopes: OAuthScope[]
  grantedAt: string
  lastUsedAt: string | null
}

/** GET /api/oauth/authorize: what the application asks for. */
export interface AuthorizationCheck {
  client: { id: string, name: string, description: string | null, trusted: boolean, redirectHost: string }
  scopes: { name: OAuthScope, description: string }[]
  consentRequired: boolean
}
