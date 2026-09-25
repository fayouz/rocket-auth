import type { AppVersionInfo, UpdateStatus } from '~/types/api'

/**
 * Version of the platform (API and interface) and, for administrators, the available update.
 * Loaded once per session: the API caches the GitHub check for an hour anyway.
 */
export function useAppVersion() {
  const config = useRuntimeConfig()
  const auth = useAuth()
  const api = useApi()
  const apiVersion = useState<AppVersionInfo | null>('ra_api_version', () => null)
  const update = useState<UpdateStatus | null>('ra_update_status', () => null)
  const loaded = useState('ra_version_loaded', () => false)

  const frontVersion = computed(() => formatVersion(String(config.public.appVersion ?? '')))
  const version = computed(() => apiVersion.value?.version ?? frontVersion.value)
  const updateAvailable = computed(() => update.value?.updateAvailable === true)

  async function fetchVersion() {
    apiVersion.value = await api<AppVersionInfo>('/api/system/version')
    return apiVersion.value
  }

  async function fetchUpdate(refresh = false) {
    update.value = await api<UpdateStatus>('/api/system/update', { query: refresh ? { refresh: 1 } : {} })
    apiVersion.value = { version: update.value.current.version, release: update.value.current.release }
    return update.value
  }

  async function load() {
    if (loaded.value) return
    loaded.value = true
    try {
      await (auth.isAdmin.value ? fetchUpdate() : fetchVersion())
    }
    catch {
      // Informational only: the sidebar just shows the interface version.
      loaded.value = false
    }
  }

  return { apiVersion, frontVersion, version, update, updateAvailable, fetchVersion, fetchUpdate, load }
}
