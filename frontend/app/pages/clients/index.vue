<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OAuthClient, OAuthGrant, OAuthScope } from '~/types/api'

definePageMeta({ admin: true })
const app = useAppConfig().rocket
useHead({ title: `Clients OAuth · ${app.name}` })

const api = useApi()
const toast = useToast()
const config = useRuntimeConfig()
const requestUrl = useRequestURL()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

const { data: clients, status, refresh } = await useAsyncData('oauth-clients', () => api<OAuthClient[]>('/api/oauth/clients'), { default: () => [] })

// Issuer shown to administrators: what applications must be configured with.
const { data: discovery } = await useAsyncData('oidc-discovery', () => $fetch<{ issuer: string }>('/.well-known/openid-configuration', { baseURL: config.public.apiBase || requestUrl.origin }).catch(() => null))

const SCOPES: { value: OAuthScope, label: string }[] = [
  { value: 'openid', label: 'openid : identité (obligatoire pour OpenID Connect)' },
  { value: 'profile', label: 'profile : nom et prénom' },
  { value: 'email', label: 'email : adresse email' },
  { value: 'groups', label: 'groups : groupes de l’utilisateur' },
  { value: 'offline_access', label: 'offline_access : accès hors connexion' },
]
const GRANTS: { value: OAuthGrant, label: string }[] = [
  { value: 'authorization_code', label: 'Code d’autorisation (connexion des utilisateurs)' },
  { value: 'refresh_token', label: 'Jeton de rafraîchissement' },
  { value: 'client_credentials', label: 'Identifiants client (application seule, sans utilisateur)' },
]

