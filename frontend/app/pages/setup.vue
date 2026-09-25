<script setup lang="ts">
definePageMeta({ layout: 'bare' })
const appName = useAppConfig().rocket.name
useHead({ title: `Configuration initiale · ${appName}` })

const auth = useAuth()
const config = useRuntimeConfig()
const setup = await useSetupStatus()

const state = reactive({ firstName: '', lastName: '', email: '', password: '', confirmation: '', setupToken: '' })
const error = ref<string | null>(null)
const loading = ref(false)
const showPassword = ref(false)

const MIN_LENGTH = 12
const passwordTooShort = computed(() => state.password.length > 0 && state.password.length < MIN_LENGTH)
const mismatch = computed(() => state.confirmation.length > 0 && state.confirmation !== state.password)
const canSubmit = computed(() => isEmail(state.email) && state.password.length >= MIN_LENGTH && state.password === state.confirmation
  && (!setup.tokenRequired || state.setupToken.trim() !== '') && !loading.value)

async function submit() {
  if (!canSubmit.value) return
  loading.value = true
  error.value = null
  try {
    const { token } = await $fetch<{ token: string }>('/api/setup', {
      baseURL: config.public.apiBase,
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: {
        email: state.email.trim(),
        password: state.password,
        firstName: state.firstName.trim() || null,
        lastName: state.lastName.trim() || null,
        setupToken: setup.tokenRequired ? state.setupToken.trim() : null,
      },
    })
    markSetupDone()
    await auth.startSession(token)
    await navigateTo('/settings?welcome=1')
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
    <UCard class="w-full max-w-md" data-testid="setup">
      <template #header>
        <div class="flex items-center gap-2 text-lg font-semibold">
          <UIcon name="i-lucide-rocket" class="size-6 text-primary" />
          Bienvenue dans {{ appName }}
        </div>
        <p class="mt-1 text-sm text-muted">
          Première installation : créez le compte administrateur. Il gérera ensuite les utilisateurs, les applications et les réglages.
        </p>
      </template>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Prénom">
            <UInput v-model="state.firstName" autocomplete="given-name" class="w-full" />
          </UFormField>
          <UFormField label="Nom">
            <UInput v-model="state.lastName" autocomplete="family-name" class="w-full" />
          </UFormField>
        </div>
        <UFormField label="Email" required>
          <UInput v-model="state.email" type="email" autocomplete="username" class="w-full" autofocus />
        </UFormField>
        <UFormField
          label="Mot de passe"
          required
          :hint="`${MIN_LENGTH} caractères minimum`"
          :error="passwordTooShort ? `Encore ${MIN_LENGTH - state.password.length} caractère(s)` : undefined"
        >
          <UInput
            v-model="state.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="new-password"
            class="w-full"
            :ui="{ trailing: 'pe-1' }"
          >
            <template #trailing>
              <UButton
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                color="neutral"
                variant="link"
                size="sm"
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>
        </UFormField>
        <UFormField label="Confirmation" required :error="mismatch ? 'Les mots de passe ne correspondent pas' : undefined">
          <UInput v-model="state.confirmation" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="w-full" />
        </UFormField>
        <UFormField
          v-if="setup.tokenRequired"
          label="Jeton d’installation"
          required
          hint="SETUP_TOKEN"
          :help="`Défini dans la configuration du serveur (.env) par la personne qui a installé ${appName}.`"
        >
          <UInput v-model="state.setupToken" type="password" autocomplete="off" class="w-full" />
        </UFormField>

        <UAlert v-if="error" color="error" variant="subtle" :description="error" icon="i-lucide-circle-alert" />
        <UButton type="submit" label="Créer le compte administrateur" icon="i-lucide-shield-check" block :loading="loading" :disabled="!canSubmit" />
      </form>

      <template #footer>
        <p class="text-xs text-muted">
          Cette page n’est disponible que tant qu’aucun compte n’existe. Vous pourrez ensuite ajouter des administrateurs dans « Utilisateurs », ou via le groupe LDAP des administrateurs.
        </p>
      </template>
    </UCard>
  </div>
</template>
