<script setup lang="ts">
import type { Consent } from '~/types/api'

const app = useAppConfig().rocket
useHead({ title: `Applications autorisées · ${app.name}` })

const api = useApi()
const toast = useToast()

const { data: consents, status, refresh } = await useAsyncData('consents', () => api<Consent[]>('/api/consents'), { default: () => [] })

const SCOPES: Record<string, string> = {
  openid: 'Identité',
  profile: 'Nom et prénom',
  email: 'Adresse email',
  groups: 'Groupes',
  offline_access: 'Accès hors connexion',
}

const toRevoke = ref<Consent | null>(null)
async function revoke() {
  const consent = toRevoke.value!
  toRevoke.value = null
  try {
    await api(`/api/consents/${consent.id}`, { method: 'DELETE' })
    toast.add({ title: `Accès de ${consent.clientName} retiré`, color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Retrait impossible', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="consents">
    <template #header>
      <UDashboardNavbar title="Applications autorisées">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <p class="text-sm text-muted">
          Les applications auxquelles vous avez permis d’utiliser votre compte {{ app.name }}. Retirer un accès vous déconnecte de l’application : elle vous redemandera votre accord.
        </p>
        <div v-if="status === 'pending'" class="py-8 text-center text-sm text-muted">
          Chargement…
        </div>
        <UCard v-for="consent in consents" :key="consent.id" data-testid="consent">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="font-medium text-highlighted">
                {{ consent.clientName }}
              </p>
              <p v-if="consent.clientDescription" class="text-sm text-muted">
                {{ consent.clientDescription }}
              </p>
              <div class="mt-2 flex flex-wrap gap-1">
                <UBadge v-for="scope in consent.scopes" :key="scope" :label="SCOPES[scope] ?? scope" variant="subtle" color="neutral" size="sm" />
              </div>
              <p class="mt-2 text-xs text-muted">
                Autorisée le {{ formatDate(consent.grantedAt) }}<template v-if="consent.lastUsedAt">
                  · dernière connexion {{ timeAgo(consent.lastUsedAt) }}
                </template>
              </p>
            </div>
            <UButton label="Retirer l’accès" color="error" variant="soft" size="sm" @click="toRevoke = consent" />
          </div>
        </UCard>
        <UCard v-if="status !== 'pending' && !consents.length">
          <p class="py-6 text-center text-sm text-muted">
            Aucune application autorisée. Les applications internes de confiance n’apparaissent pas ici : elles ne demandent pas d’accord.
          </p>
        </UCard>
      </div>

      <UModal :open="toRevoke !== null" :title="`Retirer l’accès de ${toRevoke?.clientName} ?`" description="Vous serez déconnecté de cette application." @update:open="(value: boolean) => { if (!value) toRevoke = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toRevoke = null" />
            <UButton label="Retirer" color="error" @click="revoke" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
