/** Sign-in through an OpenID Connect provider (authorization code flow with PKCE, completed by the API). */

export interface AuthProvider {
  id: string
  name: string
  authorizationEndpoint: string
  endSessionEndpoint: string | null
  clientId: string
  scope: string
}

interface PendingSignIn {
  provider: string
  state: string
  nonce: string
  codeVerifier: string
  redirect: string
}

const STORAGE_KEY = 'oidc_pending_sign_in'

function randomString(bytes = 32): string {
  return base64Url(crypto.getRandomValues(new Uint8Array(bytes)))
}

function base64Url(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function oidcRedirectUri(): string {
  return `${window.location.origin}/auth/callback`
}

/** Leaves for the provider's authorization page; the state, nonce and PKCE verifier stay in this tab. */
export async function startOidcSignIn(provider: AuthProvider, redirect = '/') {
  const pending: PendingSignIn = {
    provider: provider.id,
    state: randomString(),
    nonce: randomString(),
    codeVerifier: randomString(48),
    redirect,
  }
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(pending))
  const challenge = base64Url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(pending.codeVerifier))))

  const url = new URL(provider.authorizationEndpoint)
  url.search = new URLSearchParams({
    response_type: 'code',
    client_id: provider.clientId,
    redirect_uri: oidcRedirectUri(),
    scope: provider.scope,
    state: pending.state,
    nonce: pending.nonce,
    code_challenge: challenge,
    code_challenge_method: 'S256',
  }).toString()
  window.location.assign(url.toString())
}

/** The sign-in started in this tab matching the returned state (consumed: a code is used once). */
export function takePendingSignIn(state: string | null): PendingSignIn | null {
  const raw = sessionStorage.getItem(STORAGE_KEY)
  sessionStorage.removeItem(STORAGE_KEY)
  if (!raw || !state) return null
  try {
    const pending = JSON.parse(raw) as PendingSignIn
    return pending.state === state ? pending : null
  }
  catch {
    return null
  }
}