async function patch(client: OAuthClient, body: Partial<OAuthClient>) {
  try {
    Object.assign(client, await api<OAuthClient>(`/api/oauth/clients/${client.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

const columns: TableColumn<OAuthClient>[] = [
  {
    accessorKey: 'name',
    header: 'Application',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'font-mono text-xs text-muted' }, row.original.clientId),
    ]),
  },
  {
    accessorKey: 'confidential',
    header: 'Type',
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, [
      h(UBadge, { variant: 'subtle', color: row.original.confidential ? 'info' : 'neutral', label: row.original.confidential ? 'Confidentiel' : 'Public (PKCE)' }),
      row.original.trusted ? h(UBadge, { variant: 'subtle', color: 'warning', label: 'De confiance' }) : null,
    ]),
  },
  { accessorKey: 'redirectUris', header: 'Redirections', cell: ({ row }) => h('div', { class: 'max-w-72 truncate font-mono text-xs' }, row.original.redirectUris.join(', ') || '—') },
  { accessorKey: 'lastUsedAt', header: 'Dernier usage', cell: ({ row }) => formatDate(row.original.lastUsedAt) },
  {
    accessorKey: 'enabled',
    header: 'Actif',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { icon: 'i-lucide-pencil', color: 'neutral', variant: 'ghost', 'aria-label': 'Modifier', onClick: () => edit(row.original) }),
      row.original.confidential ? h(UButton, { icon: 'i-lucide-rotate-cw', color: 'neutral', variant: 'ghost', 'aria-label': 'Régénérer le secret', onClick: () => (toRotate.value = row.original) }) : null,
      h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': 'Supprimer', onClick: () => (toDelete.value = row.original) }),
    ]),
  },
]

const EMPTY = {
  name: '',
  description: '',
  clientId: '',
  homeUrl: '',
  icon: '',
  confidential: true,
  trusted: false,
  redirectUris: [] as string[],
  postLogoutRedirectUris: [] as string[],
  allowedScopes: ['openid', 'email', 'profile', 'groups'] as OAuthScope[],
  grantTypes: ['authorization_code', 'refresh_token'] as OAuthGrant[],
}
const formOpen = ref(false)
const editing = ref<OAuthClient | null>(null)
const form = reactive({ ...EMPTY })

function create() {
  editing.value = null
  Object.assign(form, structuredClone(EMPTY))
  formOpen.value = true
}
onMounted(() => {
  if (useRoute().query.new) create()
})

function edit(client: OAuthClient) {
  editing.value = client
  Object.assign(form, {
    name: client.name,
    description: client.description ?? '',
    clientId: client.clientId,
    homeUrl: client.homeUrl ?? '',
    icon: client.icon ?? '',
    confidential: client.confidential,
    trusted: client.trusted,
    redirectUris: [...client.redirectUris],
    postLogoutRedirectUris: [...client.postLogoutRedirectUris],
    allowedScopes: [...client.allowedScopes],
    grantTypes: [...client.grantTypes],
  })
  formOpen.value = true
}

const revealed = ref<{ client: OAuthClient, secret: string | null } | null>(null)

async function submit() {
  const body = { ...form, description: form.description || null, homeUrl: form.homeUrl || null, icon: form.icon || null }
  if (editing.value) {
    const { clientId: _clientId, confidential: _confidential, ...changes } = body
    if (await patch(editing.value, changes)) formOpen.value = false
    return
  }
  try {
    const created = await api<OAuthClient>('/api/oauth/clients', { method: 'POST', body })
    formOpen.value = false
    revealed.value = { client: created, secret: created.plainSecret ?? null }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const toRotate = ref<OAuthClient | null>(null)
const toDelete = ref<OAuthClient | null>(null)

async function rotate() {
  const client = toRotate.value!
  toRotate.value = null
  try {
    const { secret } = await api<{ secret: string }>(`/api/oauth/clients/${client.id}/regenerate-secret`, { method: 'POST' })
    revealed.value = { client, secret }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Régénération impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  const client = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/oauth/clients/${client.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function copy(text: string) {
  await navigator.clipboard.writeText(text)
  toast.add({ title: 'Copié', color: 'success', duration: 1500 })
}
</script>

<template>
  <UDashboardPanel id="oauth-clients">
    <template #header>
      <UDashboardNavbar title="Clients OAuth">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouveau client" data-testid="new-client" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Connecter une application"
        data-testid="issuer"
      >
        <template #description>
          Configurez l’application comme client OpenID Connect avec l’émetteur
          <code class="rounded bg-elevated px-1">{{ discovery?.issuer ?? '…' }}</code>
          (découverte : <code class="rounded bg-elevated px-1">/.well-known/openid-configuration</code>), son client ID et son secret.
          Les applications Rocket se configurent dans Administration → Serveurs d’authentification → OpenID Connect.
        </template>
      </UAlert>
      <UTable :data="clients" :columns="columns" :loading="status === 'pending'" empty="Aucun client : créez-en un par application qui connecte ses utilisateurs avec Rocket Auth." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouveau client OAuth'" :ui="{ content: 'max-w-2xl' }">
        <template #body>
          <form id="client-form" class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField label="Nom de l’application" required>
                <UInput v-model="form.name" class="w-full" placeholder="Rocket Mailer" />
              </UFormField>
              <UFormField label="Client ID" :help="editing ? 'Ne peut plus changer.' : 'Laisser vide pour le générer.'">
                <UInput v-model="form.clientId" class="w-full font-mono" :disabled="!!editing" />
              </UFormField>
            </div>
            <UFormField label="Description" help="Affichée aux utilisateurs sur l’écran d’autorisation.">
              <UTextarea v-model="form.description" class="w-full" :rows="2" />
            </UFormField>
            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField label="Adresse de l’application" help="Dans la suite : affichée dans le sélecteur d’applications.">
                <UInput v-model="form.homeUrl" class="w-full" placeholder="https://print.exemple.com" />
              </UFormField>
              <UFormField label="Icône" help="Ex. i-lucide-printer">
                <UInput v-model="form.icon" class="w-full font-mono" :leading-icon="form.icon || undefined" placeholder="i-lucide-app-window" />
              </UFormField>
            </div>
            <UFormField label="URL de retour (redirect URI)" required hint="ex. https://mailer.exemple.com/auth/callback">
              <UInputTags v-model="form.redirectUris" add-on-blur add-on-paste class="w-full" data-testid="redirect-uris" />
            </UFormField>
            <UFormField label="URL après déconnexion (optionnel)" hint="post_logout_redirect_uri">
              <UInputTags v-model="form.postLogoutRedirectUris" add-on-blur add-on-paste class="w-full" />
            </UFormField>
            <div class="grid gap-3 sm:grid-cols-2">
              <UFormField label="Scopes autorisés">
                <UCheckboxGroup v-model="form.allowedScopes" :items="SCOPES" />
              </UFormField>
              <UFormField label="Flux autorisés">
                <UCheckboxGroup v-model="form.grantTypes" :items="GRANTS" />
              </UFormField>
            </div>
            <USwitch v-model="form.confidential" :disabled="!!editing" label="Client confidentiel (application serveur, avec un secret)" description="Sinon : client public (navigateur, mobile), qui doit utiliser PKCE." />
            <USwitch v-model="form.trusted" label="Application de confiance" description="Application interne : pas d’écran d’autorisation pour les utilisateurs." />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="client-form" :label="editing ? 'Enregistrer' : 'Créer'" />
          </div>
        </template>
      </UModal>

      <UModal :open="revealed !== null" title="Identifiants du client" :dismissible="false" @update:open="(value: boolean) => { if (!value) revealed = null }">
        <template #body>
          <div v-if="revealed" class="flex flex-col gap-3">
            <UFormField label="Client ID">
              <div class="flex items-center gap-2">
                <code class="min-w-0 flex-1 break-all rounded bg-elevated p-2 text-sm" data-testid="client-id">{{ revealed.client.clientId }}</code>
                <UButton icon="i-lucide-copy" color="neutral" variant="outline" aria-label="Copier" @click="copy(revealed.client.clientId)" />
              </div>
            </UFormField>
            <template v-if="revealed.secret">
              <UAlert color="warning" variant="subtle" icon="i-lucide-triangle-alert" description="Copiez ce secret maintenant : il ne sera plus jamais affiché. Il ne doit être connu que du serveur de l’application." />
              <UFormField label="Secret">
                <div class="flex items-center gap-2">
                  <code class="min-w-0 flex-1 break-all rounded bg-elevated p-2 text-sm" data-testid="client-secret">{{ revealed.secret }}</code>
                  <UButton icon="i-lucide-copy" color="neutral" variant="outline" aria-label="Copier" @click="copy(revealed.secret)" />
                </div>
              </UFormField>
            </template>
          </div>
        </template>
        <template #footer>
          <div class="flex w-full justify-end">
            <UButton label="C’est noté" @click="revealed = null" />
          </div>
        </template>
      </UModal>

      <UModal :open="toRotate !== null" title="Régénérer le secret ?" description="L’ancien secret cessera immédiatement de fonctionner : l’application devra être reconfigurée." @update:open="(value: boolean) => { if (!value) toRotate = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toRotate = null" />
            <UButton label="Régénérer" color="warning" @click="rotate" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer le client ?" :description="toDelete ? `Les utilisateurs ne pourront plus se connecter à « ${toDelete.name} » avec Rocket Auth.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toDelete = null" />
            <UButton label="Supprimer" color="error" @click="remove" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
