export interface SetupStatus {
  /** No user yet: the first administrator must be created. */
  required: boolean
  /** SETUP_TOKEN is set on the server: the setup form asks for it. */
  tokenRequired: boolean
}

/** First-run setup status, fetched once per page load (until the setup is done). */
export async function useSetupStatus(): Promise<SetupStatus> {
  const status = useState<SetupStatus | null>('ra_setup', () => null)
  if (!status.value) {
    try {
      status.value = await $fetch<SetupStatus>('/api/setup', { baseURL: useRuntimeConfig().public.apiBase, headers: { Accept: 'application/json' } })
    }
    catch {
      // API unreachable: let the login page report it.
      return { required: false, tokenRequired: false }
    }
  }
  return status.value
}

export function markSetupDone() {
  useState<SetupStatus | null>('ra_setup').value = { required: false, tokenRequired: false }
}
