<script setup lang="ts">
definePageMeta({ layout: 'bare' })
const appName = useAppConfig().rocket.name
useHead({ title: `Connexion · ${appName}` })

const auth = useAuth()
const config = useRuntimeConfig()
const route = useRoute()
const error = ref<string | null>(null)

onMounted(async () => {
  const query = route.query
  const pending = takePendingSignIn(typeof query.state === 'string' ? query.state : null)
  if (typeof query.error === 'string') {
    error.value = typeof query.error_description === 'string' ? query.error_description : `Connexion refusée (${query.error}).`
    return
  }
  if (!pending || typeof query.code !== 'string') {
    error.value = 'Cette réponse de connexion est invalide ou a expiré. Recommencez depuis la page de connexion.'
    return
  }
  try {
    const response = await $fetch<{ token: string }>('/api/auth/oidc/callback', {
      baseURL: config.public.apiBase,
      method: 'POST',
      body: {
        provider: pending.provider,
        code: query.code,
        codeVerifier: pending.codeVerifier,
        redirectUri: oidcRedirectUri(),
        nonce: pending.nonce,
      },
    })
    await auth.startSession(response.token)
    const redirect = pending.redirect.startsWith('/') && !pending.redirect.startsWith('//') ? pending.redirect : '/'
    await navigateTo(redirect, { replace: true })
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <div v-if="!error" class="flex items-center gap-3 text-sm text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" />
        Connexion en cours…
      </div>
      <div v-else class="flex flex-col gap-4">
        <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="Connexion impossible" :description="error" />
        <UButton to="/login" label="Retour à la connexion" color="neutral" variant="outline" block />
      </div>
    </UCard>
  </div>
</template>
