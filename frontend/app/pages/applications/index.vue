<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Application } from '~/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Applications · ${appName}` })

const api = useApi()
const toast = useToast()
const config = useRuntimeConfig()
const requestUrl = useRequestURL()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

const { data: applications, status, refresh } = await useAsyncData('applications', () => api<Application[]>('/api/applications'), { default: () => [] })

async function patch(application: Application, body: Partial<Application>) {
  try {
    Object.assign(application, await api<Application>(`/api/applications/${application.id}`, { method: 'PATCH', body }))
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

const columns: TableColumn<Application>[] = [
  {
    accessorKey: 'name',
    header: 'Application',
    cell: ({ row }) => h('div', [
      h('p', { class: 'font-medium' }, row.original.name),
      h('p', { class: 'font-mono text-xs text-muted' }, `${row.original.tokenHint}…`),
    ]),
  },
  {
    accessorKey: 'canImpersonate',
    header: 'Impersonation',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: row.original.canImpersonate ? 'warning' : 'neutral', label: row.original.canImpersonate ? 'Autorisée' : 'Non' }),
  },
  { accessorKey: 'lastUsedAt', header: 'Dernier appel', cell: ({ row }) => formatDate(row.original.lastUsedAt) },
  {
    accessorKey: 'enabled',
    header: 'Active',
    cell: ({ row }) => h(USwitch, { 'modelValue': row.original.enabled, 'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }) }),
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      h(UButton, { icon: 'i-lucide-pencil', color: 'neutral', variant: 'ghost', 'aria-label': 'Modifier', onClick: () => edit(row.original) }),
      h(UButton, { icon: 'i-lucide-rotate-cw', color: 'neutral', variant: 'ghost', 'aria-label': 'Régénérer le jeton', onClick: () => (toRotate.value = row.original) }),
      h(UButton, { icon: 'i-lucide-trash-2', color: 'error', variant: 'ghost', 'aria-label': 'Supprimer', onClick: () => (toDelete.value = row.original) }),
    ]),
  },
]

// Create / edit
const formOpen = ref(false)
const editing = ref<Application | null>(null)
const form = reactive({ name: '', description: '', canImpersonate: true })

function create() {
  editing.value = null
  Object.assign(form, { name: '', description: '', canImpersonate: true })
  formOpen.value = true
}

// "Nouvelle application" from the dashboard.
onMounted(() => {
  if (useRoute().query.new) create()
})

function edit(application: Application) {
  editing.value = application
  Object.assign(form, {
    name: application.name,
    description: application.description ?? '',
    canImpersonate: application.canImpersonate,
  })
  formOpen.value = true
}

async function submit() {
  const body = { ...form, description: form.description || null }
  if (editing.value) {
    if (await patch(editing.value, body)) formOpen.value = false
    return
  }
  try {
    const created = await api<Application>('/api/applications', { method: 'POST', body })
    formOpen.value = false
    revealed.value = { application: created, token: created.plainToken! }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

// Secret shown once
const revealed = ref<{ application: Application, token: string } | null>(null)
const toRotate = ref<Application | null>(null)
const toDelete = ref<Application | null>(null)

async function rotate() {
  const application = toRotate.value!
  toRotate.value = null
  try {
    const { token } = await api<{ token: string }>(`/api/applications/${application.id}/regenerate-token`, { method: 'POST' })
    revealed.value = { application, token }
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Régénération impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove() {
  const application = toDelete.value!
  toDelete.value = null
  try {
    await api(`/api/applications/${application.id}`, { method: 'DELETE' })
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

const snippet = computed(() => revealed.value && `# Server side only: never expose the application token to a browser.
curl ${config.public.apiBase || requestUrl.origin}/api/me \
  -H "Authorization: Bearer ${revealed.value.token}" \
  -H "X-Impersonate-User: jean.dupont@example.org"`)
</script>

<template>
  <UDashboardPanel id="applications">
    <template #header>
      <UDashboardNavbar title="Applications externes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nouvelle application" @click="create" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        icon="i-lucide-info"
        variant="subtle"
        color="neutral"
        title="Comment ça marche"
        description="Une application s'authentifie avec son jeton (Authorization: Bearer raa_…). Si l'impersonation est autorisée, l'en-tête X-Impersonate-User lui permet d'agir en tant qu'un utilisateur, jamais avec le rôle administrateur. Sans impersonation, elle peut seulement s'identifier (GET /api/me)."
      />
      <UTable :data="applications" :columns="columns" :loading="status === 'pending'" empty="Aucune application." />

      <UModal v-model:open="formOpen" :title="editing ? `Modifier ${editing.name}` : 'Nouvelle application'">
        <template #body>
          <form id="application-form" class="flex flex-col gap-3" @submit.prevent="submit">
            <UFormField label="Nom" required>
              <UInput v-model="form.name" class="w-full" />
            </UFormField>
            <UFormField label="Description">
              <UTextarea v-model="form.description" class="w-full" :rows="2" />
            </UFormField>
            <USwitch v-model="form.canImpersonate" label="Peut agir en tant qu'utilisateur (impersonation)" />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="formOpen = false" />
            <UButton type="submit" form="application-form" :label="editing ? 'Enregistrer' : 'Créer'" />
          </div>
        </template>
      </UModal>

      <UModal :open="revealed !== null" title="Jeton de l'application" :dismissible="false" :ui="{ content: 'max-w-2xl' }" @update:open="(value: boolean) => { if (!value) revealed = null }">
        <template #body>
          <div v-if="revealed" class="flex flex-col gap-4">
            <UAlert color="warning" variant="subtle" icon="i-lucide-triangle-alert" description="Copiez ce jeton maintenant : il ne sera plus jamais affiché. Stockez-le côté serveur uniquement." />
            <div class="flex items-center gap-2">
              <code class="min-w-0 flex-1 break-all rounded bg-elevated p-2 text-sm">{{ revealed.token }}</code>
              <UButton icon="i-lucide-copy" color="neutral" variant="outline" aria-label="Copier" @click="copy(revealed.token)" />
            </div>
            <div>
              <p class="mb-1 text-sm font-medium">
                Exemple d'appel
              </p>
              <pre class="max-h-72 overflow-auto rounded bg-elevated p-3 text-xs">{{ snippet }}</pre>
            </div>
          </div>
        </template>
        <template #footer>
          <div class="flex w-full justify-end">
            <UButton label="J'ai copié le jeton" @click="revealed = null" />
          </div>
        </template>
      </UModal>

      <UModal :open="toRotate !== null" title="Régénérer le jeton ?" description="L'ancien jeton cessera immédiatement de fonctionner." @update:open="(value: boolean) => { if (!value) toRotate = null }">
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="toRotate = null" />
            <UButton label="Régénérer" color="warning" @click="rotate" />
          </div>
        </template>
      </UModal>

      <UModal :open="toDelete !== null" title="Supprimer l'application ?" :description="toDelete ? `« ${toDelete.name} » ne pourra plus accéder à l'API.` : ''" @update:open="(value: boolean) => { if (!value) toDelete = null }">
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
