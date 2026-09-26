<script setup lang="ts">
/**
 * Sign-out page of the OpenID Connect provider (end_session_endpoint): ends the Rocket Auth session, then goes
 * back to the application when it registered this address (post_logout_redirect_uri).
 */
// Works without a session too.
definePageMeta({ layout: 'bare', public: true })
const app = useAppConfig().rocket
useHead({ title: `Déconnexion · ${app.name}` })

const route = useRoute()
const auth = useAuth()
const config = useRuntimeConfig()
const client = ref<string | null>(null)
const done = ref(false)

onMounted(async () => {
  const query = Object.fromEntries(Object.entries(route.query).filter(([, v]) => typeof v === 'string')) as Record<string, string>
  const response = await $fetch<{ redirectUrl: string | null, client: string | null }>('/api/oauth/logout', { baseURL: config.public.apiBase, query })
    .catch(() => ({ redirectUrl: null, client: null }))
  await auth.logout(false)
  client.value = response.client
  if (response.redirectUrl) {
    window.location.assign(response.redirectUrl)
    return
  }
  done.value = true
})
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <div v-if="done" class="flex flex-col gap-4" data-testid="signed-out">
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon name="i-lucide-circle-check" class="size-6 text-success" />
          Vous êtes déconnecté
        </div>
        <p class="text-sm text-muted">
          Votre session {{ app.name }} est terminée{{ client ? `, ainsi que celle de ${client}` : '' }}.
        </p>
        <UButton to="/login" label="Se reconnecter" block />
      </div>
      <div v-else class="flex items-center gap-3 text-sm text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" />
        Déconnexion…
      </div>
    </UCard>
  </div>
</template>
