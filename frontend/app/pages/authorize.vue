<script setup lang="ts">
import type { AuthorizationCheck } from '~/types/api'

/**
 * Authorization page of the OpenID Connect provider (the application sent the user to /oauth/authorize).
 * The user is signed in to Rocket Auth here (the middleware sends them to /login first): they approve, or not,
 * and the browser goes back to the application with a one-time code.
 */
definePageMeta({ layout: 'bare' })
const app = useAppConfig().rocket
useHead({ title: `Autorisation · ${app.name}` })

const route = useRoute()
const auth = useAuth()
const api = useApi()
const config = useRuntimeConfig()

const params = computed(() => Object.fromEntries(Object.entries(route.query).filter(([, v]) => typeof v === 'string')) as Record<string, string>)
const check = ref<AuthorizationCheck | null>(null)
const error = ref<string | null>(null)
const leaving = ref(false)

function leave(url: string) {
  leaving.value = true
  window.location.assign(url)
}

async function cancel(reason: 'login_required' | 'access_denied') {
  const response: { redirectUrl?: string, detail?: string } = await $fetch<{ redirectUrl?: string, detail?: string }>('/api/oauth/authorize/cancel', {
    baseURL: config.public.apiBase,
    method: 'POST',
    body: { params: params.value, error: reason },
  }).catch((e: unknown) => ({ detail: apiErrorMessage(e) }))
  if (response.redirectUrl) leave(response.redirectUrl)
  else error.value = response.detail ?? 'Demande invalide.'
}

async function decide(approve: boolean) {
  try {
    const response = await api<{ redirectUrl: string }>('/api/oauth/authorize', { method: 'POST', body: { params: params.value, approve } })
    leave(response.redirectUrl)
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/** "Ce n'est pas vous ?": back to the login page, then here again. */
async function switchAccount() {
  const query = { ...params.value }
  await auth.logout(false)
  await navigateTo({ path: '/login', query: { redirect: `/authorize?${new URLSearchParams(query)}` } })
}

onMounted(async () => {
  const prompt = (params.value.prompt ?? '').split(' ')
  if (!auth.token.value) {
    // Only reached with prompt=none (see the middleware).
    await cancel('login_required')
    return
  }
  if (prompt.includes('login') || prompt.includes('select_account')) {
    // Fresh sign-in asked by the application: once, then the same request without it.
    const next = { ...params.value, prompt: prompt.filter(p => p !== 'login' && p !== 'select_account').join(' ') }
    if (!next.prompt) delete (next as Record<string, string>).prompt
    await auth.logout(false)
    await navigateTo({ path: '/login', query: { redirect: `/authorize?${new URLSearchParams(next)}` } })
    return
  }
  try {
    const response = await api<AuthorizationCheck & { redirectUrl?: string }>('/api/oauth/authorize', { query: params.value })
    if (response.redirectUrl) {
      leave(response.redirectUrl)
      return
    }
    check.value = response
    // Trusted application or consent already given: straight back to the application.
    if (!response.consentRequired) await decide(true)
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-md" data-testid="authorize">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon :name="app.icon" class="size-6 text-primary" />
          {{ app.name }}
        </div>
      </template>

      <div v-if="error" class="flex flex-col gap-4">
        <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="Connexion impossible" :description="error" />
        <UButton to="/" label="Aller à mon compte" color="neutral" variant="outline" block />
      </div>

      <div v-else-if="check?.consentRequired && !leaving" class="flex flex-col gap-5">
        <div>
          <p class="text-lg font-semibold text-highlighted">
            {{ check.client.name }} souhaite accéder à votre compte
          </p>
          <p v-if="check.client.description" class="mt-1 text-sm text-muted">
            {{ check.client.description }}
          </p>
        </div>

        <div class="flex items-center justify-between gap-3 rounded-lg border border-default p-3">
          <UUser
            v-if="auth.me.value?.user"
            :name="auth.me.value.user.displayName"
            :description="auth.me.value.user.email"
            size="sm"
            class="min-w-0"
          />
          <UButton label="Ce n’est pas vous ?" color="neutral" variant="link" size="xs" @click="switchAccount" />
        </div>

        <div>
          <p class="mb-2 text-sm font-medium">
            Cette application pourra :
          </p>
          <ul class="flex flex-col gap-2" data-testid="scopes">
            <li v-for="scope in check.scopes" :key="scope.name" class="flex items-start gap-2 text-sm">
              <UIcon name="i-lucide-check" class="mt-0.5 size-4 shrink-0 text-success" />
              {{ scope.description }}
            </li>
          </ul>
        </div>

        <p class="text-xs text-muted">
          Vous serez redirigé vers <span class="font-medium">{{ check.client.redirectHost }}</span>. Vous pourrez retirer cet accès à tout moment dans « Applications autorisées ».
        </p>

        <div class="flex gap-2">
          <UButton label="Refuser" color="neutral" variant="outline" class="flex-1 justify-center" data-testid="deny" @click="decide(false)" />
          <UButton label="Autoriser" class="flex-1 justify-center" data-testid="approve" @click="decide(true)" />
        </div>
      </div>

      <div v-else class="flex items-center gap-3 text-sm text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" />
        {{ check ? `Retour vers ${check.client.name}…` : 'Vérification de la demande…' }}
      </div>
    </UCard>
  </div>
</template>
