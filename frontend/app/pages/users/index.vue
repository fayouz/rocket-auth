<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { User } from '~/types/api'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `Utilisateurs · ${appName}` })

const api = useApi()
const auth = useAuth()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const USwitch = resolveComponent('USwitch')
const UButton = resolveComponent('UButton')

const { data: users, status, refresh } = await useAsyncData('users', () => api<User[]>('/api/users', { query: { itemsPerPage: 500 } }), { default: () => [] })

const search = ref('')
const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term ? users.value.filter(u => `${u.displayName} ${u.email}`.toLowerCase().includes(term)) : users.value
})

async function patch(user: User, body: Partial<User> & { plainPassword?: string }) {
  try {
    const updated = await api<User>(`/api/users/${user.id}`, { method: 'PATCH', body })
    Object.assign(user, updated)
    return true
  }
  catch (error) {
    toast.add({ title: 'Mise à jour impossible', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

function toggleAdmin(user: User, admin: boolean) {
  const roles = user.roles.filter(r => r !== 'ROLE_ADMIN' && r !== 'ROLE_USER')
  return patch(user, { roles: admin ? [...roles, 'ROLE_ADMIN'] : roles })
}

const columns: TableColumn<User>[] = [
  {
    accessorKey: 'displayName',
    header: 'Utilisateur',
    cell: ({ row }) => h('div', [h('p', { class: 'font-medium' }, row.original.displayName), h('p', { class: 'text-sm text-muted' }, row.original.email)]),
  },
  {
    accessorKey: 'source',
    header: 'Source',
    cell: ({ row }) => h(UBadge, {
      variant: 'subtle',
      color: row.original.source === 'ldap' ? 'info' : row.original.source === 'oidc' ? 'primary' : 'neutral',
      label: row.original.source === 'ldap'
        ? (row.original.authenticationServerName ?? 'LDAP')
        : row.original.source === 'oidc' ? (row.original.authenticationServerName ?? 'SSO') : 'Local',
    }),
  },
  {
    id: 'admin',
    header: 'Admin',
    cell: ({ row }) => h(USwitch, {
      'modelValue': row.original.roles.includes('ROLE_ADMIN'),
      'disabled': row.original.id === auth.me.value?.user?.id,
      'onUpdate:modelValue': (value: boolean) => toggleAdmin(row.original, value),
    }),
  },
  {
    accessorKey: 'enabled',
    header: 'Actif',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2' }, [
      h(UBadge, {
        variant: 'subtle',
        color: row.original.enabled ? 'success' : 'neutral',
        label: row.original.enabled ? 'Actif' : 'Désactivé',
      }),
      h(USwitch, {
        'modelValue': row.original.enabled,
        'disabled': row.original.id === auth.me.value?.user?.id,
        'aria-label': row.original.enabled ? 'Désactiver' : 'Activer',
        'onUpdate:modelValue': (value: boolean) => patch(row.original, { enabled: value }),
      }),
    ]),
  },
  {
    accessorKey: 'groups',
    header: 'Groupes',
    cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, row.original.groups.length
      ? row.original.groups.map(group => h(UBadge, { label: group, variant: 'outline', color: 'neutral', size: 'sm' }))
      : [h('span', { class: 'text-muted' }, '—')]),
  },
  { accessorKey: 'ldapSyncedAt', header: 'Synchro LDAP', cell: ({ row }) => formatDate(row.original.ldapSyncedAt) },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      // Directory accounts get their groups from LDAP at each synchronization.
      row.original.source !== 'ldap'
        ? h(UButton, { 'icon': 'i-lucide-users-round', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Groupes', 'data-testid': 'edit-groups', 'onClick': () => editGroups(row.original) })
        : null,
      row.original.source === 'local'
        ? h(UButton, { icon: 'i-lucide-key', color: 'neutral', variant: 'ghost', 'aria-label': 'Changer le mot de passe', onClick: () => (passwordFor.value = row.original) })
        : null,
    ]),
  },
]

// Groups sent to applications ("groups" claim)
const groupsFor = ref<User | null>(null)
const groups = ref<string[]>([])
function editGroups(user: User) {
  groupsFor.value = user
  groups.value = [...user.groups]
}
async function saveGroups() {
  if (groupsFor.value && await patch(groupsFor.value, { groups: groups.value })) groupsFor.value = null
}

