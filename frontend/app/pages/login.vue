<script setup lang="ts">
definePageMeta({ layout: 'bare' })
const app = useAppConfig().rocket
useHead({ title: `Connexion · ${app.name}` })

const auth = useAuth()
const route = useRoute()
const state = reactive({ email: '', password: '' })
const error = ref<string | null>(null)
const loading = ref(false)

// Single sign-on providers (OpenID Connect servers enabled by an administrator).
const { data: providers } = await useAsyncData('auth-providers', () => $fetch<{ providers: AuthProvider[] }>('/api/auth/providers', {
  baseURL: useRuntimeConfig().public.apiBase,
}).then(r => r.providers).catch(() => [] as AuthProvider[]), { default: () => [] as AuthProvider[] })
const redirecting = ref<string | null>(null)

function redirectTarget(): string {
  return typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
    ? route.query.redirect
    : '/'
}

async function signInWith(provider: AuthProvider) {
  redirecting.value = provider.id
  await startOidcSignIn(provider, redirectTarget())
}

async function submit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(state.email, state.password)
    await navigateTo(redirectTarget())
  }
  catch (e) {
    error.value = apiErrorMessage(e)
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon :name="app.icon" class="size-6 text-primary" />
          {{ app.name }}
        </div>
        <p class="mt-1 text-sm text-muted">
          {{ app.tagline }}
        </p>
      </template>

      <div v-if="providers.length" class="mb-4 flex flex-col gap-2">
        <UButton
          v-for="provider in providers"
          :key="provider.id"
          :label="`Se connecter avec ${provider.name}`"
          icon="i-lucide-shield-check"
          color="neutral"
          variant="outline"
          block
          :loading="redirecting === provider.id"
          @click="signInWith(provider)"
        />
        <USeparator label="ou" class="my-2" />
      </div>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <UFormField label="Email" required>
          <UInput v-model="state.email" type="email" autocomplete="username" class="w-full" autofocus />
        </UFormField>
        <UFormField label="Mot de passe" required>
          <UInput v-model="state.password" type="password" autocomplete="current-password" class="w-full" />
        </UFormField>
        <UAlert v-if="error" color="error" variant="subtle" :description="error" icon="i-lucide-circle-alert" />
        <UButton type="submit" label="Se connecter" block :loading="loading" />
      </form>
    </UCard>
  </div>
</template>
