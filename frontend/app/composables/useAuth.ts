import type { Me } from '~/types/api'

const TOKEN_COOKIE = 'ra_token'
const TOKEN_TTL_SECONDS = 3600

export function useAuth() {
  const config = useRuntimeConfig()
  const token = useState<string | null>('ra_token', () => useCookie<string | null>(TOKEN_COOKIE).value ?? null)

  // Written synchronously: a useCookie ref only persists through a watcher, which is lost
  // when the component that set it unmounts right away (e.g. navigating after login).
  function setToken(value: string | null) {
    token.value = value
    const secure = window.location.protocol === 'https:' ? '; Secure' : ''
    document.cookie = value
      ? `${TOKEN_COOKIE}=${encodeURIComponent(value)}; Path=/; Max-Age=${TOKEN_TTL_SECONDS}; SameSite=Strict${secure}`
      : `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Strict${secure}`
  }
  const me = useState<Me | null>('ra_me', () => null)

  const isAuthenticated = computed(() => !!token.value)
  const isAdmin = computed(() => me.value?.roles.includes('ROLE_ADMIN') ?? false)

  function authorizationHeader(): string | undefined {
    if (token.value) return `Bearer ${token.value}`
    return undefined
  }

  async function login(email: string, password: string) {
    const response = await $fetch<{ token: string }>('/api/auth/login', {
      baseURL: config.public.apiBase,
      method: 'POST',
      body: { email, password },
    })
    setToken(response.token)
    await fetchMe()
  }

  /** Opens a session with a token obtained elsewhere (e.g. the first-run setup). */
  async function startSession(value: string) {
    setToken(value)
    await fetchMe()
  }

  async function fetchMe() {
    me.value = await useApi()<Me>('/api/me')
    return me.value
  }

  async function logout(redirect = true) {
    setToken(null)
    me.value = null
    if (redirect) await navigateTo('/login')
  }

  return { token: readonly(token), me, isAuthenticated, isAdmin, authorizationHeader, login, startSession, fetchMe, logout }
}