// Create a local user
// Opened directly by "Nouvel utilisateur" on the dashboard.
const createOpen = ref(Boolean(useRoute().query.new))
const newUser = reactive({ email: '', firstName: '', lastName: '', plainPassword: '', admin: false })
async function createUser() {
  try {
    await api('/api/users', {
      method: 'POST',
      body: {
        email: newUser.email,
        firstName: newUser.firstName || null,
        lastName: newUser.lastName || null,
        plainPassword: newUser.plainPassword,
        roles: newUser.admin ? ['ROLE_ADMIN'] : [],
      },
    })
    Object.assign(newUser, { email: '', firstName: '', lastName: '', plainPassword: '', admin: false })
    createOpen.value = false
    toast.add({ title: 'Utilisateur créé', color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Création impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

// Password reset for local users
const passwordFor = ref<User | null>(null)
const newPassword = ref('')
async function changePassword() {
  if (passwordFor.value && await patch(passwordFor.value, { plainPassword: newPassword.value })) {
    toast.add({ title: 'Mot de passe modifié', color: 'success' })
    passwordFor.value = null
    newPassword.value = ''
  }
}

// LDAP synchronization
const syncing = ref(false)
async function syncLdap(dryRun: boolean) {
  syncing.value = true
  try {
    const report = await api<{ created: number, updated: number, disabled: number, conflicts: string[] }>('/api/ldap/sync', { method: 'POST', query: { dryRun } })
    toast.add({
      title: dryRun ? 'Simulation de synchronisation' : 'Synchronisation LDAP terminée',
      description: `${report.created} créé(s), ${report.updated} mis à jour, ${report.disabled} désactivé(s)`
        + (report.conflicts.length ? ` · comptes locaux ignorés : ${report.conflicts.join(', ')}` : ''),
      color: 'success',
      duration: 8000,
    })
    if (!dryRun) await refresh()
  }
  catch (error) {
    toast.add({ title: 'Synchronisation impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    syncing.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="users">
    <template #header>
      <UDashboardNavbar title="Utilisateurs">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-flask-conical" label="Simuler la synchro" color="neutral" variant="ghost" :loading="syncing" @click="syncLdap(true)" />
          <UButton icon="i-lucide-refresh-cw" label="Synchroniser LDAP" color="neutral" variant="outline" :loading="syncing" @click="syncLdap(false)" />
          <UButton icon="i-lucide-user-plus" label="Utilisateur local" @click="createOpen = true" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher…" class="max-w-sm" />
      <UTable :data="filtered" :columns="columns" :loading="status === 'pending'" empty="Aucun utilisateur." />

      <UModal v-model:open="createOpen" title="Nouvel utilisateur local">
        <template #body>
          <form id="create-user" class="flex flex-col gap-3" @submit.prevent="createUser">
            <UFormField label="Email" required>
              <UInput v-model="newUser.email" type="email" class="w-full" />
            </UFormField>
            <div class="grid grid-cols-2 gap-3">
              <UFormField label="Prénom">
                <UInput v-model="newUser.firstName" class="w-full" />
              </UFormField>
              <UFormField label="Nom">
                <UInput v-model="newUser.lastName" class="w-full" />
              </UFormField>
            </div>
            <UFormField label="Mot de passe" hint="12 caractères minimum" required>
              <UInput v-model="newUser.plainPassword" type="password" autocomplete="new-password" class="w-full" />
            </UFormField>
            <USwitch v-model="newUser.admin" label="Administrateur" />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="createOpen = false" />
            <UButton type="submit" form="create-user" label="Créer" />
          </div>
        </template>
      </UModal>

      <UModal
        :open="groupsFor !== null"
        :title="`Groupes de ${groupsFor?.displayName}`"
        description="Transmis aux applications qui demandent le scope « groups » (revendication groups), par exemple pour donner le rôle administrateur."
        @update:open="(value: boolean) => { if (!value) groupsFor = null }"
      >
        <template #body>
          <UInputTags v-model="groups" add-on-blur add-on-paste placeholder="rocket-admins" class="w-full" data-testid="groups-input" />
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="groupsFor = null" />
            <UButton label="Enregistrer" @click="saveGroups" />
          </div>
        </template>
      </UModal>

      <UModal
        :open="passwordFor !== null"
        :title="`Nouveau mot de passe pour ${passwordFor?.email}`"
        @update:open="(value: boolean) => { if (!value) passwordFor = null }"
      >
        <template #body>
          <UFormField label="Mot de passe" hint="12 caractères minimum">
            <UInput v-model="newPassword" type="password" autocomplete="new-password" class="w-full" />
          </UFormField>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Annuler" color="neutral" variant="ghost" @click="passwordFor = null" />
            <UButton label="Enregistrer" :disabled="newPassword.length < 12" @click="changePassword" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
